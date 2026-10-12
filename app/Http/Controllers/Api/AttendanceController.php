<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\AttendanceAuditLog;
use App\Models\AttendanceSession;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\AttendanceNotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AttendanceController extends Controller
{
    public function sheet(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'attendance_session_id' => ['sometimes', 'required', 'integer', 'exists:attendance_sessions,id'],
            'class_id' => ['required_without:attendance_session_id', 'integer', 'exists:classes,id'],
            'date' => ['required_without:attendance_session_id', 'date_format:Y-m-d'],
        ]);
        $attendanceSession = isset($filters['attendance_session_id'])
            ? AttendanceSession::with('subject.schoolClass')->findOrFail($filters['attendance_session_id'])
            : null;
        $schoolClass = $attendanceSession?->subject->schoolClass
            ?? SchoolClass::findOrFail($filters['class_id']);
        $date = $attendanceSession?->date->toDateString() ?? $filters['date'];
        $this->ensureTeacherCanAccessClass($request->user(), $schoolClass);

        $students = $schoolClass->students()
            ->select(['id', 'class_id', 'student_number', 'name'])
            ->with([
                'attendanceRecords' => function ($query) use ($date, $attendanceSession): void {
                    if ($attendanceSession !== null) {
                        $query->where('attendance_session_id', $attendanceSession->id);
                    } else {
                        $query->whereNull('attendance_session_id')->whereDate('date', $date);
                    }

                    $query->select(['id', 'student_id', 'attendance_session_id', 'date', 'status']);
                },
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (Student $student): array => [
                'id' => $student->id,
                'student_number' => $student->student_number,
                'name' => $student->name,
                'attendance_id' => $student->attendanceRecords->first()?->id,
                'status' => $student->attendanceRecords->first()?->status,
            ]);

        return response()->json([
            'data' => [
                'class' => $schoolClass->only(['id', 'name']),
                'date' => $date,
                'session' => $attendanceSession === null ? null : [
                    'id' => $attendanceSession->id,
                    'subject' => $attendanceSession->subject->name,
                    'meeting_number' => $attendanceSession->meeting_number,
                ],
                'students' => $students,
            ],
        ]);
    }

    public function storeSheet(Request $request, AttendanceNotificationService $notifications): JsonResponse
    {
        $validated = $request->validate([
            'attendance_session_id' => ['sometimes', 'required', 'integer', 'exists:attendance_sessions,id'],
            'class_id' => ['required_without:attendance_session_id', 'integer', 'exists:classes,id'],
            'date' => ['required_without:attendance_session_id', 'date_format:Y-m-d'],
            'attendance' => ['present', 'array'],
            'attendance.*.student_id' => ['required', 'integer', 'distinct', 'exists:students,id'],
            'attendance.*.status' => ['required', 'in:hadir,izin,sakit,alpa'],
        ]);
        $user = $request->user();
        abort_unless(in_array($user->role, ['admin', 'guru'], true), Response::HTTP_FORBIDDEN);

        $attendanceSession = isset($validated['attendance_session_id'])
            ? AttendanceSession::with('subject.schoolClass')->findOrFail($validated['attendance_session_id'])
            : null;
        $schoolClass = $attendanceSession?->subject->schoolClass
            ?? SchoolClass::findOrFail($validated['class_id']);
        $date = $attendanceSession?->date->toDateString() ?? $validated['date'];
        $this->ensureTeacherCanAccessClass($user, $schoolClass);

        $students = $schoolClass->students()
            ->with('schoolClass')
            ->orderBy('id')
            ->get()
            ->keyBy('id');
        $submittedStudentIds = collect($validated['attendance'])
            ->pluck('student_id')
            ->map(fn ($studentId): int => (int) $studentId)
            ->sort()
            ->values();

        if ($submittedStudentIds->all() !== $students->keys()->map(fn ($studentId): int => (int) $studentId)->sort()->values()->all()) {
            throw ValidationException::withMessages([
                'attendance' => ['Daftar siswa kelas berubah. Muat ulang lembar absensi sebelum menyimpan.'],
            ]);
        }

        try {
            $changedAttendance = DB::transaction(function () use (
                $validated,
                $schoolClass,
                $user,
                $attendanceSession,
                $date,
            ): array {
                $lockedStudents = $schoolClass->students()
                    ->with('schoolClass')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
                $submittedStudentIds = collect($validated['attendance'])
                    ->pluck('student_id')
                    ->map(fn ($studentId): int => (int) $studentId)
                    ->sort()
                    ->values();

                if ($submittedStudentIds->all() !== $lockedStudents->keys()->map(fn ($studentId): int => (int) $studentId)->sort()->values()->all()) {
                    throw ValidationException::withMessages([
                        'attendance' => ['Daftar siswa kelas berubah. Muat ulang lembar absensi sebelum menyimpan.'],
                    ]);
                }

                $existingAttendance = Attendance::query()
                    ->whereIn('student_id', $lockedStudents->keys())
                    ->when(
                        $attendanceSession !== null,
                        fn ($query) => $query->where('attendance_session_id', $attendanceSession->id),
                        fn ($query) => $query->whereNull('attendance_session_id')->whereDate('date', $date),
                    )
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('student_id');
                $changedAttendance = [];

                foreach ($validated['attendance'] as $entry) {
                    $student = $lockedStudents->get($entry['student_id']);
                    $attendance = $existingAttendance->get($entry['student_id']);

                    if ($attendance === null) {
                        $attendance = Attendance::create([
                            'student_id' => $student->id,
                            'recorded_by' => $user->id,
                            'attendance_session_id' => $attendanceSession?->id,
                            'date' => $date,
                            'status' => $entry['status'],
                        ]);
                        $this->recordAuditLog(
                            $attendance,
                            $user,
                            'created',
                            null,
                            $attendance->status,
                            $student,
                            $attendanceSession,
                        );
                        $changedAttendance[] = $attendance;

                        continue;
                    }

                    $previousStatus = $attendance->status;
                    if ($previousStatus === $entry['status']) {
                        continue;
                    }

                    $attendance->update([
                        'status' => $entry['status'],
                    ]);
                    $this->recordAuditLog(
                        $attendance,
                        $user,
                        'updated',
                        $previousStatus,
                        $attendance->status,
                        $student,
                        $attendanceSession,
                    );
                    $changedAttendance[] = $attendance;
                }

                return $changedAttendance;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'attendance' => ['Absensi kelas ini baru saja diubah. Muat ulang lembar absensi sebelum menyimpan.'],
            ]);
        }

        foreach ($changedAttendance as $attendance) {
            $notifications->queueFor($attendance);
        }

        return response()->json([
            'message' => 'Absensi kelas berhasil disimpan.',
            'data' => [
                'attendance_session_id' => $attendanceSession?->id,
                'saved' => count($changedAttendance),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'class_id' => ['sometimes', 'integer', 'exists:classes,id'],
            'student_id' => ['sometimes', 'integer', 'exists:students,id'],
            'status' => ['sometimes', 'in:hadir,izin,sakit,alpa'],
        ]);
        $query = Attendance::query()
            ->with([
                'student:id,class_id,student_number,name',
                'student.schoolClass:id,name',
                'recorder:id,name',
                'attendanceSession:id,subject_id,meeting_number,date',
                'attendanceSession.subject:id,name',
            ])
            ->when(isset($filters['date']), fn ($query) => $query->whereDate('date', $filters['date']))
            ->when(isset($filters['class_id']), fn ($query) => $query->whereHas(
                'student',
                fn ($studentQuery) => $studentQuery->where('class_id', $filters['class_id']),
            ))
            ->when(isset($filters['student_id']), fn ($query) => $query->where('student_id', $filters['student_id']))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']));
        $this->scopeToUser($query, $request->user());

        return response()->json([
            'data' => $query->orderByDesc('date')
                ->orderBy('student_id')
                ->orderBy('attendance_session_id')
                ->paginate(15)
                ->withQueryString(),
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
        try {
            $attendance = DB::transaction(function () use ($validated, $user, $student): Attendance {
                Student::query()->lockForUpdate()->findOrFail($student->id);
                if (Attendance::query()
                    ->where('student_id', $student->id)
                    ->whereNull('attendance_session_id')
                    ->whereDate('date', $validated['date'])
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'student_id' => ['Absensi siswa untuk tanggal tersebut sudah tercatat.'],
                    ]);
                }

                $attendance = Attendance::create([
                    ...$validated,
                    'recorded_by' => $user->id,
                ]);
                $this->recordAuditLog($attendance, $user, 'created', null, $attendance->status);

                return $attendance;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'student_id' => ['Absensi siswa untuk tanggal tersebut sudah tercatat.'],
            ]);
        }
        $notifications->queueFor($attendance);

        return response()->json([
            'message' => 'Absensi berhasil dicatat.',
            'data' => $attendance->load([
                'student:id,class_id,student_number,name',
                'recorder:id,name',
                'attendanceSession:id,subject_id,meeting_number,date',
                'attendanceSession.subject:id,name',
            ]),
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
                'attendanceSession:id,subject_id,meeting_number,date',
                'attendanceSession.subject:id,name',
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
        $user = $request->user();
        [$attendance, $statusChanged] = DB::transaction(function () use ($attendance, $validated, $user): array {
            $attendance = Attendance::query()->lockForUpdate()->findOrFail($attendance->id);
            $previousStatus = $attendance->status;
            $attendance->update($validated);

            if ($previousStatus === $attendance->status) {
                return [$attendance, false];
            }

            $this->recordAuditLog($attendance, $user, 'updated', $previousStatus, $attendance->status);

            return [$attendance, true];
        });
        if ($statusChanged) {
            $notifications->queueFor($attendance);
        }

        return response()->json([
            'message' => 'Absensi berhasil diperbarui.',
            'data' => $attendance->refresh()->load([
                'student:id,class_id,student_number,name',
                'recorder:id,name',
                'attendanceSession:id,subject_id,meeting_number,date',
                'attendanceSession.subject:id,name',
            ]),
        ]);
    }

    public function destroy(Request $request, Attendance $attendance): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['admin', 'guru'], true), Response::HTTP_FORBIDDEN);
        $this->authorizeAttendanceAccess($request->user(), $attendance);
        $user = $request->user();
        DB::transaction(function () use ($attendance, $user): void {
            $attendance = Attendance::query()->lockForUpdate()->findOrFail($attendance->id);
            $this->recordAuditLog($attendance, $user, 'deleted', $attendance->status, null);
            $attendance->delete();
        });

        return response()->json(['message' => 'Catatan absensi berhasil dihapus.']);
    }

    private function recordAuditLog(
        Attendance $attendance,
        User $actor,
        string $action,
        ?string $previousStatus,
        ?string $newStatus,
        ?Student $student = null,
        ?AttendanceSession $attendanceSession = null,
    ): void {
        $student ??= $attendance->student()->with('schoolClass')->firstOrFail();
        $attendanceSession ??= $attendance->attendance_session_id === null
            ? null
            : $attendance->attendanceSession()->with('subject')->firstOrFail();

        AttendanceAuditLog::create([
            'attendance_id' => $attendance->id,
            'student_id' => $student->id,
            'class_id' => $student->class_id,
            'student_number' => $student->student_number,
            'student_name' => $student->name,
            'class_name' => $student->schoolClass?->name,
            'subject_name' => $attendanceSession?->subject->name,
            'actor_id' => $actor->id,
            'actor_name' => $actor->name,
            'action' => $action,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'attendance_date' => $attendance->date->toDateString(),
            'meeting_number' => $attendanceSession?->meeting_number,
            'occurred_at' => now(),
        ]);
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
        $schoolClass = SchoolClass::findOrFail($student->class_id);
        $this->ensureTeacherCanAccessClass($user, $schoolClass);
    }

    private function ensureTeacherCanAccessClass(User $user, SchoolClass $schoolClass): void
    {
        abort_unless(
            $user->role === 'admin'
                || $user->teacher?->schoolClasses()->whereKey($schoolClass->id)->exists(),
            Response::HTTP_FORBIDDEN,
            'Anda tidak memiliki akses ke kelas ini.',
        );
    }
}
