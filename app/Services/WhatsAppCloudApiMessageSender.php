<?php

namespace App\Services;

use App\Contracts\WhatsAppMessageSender;
use App\Exceptions\WhatsAppApiException;
use App\Exceptions\WhatsAppNotConfiguredException;
use App\Models\AttendanceNotification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WhatsAppCloudApiMessageSender implements WhatsAppMessageSender
{
    public function send(AttendanceNotification $notification): string
    {
        $accessToken = config('services.whatsapp.access_token');
        $phoneNumberId = config('services.whatsapp.phone_number_id');
        $apiVersion = config('services.whatsapp.api_version');
        $templateName = config('services.whatsapp.template_name');
        $templateLanguage = config('services.whatsapp.template_language');

        if (! is_string($accessToken) || trim($accessToken) === ''
            || ! is_string($phoneNumberId) || trim($phoneNumberId) === ''
            || ! is_string($apiVersion) || preg_match('/^v\d+\.\d+$/', $apiVersion) !== 1
            || ! is_string($templateName) || trim($templateName) === ''
            || ! is_string($templateLanguage) || trim($templateLanguage) === '') {
            throw new WhatsAppNotConfiguredException(
                'Konfigurasi WhatsApp Cloud API belum lengkap atau tidak valid.',
            );
        }

        $attendance = $notification->attendance;
        $student = $attendance?->student;
        if ($attendance === null || $student === null) {
            throw new RuntimeException('Attendance or student record is unavailable for WhatsApp notification.');
        }

        $url = sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            $apiVersion,
            rawurlencode($phoneNumberId),
        );

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->connectTimeout(3)
                ->timeout(10)
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => ltrim($notification->recipient_phone, '+'),
                    'type' => 'template',
                    'template' => [
                        'name' => $templateName,
                        'language' => ['code' => $templateLanguage],
                        'components' => [
                            [
                                'type' => 'body',
                                'parameters' => [
                                    ['type' => 'text', 'text' => $student->name],
                                    ['type' => 'text', 'text' => $notification->trigger_status],
                                    ['type' => 'text', 'text' => $attendance->date->format('Y-m-d')],
                                ],
                            ],
                        ],
                    ],
                    'biz_opaque_callback_data' => (string) $notification->id,
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('WhatsApp Cloud API connection failed: '.$exception->getMessage(), previous: $exception);
        }

        if (! $response->successful()) {
            $retryable = $response->status() === 429 || $response->serverError();
            $error = $response->json('error');
            $description = is_array($error) && is_string($error['message'] ?? null)
                ? mb_substr($error['message'], 0, 500)
                : 'No error details returned.';
            $code = is_array($error) && is_int($error['code'] ?? null)
                ? sprintf(' (provider code %d)', $error['code'])
                : '';

            throw new WhatsAppApiException(
                sprintf('WhatsApp Cloud API returned HTTP %d%s: %s', $response->status(), $code, $description),
                $retryable,
            );
        }

        $messageId = $response->json('messages.0.id');
        if (! is_string($messageId) || trim($messageId) === '') {
            throw new WhatsAppApiException(
                'WhatsApp Cloud API returned success without a confirmed message ID.',
                true,
            );
        }

        return $messageId;
    }
}
