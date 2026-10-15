<?php

namespace Tests\Feature;

use App\Jobs\SendAttendanceNotification;
use App\Models\Attendance;
use App\Models\AttendanceNotification;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AttendanceNotificationTest extends TestCase
{
    use RefreshDatabase;

    public static function notifiableStatuses(): array
    {
        return [
            'alpa' => ['alpa'],
            'izin' => ['izin'],
            'sakit' => ['sakit'],
        ];
    }

    #[DataProvider('notifiableStatuses')]
    public function test_records_notification_without_sending_for_a_notifiable_attendance_status(string $status): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $admin = User::factory()->state(['role' => 'admin'])->create();
        $student = Student::factory()->create([
            'parent_whatsapp_phone' => '+6281234567890',
        ]);

        $this->actingAs($admin)
            ->postJson('/api/attendance', [
                'student_id' => $student->id,
                'date' => '2026-10-08',
                'status' => $status,
            ])
            ->assertCreated();

        $attendance = Attendance::firstOrFail();
        $this->assertDatabaseHas('attendance_notifications', [
            'attendance_id' => $attendance->id,
            'trigger_status' => $status,
            'recipient_phone' => '+6281234567890',
            'status' => 'not_configured',
            'attempts' => 0,
            'last_error' => 'Pengiriman WhatsApp Cloud API dinonaktifkan.',
        ]);
        Queue::assertNothingPushed();
    }

    public function test_does_not_create_a_notification_for_hadir_attendance(): void
    {
        Queue::fake();
        $admin = User::factory()->state(['role' => 'admin'])->create();
        $student = Student::factory()->create([
            'parent_whatsapp_phone' => '+6281234567890',
        ]);

        $this->actingAs($admin)
            ->postJson('/api/attendance', [
                'student_id' => $student->id,
                'date' => '2026-10-08',
                'status' => 'hadir',
            ])
            ->assertCreated();

        $this->assertDatabaseCount('attendance_notifications', 0);
        Queue::assertNothingPushed();
    }

    public function test_records_missing_parent_phone_without_failing_attendance_creation(): void
    {
        $admin = User::factory()->state(['role' => 'admin'])->create();
        $student = Student::factory()->create();

        $this->actingAs($admin)
            ->postJson('/api/attendance', [
                'student_id' => $student->id,
                'date' => '2026-10-08',
                'status' => 'alpa',
            ])
            ->assertCreated();

        $attendance = Attendance::firstOrFail();
        $this->assertDatabaseHas('attendance', [
            'id' => $attendance->id,
            'status' => 'alpa',
        ]);
        $this->assertDatabaseHas('attendance_notifications', [
            'attendance_id' => $attendance->id,
            'status' => 'failed',
            'recipient_phone' => null,
            'last_error' => 'Nomor WhatsApp orang tua/wali belum tersedia.',
        ]);
    }

    public function test_attendance_status_updates_create_one_record_per_target_status(): void
    {
        Queue::fake();
        $admin = User::factory()->state(['role' => 'admin'])->create();
        $student = Student::factory()->create([
            'parent_whatsapp_phone' => '+6281234567890',
        ]);
        $attendance = Attendance::factory()->create([
            'student_id' => $student->id,
            'recorded_by' => $admin->id,
            'status' => 'hadir',
        ]);

        $this->actingAs($admin);
        $this->putJson('/api/attendance/'.$attendance->id, ['status' => 'sakit'])->assertOk();
        $this->putJson('/api/attendance/'.$attendance->id, ['status' => 'sakit'])->assertOk();
        $this->putJson('/api/attendance/'.$attendance->id, ['status' => 'izin'])->assertOk();
        $this->putJson('/api/attendance/'.$attendance->id, ['status' => 'sakit'])->assertOk();

        $this->assertDatabaseCount('attendance_notifications', 2);
        $this->assertDatabaseHas('attendance_notifications', [
            'attendance_id' => $attendance->id,
            'trigger_status' => 'sakit',
            'status' => 'not_configured',
        ]);
        $this->assertDatabaseHas('attendance_notifications', [
            'attendance_id' => $attendance->id,
            'trigger_status' => 'izin',
            'status' => 'not_configured',
        ]);
        Queue::assertNothingPushed();
    }

    public function test_legacy_queued_notification_is_cancelled_if_attendance_status_changes(): void
    {
        $admin = User::factory()->state(['role' => 'admin'])->create();
        $student = Student::factory()->create([
            'parent_whatsapp_phone' => '+6281234567890',
        ]);
        $attendance = Attendance::factory()->create([
            'student_id' => $student->id,
            'recorded_by' => $admin->id,
            'status' => 'alpa',
        ]);
        $notification = $this->createPendingNotification('alpa', $attendance);

        $this->actingAs($admin)
            ->putJson('/api/attendance/'.$attendance->id, ['status' => 'hadir'])
            ->assertOk();

        (new SendAttendanceNotification($notification->id))->handle();

        $this->assertDatabaseHas('attendance_notifications', [
            'id' => $notification->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_student_api_saves_only_valid_e164_parent_phone_numbers(): void
    {
        $admin = User::factory()->state(['role' => 'admin'])->create();
        $schoolClass = SchoolClass::factory()->create();

        $response = $this->actingAs($admin)->postJson('/api/students', [
            'class_id' => $schoolClass->id,
            'student_number' => 'S-PHONE-001',
            'name' => 'Siswa Telepon',
            'parent_whatsapp_phone' => '+6281234567890',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.parent_whatsapp_phone', '+6281234567890');
        $student = Student::where('student_number', 'S-PHONE-001')->firstOrFail();
        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'parent_whatsapp_phone' => '+6281234567890',
        ]);

        $this->putJson('/api/students/'.$student->id, [
            'class_id' => $schoolClass->id,
            'student_number' => 'S-PHONE-001',
            'name' => 'Siswa Telepon',
            'parent_whatsapp_phone' => '081234567890',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_whatsapp_phone');

        $this->assertSame('+6281234567890', $student->fresh()->parent_whatsapp_phone);
    }

    public function test_legacy_pending_job_is_not_sent_and_marks_notification_not_configured(): void
    {
        Http::preventStrayRequests();
        $notification = $this->createPendingNotification('alpa');

        (new SendAttendanceNotification($notification->id))->handle();

        $this->assertDatabaseHas('attendance_notifications', [
            'id' => $notification->id,
            'status' => 'not_configured',
            'provider_message_id' => null,
            'attempts' => 0,
            'last_error' => 'Pengiriman WhatsApp Cloud API dinonaktifkan.',
        ]);
    }

    private function createPendingNotification(string $status, ?Attendance $attendance = null): AttendanceNotification
    {
        if ($attendance === null) {
            $admin = User::factory()->state(['role' => 'admin'])->create();
            $student = Student::factory()->create([
                'parent_whatsapp_phone' => '+6281234567890',
            ]);
            $attendance = Attendance::factory()->create([
                'student_id' => $student->id,
                'recorded_by' => $admin->id,
                'date' => '2026-10-08',
                'status' => $status,
            ]);
        }

        return AttendanceNotification::create([
            'attendance_id' => $attendance->id,
            'trigger_status' => $status,
            'recipient_phone' => '+6281234567890',
            'status' => 'pending',
        ]);
    }
}
