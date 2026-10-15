<?php

namespace Tests\Feature\Api;

use App\Models\Attendance;
use App\Models\AttendanceSession;
use App\Models\ClassTeacher;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
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

    public function test_teacher_can_view_full_attendance_sheet_for_assigned_class_and_date(): void
    {
        $teacher = Teacher::factory()->create();
        $schoolClass = SchoolClass::factory()->create();
        ClassTeacher::factory()->for($schoolClass, 'schoolClass')->for($teacher)->create();
        $students = Student::factory()
            ->count(2)
            ->for($schoolClass, 'schoolClass')
            ->sequence(
                ['name' => 'Alya Siswa', 'student_number' => 'NIS-001'],
                ['name' => 'Bima Siswa', 'student_number' => 'NIS-002'],
            )
            ->create();
        Attendance::factory()->create([
            'student_id' => $students[0]->id,
            'recorded_by' => $teacher->user->id,
            'date' => '2026-10-12',
            'status' => 'izin',
        ]);

        $this->actingAs($teacher->user)
            ->getJson('/api/attendance/sheet?class_id='.$schoolClass->id.'&date=2026-10-12')
            ->assertOk()
            ->assertJsonPath('data.class.id', $schoolClass->id)
            ->assertJsonPath('data.date', '2026-10-12')
            ->assertJsonCount(2, 'data.students')
            ->assertJsonPath('data.students.0.name', 'Alya Siswa')
            ->assertJsonPath('data.students.0.status', 'izin')
            ->assertJsonPath('data.students.1.name', 'Bima Siswa')
            ->assertJsonPath('data.students.1.status', null);
    }

    public function test_teacher_can_save_class_sheet_and_audit_only_new_or_changed_statuses(): void
    {
        $teacher = Teacher::factory()->create();
        $originalRecorder = User::factory()->state(['role' => 'admin'])->create();
        $schoolClass = SchoolClass::factory()->create();
        ClassTeacher::factory()->for($schoolClass, 'schoolClass')->for($teacher)->create();
        $changedStudent = Student::factory()->for($schoolClass, 'schoolClass')->create([
            'parent_whatsapp_phone' => '+6281234567890',
        ]);
        $newStudent = Student::factory()->for($schoolClass, 'schoolClass')->create();
        $unchangedStudent = Student::factory()->for($schoolClass, 'schoolClass')->create();
        $existingAttendance = Attendance::factory()->create([
            'student_id' => $changedStudent->id,
            'recorded_by' => $originalRecorder->id,
            'date' => '2026-10-12',
            'status' => 'hadir',
        ]);
        $unchangedAttendance = Attendance::factory()->create([
            'student_id' => $unchangedStudent->id,
            'recorded_by' => $originalRecorder->id,
            'date' => '2026-10-12',
            'status' => 'hadir',
        ]);
        $this->actingAs($teacher->user)
            ->postJson('/api/attendance/sheet', [
                'class_id' => $schoolClass->id,
                'date' => '2026-10-12',
                'attendance' => [
                    ['student_id' => $changedStudent->id, 'status' => 'sakit'],
                    ['student_id' => $newStudent->id, 'status' => 'alpa'],
                    ['student_id' => $unchangedStudent->id, 'status' => 'hadir'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.saved', 2);

        $this->assertDatabaseCount('attendance', 3);
        $this->assertDatabaseHas('attendance', [
            'id' => $existingAttendance->id,
            'status' => 'sakit',
            'recorded_by' => $originalRecorder->id,
        ]);
        $this->assertDatabaseHas('attendance', [
            'id' => $unchangedAttendance->id,
            'status' => 'hadir',
            'recorded_by' => $originalRecorder->id,
        ]);
        $this->assertDatabaseHas('attendance', [
            'student_id' => $newStudent->id,
            'recorded_by' => $teacher->user->id,
            'date' => '2026-10-12',
            'status' => 'alpa',
        ]);
        $this->assertDatabaseCount('attendance_audit_logs', 2);
        $this->assertDatabaseHas('attendance_audit_logs', [
            'attendance_id' => $existingAttendance->id,
            'action' => 'updated',
            'previous_status' => 'hadir',
            'new_status' => 'sakit',
        ]);
        $this->assertDatabaseHas('attendance_audit_logs', [
            'student_id' => $newStudent->id,
            'action' => 'created',
            'previous_status' => null,
            'new_status' => 'alpa',
        ]);
    }

    public function test_teacher_can_record_one_student_for_two_subjects_on_the_same_date(): void
    {
        $teacher = Teacher::factory()->create();
        $schoolClass = SchoolClass::factory()->create();
        ClassTeacher::factory()->for($schoolClass, 'schoolClass')->for($teacher)->create();
        $student = Student::factory()->for($schoolClass, 'schoolClass')->create([
            'parent_whatsapp_phone' => '+6281234567890',
        ]);
        $mathematics = Subject::factory()->for($schoolClass, 'schoolClass')->create([
            'name' => 'Matematika',
        ]);
        $science = Subject::factory()->for($schoolClass, 'schoolClass')->create([
            'name' => 'IPA',
        ]);
        $this->actingAs($teacher->user);

        $mathematicsSession = $this->postJson('/api/attendance-sessions', [
            'subject_id' => $mathematics->id,
            'date' => '2026-10-12',
        ])
            ->assertOk()
            ->assertJsonPath('data.subject.name', 'Matematika')
            ->assertJsonPath('data.meeting_number', 1)
            ->json('data.id');
        $this->getJson('/api/attendance/sheet?attendance_session_id='.$mathematicsSession)
            ->assertOk()
            ->assertJsonPath('data.session.subject', 'Matematika')
            ->assertJsonPath('data.students.0.name', $student->name);
        $this->postJson('/api/attendance/sheet', [
            'attendance_session_id' => $mathematicsSession,
            'attendance' => [
                ['student_id' => $student->id, 'status' => 'alpa'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.saved', 1);

        $scienceSession = $this->postJson('/api/attendance-sessions', [
            'subject_id' => $science->id,
            'date' => '2026-10-12',
        ])
            ->assertOk()
            ->assertJsonPath('data.subject.name', 'IPA')
            ->assertJsonPath('data.meeting_number', 1)
            ->json('data.id');
        $this->postJson('/api/attendance/sheet', [
            'attendance_session_id' => $scienceSession,
            'attendance' => [
                ['student_id' => $student->id, 'status' => 'hadir'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.saved', 1);

        $this->assertDatabaseCount('attendance', 2);
        $this->assertDatabaseHas('attendance', [
            'student_id' => $student->id,
            'attendance_session_id' => $mathematicsSession,
            'date' => '2026-10-12',
            'status' => 'alpa',
        ]);
        $this->assertDatabaseHas('attendance', [
            'student_id' => $student->id,
            'attendance_session_id' => $scienceSession,
            'date' => '2026-10-12',
            'status' => 'hadir',
        ]);
        $this->assertDatabaseHas('attendance_audit_logs', [
            'student_id' => $student->id,
            'subject_name' => 'Matematika',
            'meeting_number' => 1,
        ]);
        $this->getJson('/api/attendance?student_id='.$student->id)
            ->assertOk()
            ->assertJsonCount(2, 'data.data')
            ->assertJsonPath('data.data.0.attendance_session.subject.name', 'Matematika')
            ->assertJsonPath('data.data.1.attendance_session.subject.name', 'IPA');
    }

    public function test_opening_a_subject_on_the_same_date_reuses_its_session(): void
    {
        $teacher = Teacher::factory()->create();
        $schoolClass = SchoolClass::factory()->create();
        ClassTeacher::factory()->for($schoolClass, 'schoolClass')->for($teacher)->create();
        $subject = Subject::factory()->for($schoolClass, 'schoolClass')->create();
        $this->actingAs($teacher->user);

        $firstSession = $this->postJson('/api/attendance-sessions', [
            'subject_id' => $subject->id,
            'date' => '2026-10-12',
        ])->assertOk()->json('data.id');
        $reopenedSession = $this->postJson('/api/attendance-sessions', [
            'subject_id' => $subject->id,
            'date' => '2026-10-12',
        ])->assertOk()->json('data.id');
        $nextSession = $this->postJson('/api/attendance-sessions', [
            'subject_id' => $subject->id,
            'date' => '2026-10-13',
        ])
            ->assertOk()
            ->assertJsonPath('data.meeting_number', 2)
            ->json('data.id');

        $this->assertSame($firstSession, $reopenedSession);
        $this->assertDatabaseCount('attendance_sessions', 2);
        $this->assertModelExists(AttendanceSession::findOrFail($nextSession));
    }

    public function test_teacher_cannot_open_an_attendance_session_for_an_unassigned_class(): void
    {
        $teacher = Teacher::factory()->create();
        $schoolClass = SchoolClass::factory()->create();
        $subject = Subject::factory()->for($schoolClass, 'schoolClass')->create();

        $this->actingAs($teacher->user)
            ->postJson('/api/attendance-sessions', [
                'subject_id' => $subject->id,
                'date' => '2026-10-12',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('attendance_sessions', 0);
    }

    public function test_class_sheet_rejects_an_incomplete_roster_without_saving_attendance(): void
    {
        $teacher = Teacher::factory()->create();
        $schoolClass = SchoolClass::factory()->create();
        ClassTeacher::factory()->for($schoolClass, 'schoolClass')->for($teacher)->create();
        Student::factory()->for($schoolClass, 'schoolClass')->create();

        $this->actingAs($teacher->user)
            ->postJson('/api/attendance/sheet', [
                'class_id' => $schoolClass->id,
                'date' => '2026-10-12',
                'attendance' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.attendance.0',
                'Daftar siswa kelas berubah. Muat ulang lembar absensi sebelum menyimpan.',
            );

        $this->assertDatabaseCount('attendance', 0);
        $this->assertDatabaseCount('attendance_audit_logs', 0);
    }

    public function test_teacher_cannot_view_or_save_attendance_sheet_for_unassigned_class(): void
    {
        $teacher = Teacher::factory()->create();
        $schoolClass = SchoolClass::factory()->create();
        $student = Student::factory()->for($schoolClass, 'schoolClass')->create();

        $this->actingAs($teacher->user)
            ->getJson('/api/attendance/sheet?class_id='.$schoolClass->id.'&date=2026-10-12')
            ->assertForbidden();
        $this->postJson('/api/attendance/sheet', [
            'class_id' => $schoolClass->id,
            'date' => '2026-10-12',
            'attendance' => [
                ['student_id' => $student->id, 'status' => 'hadir'],
            ],
        ])->assertForbidden();

        $this->assertDatabaseCount('attendance', 0);
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
            ->assertJsonPath('data.alpa', 0)
            ->assertJsonPath('data.attendance_rate', 50)
            ->assertJsonPath('data.daily_trends.0.date', '2026-10-07')
            ->assertJsonPath('data.daily_trends.0.attendance_rate', 0)
            ->assertJsonPath('data.daily_trends.1.date', '2026-10-08')
            ->assertJsonPath('data.daily_trends.1.attendance_rate', 100);
    }

    public function test_admin_can_view_semester_attendance_trends_and_frequent_absentees(): void
    {
        $admin = User::factory()->state(['role' => 'admin'])->create();
        $schoolClass = SchoolClass::factory()->create();
        $frequentStudent = Student::factory()->for($schoolClass, 'schoolClass')->create();
        $otherStudent = Student::factory()->for($schoolClass, 'schoolClass')->create();
        $presentStudent = Student::factory()->for($schoolClass, 'schoolClass')->create();

        Attendance::factory()->createMany([
            [
                'student_id' => $frequentStudent->id,
                'recorded_by' => $admin->id,
                'date' => '2026-10-07',
                'status' => 'alpa',
            ],
            [
                'student_id' => $frequentStudent->id,
                'recorded_by' => $admin->id,
                'date' => '2026-10-08',
                'status' => 'izin',
            ],
            [
                'student_id' => $otherStudent->id,
                'recorded_by' => $admin->id,
                'date' => '2026-10-08',
                'status' => 'sakit',
            ],
            [
                'student_id' => $presentStudent->id,
                'recorded_by' => $admin->id,
                'date' => '2026-10-08',
                'status' => 'hadir',
            ],
            [
                'student_id' => $frequentStudent->id,
                'recorded_by' => $admin->id,
                'date' => '2026-10-06',
                'status' => 'alpa',
            ],
        ]);

        $this->actingAs($admin)
            ->getJson('/api/reports/attendance?from=2026-10-07&to=2026-10-08&class_id='.$schoolClass->id)
            ->assertOk()
            ->assertJsonPath('data.total', 4)
            ->assertJsonPath('data.attendance_rate', 25)
            ->assertJsonCount(2, 'data.daily_trends')
            ->assertJsonPath('data.frequent_students.0.student_id', $frequentStudent->id)
            ->assertJsonPath('data.frequent_students.0.absence_count', 2)
            ->assertJsonPath('data.frequent_students.0.alpa', 1)
            ->assertJsonPath('data.frequent_students.0.izin', 1)
            ->assertJsonPath('data.frequent_students.1.student_id', $otherStudent->id)
            ->assertJsonPath('data.frequent_students.1.absence_count', 1)
            ->assertJsonCount(2, 'data.frequent_students');
    }

    public function test_attendance_report_returns_zero_rate_when_period_has_no_records(): void
    {
        $admin = User::factory()->state(['role' => 'admin'])->create();

        $this->actingAs($admin)
            ->getJson('/api/reports/attendance?from=2026-10-07&to=2026-10-08')
            ->assertOk()
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.attendance_rate', 0)
            ->assertJsonCount(0, 'data.daily_trends')
            ->assertJsonCount(0, 'data.frequent_students');
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
