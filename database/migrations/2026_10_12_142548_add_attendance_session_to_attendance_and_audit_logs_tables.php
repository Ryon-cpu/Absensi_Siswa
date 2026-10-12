<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->dropUnique(['student_id', 'date']);
            $table->foreignId('attendance_session_id')
                ->nullable()
                ->after('recorded_by')
                ->constrained('attendance_sessions')
                ->restrictOnDelete();
            $table->unique(['student_id', 'attendance_session_id']);
            $table->index(['date', 'student_id']);
        });

        Schema::table('attendance_audit_logs', function (Blueprint $table) {
            $table->string('subject_name', 100)->nullable();
            $table->unsignedSmallInteger('meeting_number')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $hasRepeatedDates = DB::table('attendance')
            ->select('student_id', 'date')
            ->groupBy('student_id', 'date')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasRepeatedDates) {
            throw new LogicException(
                'Attendance sessions cannot be rolled back while a student has multiple records on the same date.',
            );
        }

        Schema::table('attendance_audit_logs', function (Blueprint $table) {
            $table->dropColumn(['subject_name', 'meeting_number']);
        });

        Schema::table('attendance', function (Blueprint $table) {
            $table->dropUnique(['student_id', 'attendance_session_id']);
            $table->dropIndex(['date', 'student_id']);
            $table->dropForeign(['attendance_session_id']);
            $table->dropColumn('attendance_session_id');
            $table->unique(['student_id', 'date']);
        });
    }
};
