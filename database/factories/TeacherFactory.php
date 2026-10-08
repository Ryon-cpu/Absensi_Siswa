<?php
namespace Database\Factories;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
/**
 * @extends Factory<Teacher>
 */
class TeacherFactory extends Factory
{
    protected $model = Teacher::class;
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state(['role' => 'guru']),
            'name' => fake()->name(),
        ];
    }
}
