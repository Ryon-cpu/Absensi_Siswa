<?php
namespace App\Models;
use Database\Factories\ClassTeacherFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
#[Fillable(['class_id', 'teacher_id'])]
class ClassTeacher extends Model
{
    /** @use HasFactory<ClassTeacherFactory> */
    use HasFactory;
    protected $table = 'class_teacher';
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }
}
