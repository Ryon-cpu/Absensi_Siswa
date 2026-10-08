<?php
namespace Database\Factories;
use App\Models\Attendance;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
/**
 * @extends Factory<Attendance>
 */
class AttendanceFactory extends Factory
{
    protected $model = Attendance::class;
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'recorded_by' => User::factory()->state(['role' => 'guru']),
            'date' => fake()->date(),
            'status' => fake()->randomElement(['hadir', 'izin', 'sakit', 'alpa']),
        ];
    }
}
