<?php

namespace Database\Factories;

use App\Models\AttendanceSession;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceSession>
 */
class AttendanceSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subject_id' => Subject::factory(),
            'recorded_by' => User::factory()->state(['role' => 'guru']),
            'meeting_number' => fake()->unique()->numberBetween(1, 40),
            'date' => fake()->date(),
        ];
    }
}
