<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('attendance_audit_logs', function (Blueprint $table) {
            $table->id();
            // Keep snapshot IDs and names when attendance, student, class, or user records are removed.
            $table->unsignedBigInteger('attendance_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('class_id')->nullable();
            $table->string('student_number');
            $table->string('student_name');
            $table->string('class_name')->nullable();
            $table->unsignedBigInteger('actor_id');
            $table->string('actor_name');
            $table->string('action', 20);
            $table->string('previous_status', 10)->nullable();
            $table->string('new_status', 10)->nullable();
            $table->date('attendance_date');
            $table->timestamp('occurred_at');

            $table->index(['occurred_at', 'id']);
            $table->index(['student_id', 'occurred_at']);
            $table->index(['class_id', 'occurred_at']);
            $table->index('action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_audit_logs');
    }
};
