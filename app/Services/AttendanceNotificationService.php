<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendanceNotification;

class AttendanceNotificationService
{
    private const NOTIFIABLE_STATUSES = ['alpa', 'izin', 'sakit'];

    private const DISABLED_MESSAGE = 'Pengiriman WhatsApp Cloud API dinonaktifkan.';

    public function recordFor(Attendance $attendance): void
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
                'status' => $hasRecipient ? 'not_configured' : 'failed',
                'last_error' => $hasRecipient
                    ? self::DISABLED_MESSAGE
                    : 'Nomor WhatsApp orang tua/wali belum tersedia.',
            ],
        );

        if ($notification->wasRecentlyCreated) {
            return;
        }

        if (in_array($notification->status, ['cancelled', 'pending'], true)) {
            $notification->update([
                'recipient_phone' => $recipientPhone,
                'status' => $hasRecipient ? 'not_configured' : 'failed',
                'last_error' => $hasRecipient
                    ? self::DISABLED_MESSAGE
                    : 'Nomor WhatsApp orang tua/wali belum tersedia.',
            ]);
        }
    }
}
