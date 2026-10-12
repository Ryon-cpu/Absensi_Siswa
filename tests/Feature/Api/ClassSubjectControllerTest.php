<?php

namespace Tests\Feature\Api;

use App\Models\ClassTeacher;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassSubjectControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_can_add_and_list_subjects_for_an_assigned_class(): void
    {
        $teacher = Teacher::factory()->create();
        $schoolClass = SchoolClass::factory()->create();
        ClassTeacher::factory()->for($schoolClass, 'schoolClass')->for($teacher)->create();
        $this->actingAs($teacher->user);

        $this->postJson('/api/classes/'.$schoolClass->id.'/subjects', ['name' => 'Matematika'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Matematika')
            ->assertJsonPath('data.class_id', $schoolClass->id);
        $this->getJson('/api/classes/'.$schoolClass->id.'/subjects')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Matematika');

        $this->assertDatabaseHas('subjects', [
            'class_id' => $schoolClass->id,
            'name' => 'Matematika',
        ]);
    }

    public function test_teacher_cannot_create_a_subject_for_an_unassigned_class(): void
    {
        $teacher = Teacher::factory()->create();
        $schoolClass = SchoolClass::factory()->create();

        $this->actingAs($teacher->user)
            ->postJson('/api/classes/'.$schoolClass->id.'/subjects', ['name' => 'Matematika'])
            ->assertForbidden();

        $this->assertDatabaseCount('subjects', 0);
    }

    public function test_duplicate_subject_name_in_the_same_class_returns_422(): void
    {
        $teacher = Teacher::factory()->create();
        $schoolClass = SchoolClass::factory()->create();
        ClassTeacher::factory()->for($schoolClass, 'schoolClass')->for($teacher)->create();
        Subject::factory()->for($schoolClass, 'schoolClass')->create(['name' => 'Matematika']);

        $this->actingAs($teacher->user)
            ->postJson('/api/classes/'.$schoolClass->id.'/subjects', ['name' => 'Matematika'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->assertDatabaseCount('subjects', 1);
    }
}
