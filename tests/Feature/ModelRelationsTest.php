<?php
namespace Tests\Feature;
use App\Models\Attendance;
use App\Models\ClassTeacher;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class ModelRelationsTest extends TestCase
{
    use RefreshDatabase;
    public function test_factories_create_the_school_attendance_relationships(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        $teacher = Teacher::factory()->create();
        $student = Student::factory()->for($schoolClass, 'schoolClass')->create();
        $assignment = ClassTeacher::factory()
            ->for($schoolClass, 'schoolClass')
            ->for($teacher)
            ->create();
        $attendance = Attendance::factory()
            ->for($student)
            ->for($teacher->user, 'recorder')
            ->create();
        $this->assertModelExists($schoolClass);
        $this->assertModelExists($student);
        $this->assertModelExists($teacher);
        $this->assertModelExists($assignment);
        $this->assertModelExists($attendance);
        $this->assertSame($schoolClass->id, $student->schoolClass->id);
        $this->assertSame($schoolClass->id, $teacher->schoolClasses->first()->id);
        $this->assertSame($student->id, $attendance->student->id);
        $this->assertSame($teacher->user->id, $attendance->recorder->id);
        $this->assertSame('date:Y-m-d', $attendance->fresh()->getCasts()['date']);
    }
    public function test_user_factory_defaults_to_student_role_and_user_role_is_not_mass_assignable(): void
    {
        $user = User::factory()->create();
        $anotherUser = User::create([
            'name' => 'Test Admin',
            'email' => 'admin@example.test',
            'password' => 'password',
            'role' => 'admin',
        ]);
        $this->assertSame('siswa', $user->role);
        $this->assertSame('siswa', $anotherUser->fresh()->role);
    }
}
