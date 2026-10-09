<?php

namespace App\Services;

use App\Jobs\SendAttendanceNotification;
use App\Models\Attendance;
use App\Models\AttendanceNotification;
use Illuminate\Support\Facades\Log;
use Throwable;

class AttendanceNotificationService
{
    private const NOTIFIABLE_STATUSES = ['alpa', 'izin', 'sakit'];

    public function queueFor(Attendance $attendance): void
    {
        AttendanceNotification::query()
            ->where('attendance_id', $attendance->id)
            ->where('status', 'pending')
            ->where('trigger_status', '!=', $attendance->status)
            ->update([
                'status' => 'cancelled',
                'last_error' => 'Status absensi berubah sebelum notifikasi diproses.',
            ]);

        if (! in_array($attendance->status, self::NOTIFIABLE_STATUSES, true)) {
            return;
        }

        $recipientPhone = $attendance->student()->value('parent_whatsapp_phone');
        $hasRecipient = is_string($recipientPhone) && $recipientPhone !== '';

        $notification = AttendanceNotification::firstOrCreate(
            [
                'attendance_id' => $attendance->id,
                'trigger_status' => $attendance->status,
            ],
            [
                'recipient_phone' => $recipientPhone,
                'status' => $hasRecipient ? 'pending' : 'failed',
                'last_error' => $hasRecipient
                    ? null
                    : 'Nomor WhatsApp orang tua/wali belum tersedia.',
            ],
        );

        if (! $hasRecipient) {
            return;
        }

        if (! $notification->wasRecentlyCreated) {
            if ($notification->status !== 'cancelled') {
                return;
            }

            $notification->update([
                'recipient_phone' => $recipientPhone,
                'status' => 'pending',
                'last_error' => null,
            ]);
        }

        try {
            SendAttendanceNotification::dispatch($notification->id)->afterCommit();
        } catch (Throwable $exception) {
            $notification->update([
                'status' => 'failed',
                'last_error' => $exception->getMessage(),
            ]);

            Log::error('Failed to queue attendance WhatsApp notification.', [
                'attendance_notification_id' => $notification->id,
                'exception' => $exception,
            ]);
        }
    }
}
