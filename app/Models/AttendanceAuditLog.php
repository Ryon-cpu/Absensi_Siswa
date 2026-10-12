<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'attendance_id',
    'student_id',
    'class_id',
    'student_number',
    'student_name',
    'class_name',
    'subject_name',
    'actor_id',
    'actor_name',
    'action',
    'previous_status',
    'new_status',
    'attendance_date',
    'meeting_number',
    'occurred_at',
])]
class AttendanceAuditLog extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date:Y-m-d',
            'occurred_at' => 'datetime',
        ];
    }
}
