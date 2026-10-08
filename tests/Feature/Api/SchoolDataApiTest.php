<?php
namespace Tests\Feature\Api;
use App\Models\Attendance;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class SchoolDataApiTest extends TestCase
{
    use RefreshDatabase;
    public function test_admin_can_create_class_and_student(): void
    {
        $admin = User::factory()->state(['role' => 'admin'])->create();
        $this->actingAs($admin);
        $this->postJson('/api/classes', ['name' => 'Kelas 7A'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Kelas 7A');
        $schoolClass = SchoolClass::where('name', 'Kelas 7A')->firstOrFail();
        $this->getJson('/api/classes/'.$schoolClass->id)
            ->assertOk()
            ->assertJsonPath('data.name', 'Kelas 7A');
        $this->putJson('/api/classes/'.$schoolClass->id, ['name' => 'Kelas 7B'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Kelas 7B');
        $this->postJson('/api/students', [
            'class_id' => $schoolClass->id,
            'student_number' => 'S-001',
            'name' => 'Siswa Contoh',
        ])
            ->assertCreated()
            ->assertJsonPath('data.student_number', 'S-001');
        $this->assertModelExists(Student::where('student_number', 'S-001')->firstOrFail());
    }
    public function test_returns_401_when_unauthenticated_user_reads_classes(): void
    {
        $this->getJson('/api/classes')->assertUnauthorized();
    }
    public function test_non_admin_cannot_manage_school_data(): void
    {
        $teacher = User::factory()->state(['role' => 'guru'])->create();
        $this->actingAs($teacher)
            ->postJson('/api/classes', ['name' => 'Kelas Rahasia'])
            ->assertForbidden();
    }
    public function test_returns_409_when_class_with_students_is_deleted(): void
    {
        $admin = User::factory()->state(['role' => 'admin'])->create();
        $schoolClass = SchoolClass::factory()->create();
        Student::factory()->for($schoolClass, 'schoolClass')->create();
        $this->actingAs($admin)
            ->deleteJson('/api/classes/'.$schoolClass->id)
            ->assertConflict()
            ->assertJsonPath('message', 'Kelas tidak dapat dihapus selama masih memiliki siswa.');
        $this->assertModelExists($schoolClass);
    }
    public function test_student_crud_validates_unique_student_number_and_preserves_attendance_history(): void
    {
        $admin = User::factory()->state(['role' => 'admin'])->create();
        $schoolClass = SchoolClass::factory()->create();
        $student = Student::factory()->for($schoolClass, 'schoolClass')->create([
            'student_number' => 'S-100',
        ]);
        $this->actingAs($admin);
        $this->postJson('/api/students', [
            'class_id' => $schoolClass->id,
            'student_number' => 'S-100',
            'name' => 'Duplikat',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.student_number.0', 'The student number has already been taken.');
        $this->getJson('/api/students/'.$student->id)
            ->assertOk()
            ->assertJsonPath('data.student_number', 'S-100');
        $this->putJson('/api/students/'.$student->id, [
            'class_id' => $schoolClass->id,
            'student_number' => 'S-101',
            'name' => 'Siswa Diperbarui',
        ])
            ->assertOk()
            ->assertJsonPath('data.student_number', 'S-101');
        $teacher = User::factory()->state(['role' => 'guru'])->create();
        $attendance = Attendance::factory()->create([
            'student_id' => $student->id,
            'recorded_by' => $teacher->id,
        ]);
        $this->deleteJson('/api/students/'.$student->id)
            ->assertConflict()
            ->assertJsonPath('message', 'Siswa tidak dapat dihapus karena memiliki riwayat absensi.');
        $this->assertModelExists($student);
        $this->assertModelExists($attendance);
    }
    public function test_returns_404_when_requested_class_does_not_exist(): void
    {
        $admin = User::factory()->state(['role' => 'admin'])->create();
        $this->actingAs($admin)
            ->getJson('/api/classes/999999')
            ->assertNotFound();
    }
    public function test_admin_can_delete_empty_class_and_student(): void
    {
        $admin = User::factory()->state(['role' => 'admin'])->create();
        $schoolClass = SchoolClass::factory()->create();
        $student = Student::factory()->for($schoolClass, 'schoolClass')->create();
        $this->actingAs($admin)
            ->deleteJson('/api/students/'.$student->id)
            ->assertOk();
        $this->deleteJson('/api/classes/'.$schoolClass->id)
            ->assertOk();
        $this->assertModelMissing($student);
        $this->assertModelMissing($schoolClass);
    }
    public function test_returns_422_when_linked_account_is_not_a_student(): void
    {
        $admin = User::factory()->state(['role' => 'admin'])->create();
        $notStudent = User::factory()->state(['role' => 'guru'])->create();
        $schoolClass = SchoolClass::factory()->create();
        $this->actingAs($admin)
            ->postJson('/api/students', [
                'user_id' => $notStudent->id,
                'class_id' => $schoolClass->id,
                'student_number' => 'S-ROLE-001',
                'name' => 'Siswa Tertaut',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Akun yang dipilih harus memiliki role siswa.');
        $this->assertDatabaseCount('students', 0);
    }
    public function test_admin_can_create_teacher_account_and_assign_teacher_to_class(): void
    {
        $admin = User::factory()->state(['role' => 'admin'])->create();
        $schoolClass = SchoolClass::factory()->create();
        $this->actingAs($admin)
            ->postJson('/api/teachers', [
                'name' => 'Guru Contoh',
                'email' => 'teacher@example.test',
                'password' => 'secure-password',
            ])
            ->assertCreated()
            ->assertJsonMissingPath('data.user.password');
        $teacher = Teacher::where('name', 'Guru Contoh')->firstOrFail();
        $this->assertSame('guru', $teacher->user->role);
        $this->postJson('/api/class-assignments', [
            'class_id' => $schoolClass->id,
            'teacher_id' => $teacher->id,
        ])->assertCreated();
        $this->assertSame($schoolClass->id, $teacher->fresh()->schoolClasses->first()->id);
        $this->putJson('/api/teachers/'.$teacher->id, [
            'name' => 'Guru Diperbarui',
            'email' => 'teacher.updated@example.test',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Guru Diperbarui');
        $this->deleteJson('/api/teachers/'.$teacher->id)->assertOk();
        $this->assertModelMissing($teacher);
        $this->assertModelMissing($teacher->user);
    }
    public function test_returns_422_when_duplicate_class_assignment_is_created(): void
    {
        $admin = User::factory()->state(['role' => 'admin'])->create();
        $teacher = Teacher::factory()->create();
        $schoolClass = SchoolClass::factory()->create();
        $this->actingAs($admin);
        $payload = ['class_id' => $schoolClass->id, 'teacher_id' => $teacher->id];
        $this->postJson('/api/class-assignments', $payload)->assertCreated();
        $this->postJson('/api/class-assignments', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.class_id.0', 'Penugasan guru pada kelas tersebut sudah ada.');
        $this->assertDatabaseCount('class_teacher', 1);
    }
    public function test_returns_403_when_teacher_reads_students_from_unassigned_class(): void
    {
        $teacher = Teacher::factory()->create();
        $schoolClass = SchoolClass::factory()->create();
        Student::factory()->for($schoolClass, 'schoolClass')->create();
        $this->actingAs($teacher->user)
            ->getJson('/api/classes/'.$schoolClass->id.'/students')
            ->assertForbidden();
    }
}
