<?php
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClassAssignmentController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SchoolClassController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\TeacherController;
use Illuminate\Support\Facades\Route;
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/dashboard', DashboardController::class);
    Route::middleware('role:admin')->group(function (): void {
        Route::apiResource('students', StudentController::class);
        Route::apiResource('classes', SchoolClassController::class)
            ->parameters(['classes' => 'schoolClass']);
        Route::apiResource('teachers', TeacherController::class);
        Route::get('/class-assignments', [ClassAssignmentController::class, 'index']);
        Route::post('/class-assignments', [ClassAssignmentController::class, 'store']);
        Route::delete('/class-assignments/{classTeacher}', [ClassAssignmentController::class, 'destroy']);
        Route::get('/reports/attendance', ReportController::class);
    });
    Route::get('/classes/{schoolClass}/students', [SchoolClassController::class, 'students'])
        ->middleware('role:admin,guru');
    Route::get('/teacher/classes', [ClassAssignmentController::class, 'teacherClasses'])
        ->middleware('role:guru');
    Route::apiResource('attendance', AttendanceController::class)
        ->only(['index', 'show', 'store', 'update', 'destroy'])
        ->middleware('role:admin,guru,siswa');
});
