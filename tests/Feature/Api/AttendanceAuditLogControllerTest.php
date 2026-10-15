<?php

namespace Tests\Feature\Api;

use App\Models\ClassTeacher;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceAuditLogControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_attendance_creation_changes_and_deletion_are_kept_in_audit_history(): void
    {
        $teacher = Teacher::factory()->create();
        $schoolClass = SchoolClass::factory()->create(['name' => 'Kelas Audit']);
        ClassTeacher::factory()->for($schoolClass, 'schoolClass')->for($teacher)->create();
        $student = Student::factory()->for($schoolClass, 'schoolClass')->create([
            'student_number' => 'AUD-001',
            'name' => 'Siswa Audit',
            'parent_whatsapp_phone' => '+6281234567890',
        ]);
        $this->actingAs($teacher->user);

        $created = $this->postJson('/api/attendance', [
            'student_id' => $student->id,
            'date' => '2026-10-08',
            'status' => 'hadir',
        ])->assertCreated();
        $attendanceId = $created->json('data.id');

        $this->putJson('/api/attendance/'.$attendanceId, ['status' => 'alpa'])
            ->assertOk()
            ->assertJsonPath('data.status', 'alpa');
        $this->putJson('/api/attendance/'.$attendanceId, ['status' => 'alpa'])->assertOk();
        $this->deleteJson('/api/attendance/'.$attendanceId)->assertOk();

        $this->assertDatabaseMissing('attendance', ['id' => $attendanceId]);
        $this->assertDatabaseCount('attendance_audit_logs', 3);
        $this->assertDatabaseHas('attendance_audit_logs', [
            'attendance_id' => $attendanceId,
            'student_id' => $student->id,
            'class_id' => $schoolClass->id,
            'student_number' => 'AUD-001',
            'student_name' => 'Siswa Audit',
            'class_name' => 'Kelas Audit',
            'actor_id' => $teacher->user->id,
            'actor_name' => $teacher->user->name,
            'action' => 'created',
            'previous_status' => null,
            'new_status' => 'hadir',
            'attendance_date' => '2026-10-08',
        ]);
        $this->assertDatabaseHas('attendance_audit_logs', [
            'attendance_id' => $attendanceId,
            'action' => 'updated',
            'previous_status' => 'hadir',
            'new_status' => 'alpa',
        ]);
        $this->assertDatabaseHas('attendance_audit_logs', [
            'attendance_id' => $attendanceId,
            'action' => 'deleted',
            'previous_status' => 'alpa',
            'new_status' => null,
        ]);
        $this->getJson('/api/attendance-audit-logs')
            ->assertOk()
            ->assertJsonCount(3, 'data.data')
            ->assertJsonPath('data.data.0.action', 'deleted');
    }

    public function test_attendance_audit_log_endpoint_is_only_available_to_assigned_teachers(): void
    {
        $this->getJson('/api/attendance-audit-logs')->assertUnauthorized();

        $admin = User::factory()->state(['role' => 'admin'])->create();
        $this->actingAs($admin)->getJson('/api/attendance-audit-logs')->assertForbidden();

        $student = User::factory()->state(['role' => 'siswa'])->create();
        $this->actingAs($student)
            ->getJson('/api/attendance-audit-logs')
            ->assertForbidden();
    }

    public function test_teacher_can_filter_only_audit_logs_for_assigned_classes(): void
    {
        $this->travelTo('2026-10-12 10:00:00');
        $teacher = Teacher::factory()->create();
        $schoolClass = SchoolClass::factory()->create();
        $otherClass = SchoolClass::factory()->create();
        ClassTeacher::factory()->for($schoolClass, 'schoolClass')->for($teacher)->create();
        $otherTeacher = Teacher::factory()->create();
        ClassTeacher::factory()->for($otherClass, 'schoolClass')->for($otherTeacher)->create();
        $student = Student::factory()->for($schoolClass, 'schoolClass')->create();
        $otherStudent = Student::factory()->for($otherClass, 'schoolClass')->create();
        $this->actingAs($teacher->user);

        $this->postJson('/api/attendance', [
            'student_id' => $student->id,
            'date' => '2026-10-08',
            'status' => 'hadir',
        ])->assertCreated();
        $this->actingAs($otherTeacher->user)
            ->postJson('/api/attendance', [
                'student_id' => $otherStudent->id,
                'date' => '2026-10-08',
                'status' => 'hadir',
            ])->assertCreated();
        $this->actingAs($teacher->user);

        $this->getJson('/api/attendance-audit-logs?from=2026-10-12&to=2026-10-12&class_id='.$schoolClass->id.'&action=created')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.student_id', $student->id)
            ->assertJsonPath('data.data.0.action', 'created');
        $this->getJson('/api/attendance-audit-logs?class_id='.$otherClass->id)
            ->assertOk()
            ->assertJsonCount(0, 'data.data');
        $this->getJson('/api/attendance-audit-logs?from=2026-10-13&to=2026-10-12')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');
    }
}
