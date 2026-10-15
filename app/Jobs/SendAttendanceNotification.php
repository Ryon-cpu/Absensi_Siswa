<?php

namespace App\Jobs;

use App\Models\AttendanceNotification;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Safely finalizes jobs queued before WhatsApp delivery was disabled.
 */
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
    public function handle(): void
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

        if ($notification->recipient_phone === null) {
            $notification->update([
                'status' => 'failed',
                'last_error' => 'Nomor WhatsApp orang tua/wali belum tersedia.',
            ]);

            return;
        }

        $notification->update([
            'status' => 'not_configured',
            'last_error' => 'Pengiriman WhatsApp Cloud API dinonaktifkan.',
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        AttendanceNotification::whereKey($this->notificationId)
            ->where('status', 'pending')
            ->update([
                'status' => 'not_configured',
                'last_error' => $exception?->getMessage() ?? 'Pengiriman WhatsApp Cloud API dinonaktifkan.',
            ]);

        Log::error('Legacy WhatsApp notification job failed after all attempts.', [
            'attendance_notification_id' => $this->notificationId,
            'error' => $exception?->getMessage(),
        ]);
    }

    public function uniqueId(): string
    {
        return (string) $this->notificationId;
    }
}
