<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Student;
use App\Models\User;
use App\Services\AttendanceNotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AttendanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'class_id' => ['sometimes', 'integer', 'exists:classes,id'],
            'student_id' => ['sometimes', 'integer', 'exists:students,id'],
            'status' => ['sometimes', 'in:hadir,izin,sakit,alpa'],
        ]);
        $query = Attendance::query()
            ->with(['student:id,class_id,student_number,name', 'student.schoolClass:id,name', 'recorder:id,name'])
            ->when(isset($filters['date']), fn ($query) => $query->whereDate('date', $filters['date']))
            ->when(isset($filters['class_id']), fn ($query) => $query->whereHas(
                'student',
                fn ($studentQuery) => $studentQuery->where('class_id', $filters['class_id']),
            ))
            ->when(isset($filters['student_id']), fn ($query) => $query->where('student_id', $filters['student_id']))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']));
        $this->scopeToUser($query, $request->user());

        return response()->json([
            'data' => $query->orderByDesc('date')->orderBy('student_id')->paginate(15)->withQueryString(),
        ]);
    }

    public function store(Request $request, AttendanceNotificationService $notifications): JsonResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['admin', 'guru'], true), Response::HTTP_FORBIDDEN);
        $validated = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'date' => ['required', 'date_format:Y-m-d'],
            'status' => ['required', 'in:hadir,izin,sakit,alpa'],
        ]);
        $student = Student::findOrFail($validated['student_id']);
        $this->ensureTeacherCanAccess($user, $student);
        if (Attendance::where('student_id', $student->id)->whereDate('date', $validated['date'])->exists()) {
            throw ValidationException::withMessages([
                'student_id' => ['Absensi siswa untuk tanggal tersebut sudah tercatat.'],
            ]);
        }
        try {
            $attendance = Attendance::create([
                ...$validated,
                'recorded_by' => $user->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'student_id' => ['Absensi siswa untuk tanggal tersebut sudah tercatat.'],
            ]);
        }
        $notifications->queueFor($attendance);

        return response()->json([
            'message' => 'Absensi berhasil dicatat.',
            'data' => $attendance->load(['student:id,class_id,student_number,name', 'recorder:id,name']),
        ], Response::HTTP_CREATED);
    }

    public function show(Request $request, Attendance $attendance): JsonResponse
    {
        $this->authorizeAttendanceAccess($request->user(), $attendance);

        return response()->json([
            'data' => $attendance->load([
                'student:id,class_id,student_number,name',
                'student.schoolClass:id,name',
                'recorder:id,name',
            ]),
        ]);
    }

    public function update(
        Request $request,
        Attendance $attendance,
        AttendanceNotificationService $notifications,
    ): JsonResponse {
        abort_unless(in_array($request->user()->role, ['admin', 'guru'], true), Response::HTTP_FORBIDDEN);
        $this->authorizeAttendanceAccess($request->user(), $attendance);
        $validated = $request->validate([
            'status' => ['required', 'in:hadir,izin,sakit,alpa'],
        ]);
        $previousStatus = $attendance->status;
        $attendance->update($validated);
        if ($previousStatus !== $attendance->status) {
            $notifications->queueFor($attendance);
        }

        return response()->json([
            'message' => 'Absensi berhasil diperbarui.',
            'data' => $attendance->refresh()->load(['student:id,class_id,student_number,name', 'recorder:id,name']),
        ]);
    }

    public function destroy(Request $request, Attendance $attendance): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['admin', 'guru'], true), Response::HTTP_FORBIDDEN);
        $this->authorizeAttendanceAccess($request->user(), $attendance);
        $attendance->delete();

        return response()->json(['message' => 'Catatan absensi berhasil dihapus.']);
    }

    private function scopeToUser(Builder $query, User $user): void
    {
        if ($user->role === 'guru') {
            $teacherId = $user->teacher?->id;
            $query->whereHas('student.schoolClass.classTeachers', function ($classTeachers) use ($teacherId): void {
                $classTeachers->where('teacher_id', $teacherId);
            });
        }
        if ($user->role === 'siswa') {
            $query->whereHas('student', fn ($students) => $students->where('user_id', $user->id));
        }
    }

    private function authorizeAttendanceAccess(User $user, Attendance $attendance): void
    {
        if ($user->role === 'admin') {
            return;
        }
        if ($user->role === 'siswa') {
            abort_unless(
                $attendance->student()->where('user_id', $user->id)->exists(),
                Response::HTTP_NOT_FOUND,
                'Data absensi tidak ditemukan.',
            );

            return;
        }
        $this->ensureTeacherCanAccess($user, $attendance->student);
    }

    private function ensureTeacherCanAccess(User $user, Student $student): void
    {
        if ($user->role === 'guru') {
            abort_unless(
                $user->teacher?->schoolClasses()->whereKey($student->class_id)->exists(),
                Response::HTTP_FORBIDDEN,
                'Anda tidak memiliki akses ke kelas siswa ini.',
            );
        }
    }
}
