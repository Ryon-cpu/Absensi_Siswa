<?php
namespace Tests\Feature\Api;
use App\Models\Attendance;
use App\Models\ClassTeacher;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class AttendanceApiTest extends TestCase
{
    use RefreshDatabase;
    public function test_admin_can_record_attendance_once_per_student_and_date(): void
    {
        $admin = User::factory()->state(['role' => 'admin'])->create();
        $student = Student::factory()->create();
        $this->actingAs($admin);
        $this->postJson('/api/attendance', [
            'student_id' => $student->id,
            'date' => '2026-10-08',
            'status' => 'hadir',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'hadir')
            ->assertJsonPath('data.date', '2026-10-08')
            ->assertJsonPath('data.recorded_by', $admin->id);
        $this->postJson('/api/attendance', [
            'student_id' => $student->id,
            'date' => '2026-10-08',
            'status' => 'izin',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.student_id.0', 'Absensi siswa untuk tanggal tersebut sudah tercatat.');
        $this->assertDatabaseCount('attendance', 1);
    }
    public function test_teacher_can_only_record_attendance_for_assigned_classes(): void
    {
        $teacher = Teacher::factory()->create();
        $assignedClass = SchoolClass::factory()->create();
        $otherClass = SchoolClass::factory()->create();
        ClassTeacher::factory()
            ->for($assignedClass, 'schoolClass')
            ->for($teacher)
            ->create();
        $assignedStudent = Student::factory()->for($assignedClass, 'schoolClass')->create();
        $otherStudent = Student::factory()->for($otherClass, 'schoolClass')->create();
        $this->actingAs($teacher->user);
        $this->postJson('/api/attendance', [
            'student_id' => $assignedStudent->id,
            'date' => '2026-10-08',
            'status' => 'hadir',
        ])->assertCreated();
        $this->postJson('/api/attendance', [
            'student_id' => $otherStudent->id,
            'date' => '2026-10-08',
            'status' => 'hadir',
        ])->assertForbidden();
        $this->assertDatabaseCount('attendance', 1);
    }
    public function test_student_only_sees_own_attendance_history(): void
    {
        $studentUser = User::factory()->create();
        $student = Student::factory()->create(['user_id' => $studentUser->id]);
        $otherStudent = Student::factory()->create();
        $recorder = User::factory()->state(['role' => 'admin'])->create();
        Attendance::factory()->create([
            'student_id' => $student->id,
            'recorded_by' => $recorder->id,
            'date' => '2026-10-08',
        ]);
        Attendance::factory()->create([
            'student_id' => $otherStudent->id,
            'recorded_by' => $recorder->id,
            'date' => '2026-10-08',
        ]);
        $response = $this->actingAs($studentUser)->getJson('/api/attendance')->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertSame($student->id, $response->json('data.data.0.student_id'));
        $otherAttendance = Attendance::where('student_id', $otherStudent->id)->firstOrFail();
        $this->getJson('/api/attendance/'.$otherAttendance->id)->assertNotFound();
    }
    public function test_admin_can_filter_attendance_and_get_status_totals(): void
    {
        $admin = User::factory()->state(['role' => 'admin'])->create();
        $schoolClass = SchoolClass::factory()->create();
        $student = Student::factory()->for($schoolClass, 'schoolClass')->create();
        Attendance::factory()->create([
            'student_id' => $student->id,
            'recorded_by' => $admin->id,
            'date' => '2026-10-08',
            'status' => 'hadir',
        ]);
        Attendance::factory()->create([
            'student_id' => $student->id,
            'recorded_by' => $admin->id,
            'date' => '2026-10-07',
            'status' => 'izin',
        ]);
        $this->actingAs($admin)
            ->getJson('/api/attendance?date=2026-10-08&class_id='.$schoolClass->id.'&status=hadir')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.status', 'hadir');
        $this->getJson('/api/reports/attendance?from=2026-10-07&to=2026-10-08&class_id='.$schoolClass->id)
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.hadir', 1)
            ->assertJsonPath('data.izin', 1)
            ->assertJsonPath('data.sakit', 0)
            ->assertJsonPath('data.alpa', 0);
    }
    public function test_returns_422_when_attendance_status_is_invalid(): void
    {
        $admin = User::factory()->state(['role' => 'admin'])->create();
        $student = Student::factory()->create();
        $this->actingAs($admin)
            ->postJson('/api/attendance', [
                'student_id' => $student->id,
                'date' => '2026-10-08',
                'status' => 'late',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'The selected status is invalid.');
        $this->assertDatabaseCount('attendance', 0);
    }
    public function test_returns_403_when_student_creates_or_changes_attendance(): void
    {
        $studentUser = User::factory()->create();
        $student = Student::factory()->create(['user_id' => $studentUser->id]);
        $recorder = User::factory()->state(['role' => 'admin'])->create();
        $attendance = Attendance::factory()->create([
            'student_id' => $student->id,
            'recorded_by' => $recorder->id,
            'date' => '2026-10-08',
            'status' => 'hadir',
        ]);
        $this->actingAs($studentUser)
            ->postJson('/api/attendance', [
                'student_id' => $student->id,
                'date' => '2026-10-09',
                'status' => 'hadir',
            ])
            ->assertForbidden();
        $this->putJson('/api/attendance/'.$attendance->id, ['status' => 'izin'])
            ->assertForbidden();
        $this->assertSame('hadir', $attendance->fresh()->status);
        $this->assertDatabaseCount('attendance', 1);
    }
    public function test_admin_can_update_and_delete_attendance(): void
    {
        $admin = User::factory()->state(['role' => 'admin'])->create();
        $student = Student::factory()->create();
        $attendance = Attendance::factory()->create([
            'student_id' => $student->id,
            'recorded_by' => $admin->id,
            'status' => 'hadir',
        ]);
        $this->actingAs($admin)
            ->putJson('/api/attendance/'.$attendance->id, ['status' => 'sakit'])
            ->assertOk()
            ->assertJsonPath('data.status', 'sakit');
        $this->assertSame('sakit', $attendance->fresh()->status);
        $this->deleteJson('/api/attendance/'.$attendance->id)->assertOk();
        $this->assertModelMissing($attendance);
    }
    public function test_returns_404_when_requested_attendance_does_not_exist(): void
    {
        $admin = User::factory()->state(['role' => 'admin'])->create();
        $this->actingAs($admin)
            ->getJson('/api/attendance/999999')
            ->assertNotFound();
    }
}
