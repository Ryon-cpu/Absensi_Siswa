<?php

namespace App\Jobs;

use App\Contracts\WhatsAppMessageSender;
use App\Exceptions\WhatsAppApiException;
use App\Exceptions\WhatsAppNotConfiguredException;
use App\Models\AttendanceNotification;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SendAttendanceNotification implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30];

    public int $uniqueFor = 3600;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $notificationId) {}

    /**
     * Execute the job.
     */
    public function handle(WhatsAppMessageSender $sender): void
    {
        $notification = AttendanceNotification::findOrFail($this->notificationId);
        if ($notification->status !== 'pending') {
            return;
        }

        $attendance = $notification->attendance;
        if ($attendance === null || $attendance->status !== $notification->trigger_status) {
            $notification->update([
                'status' => 'cancelled',
                'last_error' => 'Status absensi berubah sebelum notifikasi diproses.',
            ]);

            return;
        }

        $notification->increment('attempts');
        if ($notification->recipient_phone === null) {
            $notification->update([
                'status' => 'failed',
                'last_error' => 'Nomor WhatsApp orang tua/wali belum tersedia.',
            ]);

            return;
        }
        try {
            $providerMessageId = $sender->send($notification);
        } catch (WhatsAppApiException $exception) {
            if ($exception->retryable) {
                throw $exception;
            }

            $notification->update([
                'status' => 'failed',
                'last_error' => $exception->getMessage(),
            ]);

            Log::error('WhatsApp notification was rejected by the provider.', [
                'attendance_notification_id' => $notification->id,
                'error' => $exception->getMessage(),
            ]);

            return;
        } catch (WhatsAppNotConfiguredException $exception) {
            $notification->update([
                'status' => 'not_configured',
                'last_error' => $exception->getMessage(),
            ]);

            Log::warning('WhatsApp notification was not sent because configuration is unavailable.', [
                'attendance_notification_id' => $notification->id,
                'error' => $exception->getMessage(),
            ]);

            return;
        }
        if (trim($providerMessageId) === '') {
            throw new RuntimeException('WhatsApp provider did not confirm the message.');
        }
        $notification->update([
            'status' => 'sent',
            'provider_message_id' => $providerMessageId,
            'sent_at' => now(),
            'last_error' => null,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        AttendanceNotification::whereKey($this->notificationId)
            ->where('status', 'pending')
            ->update([
                'status' => 'failed',
                'last_error' => $exception?->getMessage() ?? 'WhatsApp delivery failed.',
            ]);

        Log::error('WhatsApp notification delivery failed after all attempts.', [
            'attendance_notification_id' => $this->notificationId,
            'error' => $exception?->getMessage(),
        ]);
    }

    public function uniqueId(): string
    {
        return (string) $this->notificationId;
    }
}
