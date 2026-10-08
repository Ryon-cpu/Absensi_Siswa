<?php
namespace Database\Factories;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;
/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    protected $model = Student::class;
    public function definition(): array
    {
        return [
            'user_id' => null,
            'class_id' => SchoolClass::factory(),
            'student_number' => fake()->unique()->numerify('##########'),
            'name' => fake()->name(),
        ];
    }
}
