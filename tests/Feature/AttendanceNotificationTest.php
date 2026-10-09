<?php

namespace Tests\Feature;

use App\Contracts\WhatsAppMessageSender;
use App\Exceptions\WhatsAppApiException;
use App\Jobs\SendAttendanceNotification;
use App\Models\Attendance;
use App\Models\AttendanceNotification;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\WhatsAppCloudApiMessageSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
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
    public function test_records_one_pending_notification_for_a_notifiable_attendance_status(string $status): void
    {
        Queue::fake([SendAttendanceNotification::class]);
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
            'status' => 'pending',
            'attempts' => 0,
        ]);
        Queue::assertPushed(SendAttendanceNotification::class, fn (SendAttendanceNotification $job): bool => $job->notificationId === $attendance->notifications()->value('id'));
    }

    public function test_does_not_create_a_notification_for_hadir_attendance(): void
    {
        Queue::fake([SendAttendanceNotification::class]);
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

    public function test_attendance_status_updates_create_only_one_notification_per_target_status(): void
    {
        Queue::fake([SendAttendanceNotification::class]);
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
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('attendance_notifications', [
            'attendance_id' => $attendance->id,
            'trigger_status' => 'izin',
        ]);
        Queue::assertPushed(SendAttendanceNotification::class, 2);
    }

    public function test_queued_notification_is_cancelled_if_attendance_status_changes_before_processing(): void
    {
        Queue::fake([SendAttendanceNotification::class]);
        $admin = User::factory()->state(['role' => 'admin'])->create();
        $student = Student::factory()->create([
            'parent_whatsapp_phone' => '+6281234567890',
        ]);
        $attendance = Attendance::factory()->create([
            'student_id' => $student->id,
            'recorded_by' => $admin->id,
            'status' => 'hadir',
        ]);
        $this->actingAs($admin)
            ->putJson('/api/attendance/'.$attendance->id, ['status' => 'alpa'])
            ->assertOk();
        $notification = AttendanceNotification::firstOrFail();
        $this->putJson('/api/attendance/'.$attendance->id, ['status' => 'hadir'])
            ->assertOk();
        $sender = Mockery::mock(WhatsAppMessageSender::class);
        $sender->shouldNotReceive('send');

        (new SendAttendanceNotification($notification->id))->handle($sender);

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

    public function test_notification_is_marked_sent_only_after_sender_returns_a_provider_message_id(): void
    {
        $notification = $this->createPendingNotification('alpa');
        $sender = Mockery::mock(WhatsAppMessageSender::class);
        $sender->shouldReceive('send')->once()->andReturn('wamid.test-confirmed');

        (new SendAttendanceNotification($notification->id))->handle($sender);

        $this->assertDatabaseHas('attendance_notifications', [
            'id' => $notification->id,
            'status' => 'sent',
            'provider_message_id' => 'wamid.test-confirmed',
            'attempts' => 1,
        ]);
        $this->assertNotNull($notification->fresh()->sent_at);
    }

    public function test_notification_is_not_marked_sent_when_whatsapp_is_not_configured(): void
    {
        Http::preventStrayRequests();
        config([
            'services.whatsapp.access_token' => null,
            'services.whatsapp.phone_number_id' => null,
            'services.whatsapp.api_version' => null,
            'services.whatsapp.template_name' => null,
            'services.whatsapp.template_language' => 'id',
        ]);
        $notification = $this->createPendingNotification('izin');

        (new SendAttendanceNotification($notification->id))
            ->handle($this->app->make(WhatsAppMessageSender::class));

        $this->assertDatabaseHas('attendance_notifications', [
            'id' => $notification->id,
            'status' => 'not_configured',
            'provider_message_id' => null,
            'attempts' => 1,
        ]);
    }

    public function test_cloud_api_sends_approved_template_and_returns_provider_message_id(): void
    {
        $notification = $this->createPendingNotification('alpa');
        config([
            'services.whatsapp.access_token' => 'test-access-token',
            'services.whatsapp.phone_number_id' => 'test-phone-number-id',
            'services.whatsapp.api_version' => 'v22.0',
            'services.whatsapp.template_name' => 'attendance_alert',
            'services.whatsapp.template_language' => 'id',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/v22.0/test-phone-number-id/messages' => Http::response([
                'messages' => [['id' => 'wamid.cloud-api-confirmed']],
            ], 200),
        ]);

        (new SendAttendanceNotification($notification->id))
            ->handle(app(WhatsAppCloudApiMessageSender::class));

        $this->assertDatabaseHas('attendance_notifications', [
            'id' => $notification->id,
            'status' => 'sent',
            'provider_message_id' => 'wamid.cloud-api-confirmed',
        ]);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://graph.facebook.com/v22.0/test-phone-number-id/messages'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer test-access-token')
            && $request['messaging_product'] === 'whatsapp'
            && $request['to'] === '6281234567890'
            && $request['template']['name'] === 'attendance_alert'
            && $request['template']['components'][0]['parameters'][0]['text'] === $notification->attendance->student->name
            && $request['template']['components'][0]['parameters'][1]['text'] === 'alpa'
            && $request['template']['components'][0]['parameters'][2]['text'] === '2026-10-08'
            && $request['biz_opaque_callback_data'] === (string) $notification->id);
    }

    public function test_cloud_api_permanent_rejection_is_recorded_without_retrying(): void
    {
        $notification = $this->createPendingNotification('izin');
        config([
            'services.whatsapp.access_token' => 'test-access-token',
            'services.whatsapp.phone_number_id' => 'test-phone-number-id',
            'services.whatsapp.api_version' => 'v22.0',
            'services.whatsapp.template_name' => 'attendance_alert',
            'services.whatsapp.template_language' => 'id',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/v22.0/test-phone-number-id/messages' => Http::response([
                'error' => [
                    'message' => 'Template is not approved.',
                    'code' => 132001,
                ],
            ], 400),
        ]);

        (new SendAttendanceNotification($notification->id))
            ->handle(app(WhatsAppCloudApiMessageSender::class));

        $this->assertDatabaseHas('attendance_notifications', [
            'id' => $notification->id,
            'status' => 'failed',
            'provider_message_id' => null,
            'last_error' => 'WhatsApp Cloud API returned HTTP 400 (provider code 132001): Template is not approved.',
        ]);
        Http::assertSentCount(1);
    }

    public function test_cloud_api_server_error_is_marked_retryable(): void
    {
        $notification = $this->createPendingNotification('sakit');
        config([
            'services.whatsapp.access_token' => 'test-access-token',
            'services.whatsapp.phone_number_id' => 'test-phone-number-id',
            'services.whatsapp.api_version' => 'v22.0',
            'services.whatsapp.template_name' => 'attendance_alert',
            'services.whatsapp.template_language' => 'id',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/v22.0/test-phone-number-id/messages' => Http::response([], 503),
        ]);

        try {
            app(WhatsAppCloudApiMessageSender::class)->send($notification);
            $this->fail('Expected the Cloud API server error to be retryable.');
        } catch (WhatsAppApiException $exception) {
            $this->assertSame(true, $exception->retryable);
            $this->assertSame('WhatsApp Cloud API returned HTTP 503: No error details returned.', $exception->getMessage());
        }
    }

    public function test_cloud_api_success_without_message_id_does_not_mark_notification_sent(): void
    {
        $notification = $this->createPendingNotification('sakit');
        config([
            'services.whatsapp.access_token' => 'test-access-token',
            'services.whatsapp.phone_number_id' => 'test-phone-number-id',
            'services.whatsapp.api_version' => 'v22.0',
            'services.whatsapp.template_name' => 'attendance_alert',
            'services.whatsapp.template_language' => 'id',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/v22.0/test-phone-number-id/messages' => Http::response([
                'messages' => [],
            ], 200),
        ]);

        try {
            app(WhatsAppCloudApiMessageSender::class)->send($notification);
            $this->fail('Expected a missing provider message ID to be rejected.');
        } catch (WhatsAppApiException $exception) {
            $this->assertSame(true, $exception->retryable);
        }

        $this->assertDatabaseHas('attendance_notifications', [
            'id' => $notification->id,
            'status' => 'pending',
            'provider_message_id' => null,
        ]);
    }

    public function test_empty_provider_confirmation_does_not_mark_notification_sent(): void
    {
        $notification = $this->createPendingNotification('alpa');
        $sender = Mockery::mock(WhatsAppMessageSender::class);
        $sender->shouldReceive('send')->once()->andReturn('');
        $job = new SendAttendanceNotification($notification->id);
        $exception = null;
        try {
            $job->handle($sender);
        } catch (RuntimeException $caught) {
            $exception = $caught;
        }
        $job->failed($exception);

        $this->assertDatabaseHas('attendance_notifications', [
            'id' => $notification->id,
            'status' => 'failed',
            'provider_message_id' => null,
        ]);
    }

    public function test_exhausted_sender_failures_are_saved_as_failed(): void
    {
        $notification = $this->createPendingNotification('sakit');
        $sender = Mockery::mock(WhatsAppMessageSender::class);
        $sender->shouldReceive('send')
            ->times(3)
            ->andThrow(new RuntimeException('Provider timeout.'));
        $job = new SendAttendanceNotification($notification->id);
        $lastException = null;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $job->handle($sender);
            } catch (RuntimeException $exception) {
                $lastException = $exception;
            }
        }
        $job->failed($lastException);

        $this->assertDatabaseHas('attendance_notifications', [
            'id' => $notification->id,
            'status' => 'failed',
            'last_error' => 'Provider timeout.',
            'attempts' => 3,
        ]);
        $this->assertDatabaseHas('attendance', [
            'id' => $notification->attendance_id,
            'status' => 'sakit',
        ]);
    }

    private function createPendingNotification(string $status): AttendanceNotification
    {
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

        return AttendanceNotification::create([
            'attendance_id' => $attendance->id,
            'trigger_status' => $status,
            'recipient_phone' => '+6281234567890',
            'status' => 'pending',
        ]);
    }
}
