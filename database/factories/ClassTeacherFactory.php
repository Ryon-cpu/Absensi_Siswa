<?php
namespace Database\Factories;
use App\Models\ClassTeacher;
use App\Models\SchoolClass;
use App\Models\Teacher;
use Illuminate\Database\Eloquent\Factories\Factory;
/**
 * @extends Factory<ClassTeacher>
 */
class ClassTeacherFactory extends Factory
{
    protected $model = ClassTeacher::class;
    public function definition(): array
    {
        return [
            'class_id' => SchoolClass::factory(),
            'teacher_id' => Teacher::factory(),
        ];
    }
}
