<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceSession;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AttendanceSessionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);
        $user = $request->user();
        abort_unless(in_array($user->role, ['admin', 'guru'], true), Response::HTTP_FORBIDDEN);

        $subject = Subject::with('schoolClass')->findOrFail($validated['subject_id']);
        $this->ensureClassAccess($user, $subject);

        try {
            $attendanceSession = DB::transaction(function () use ($subject, $validated, $user): AttendanceSession {
                $subject = Subject::query()->lockForUpdate()->findOrFail($subject->id);
                $existingSession = AttendanceSession::query()
                    ->where('subject_id', $subject->id)
                    ->whereDate('date', $validated['date'])
                    ->first();

                if ($existingSession !== null) {
                    return $existingSession->load('subject:id,class_id,name');
                }

                return AttendanceSession::create([
                    'subject_id' => $subject->id,
                    'recorded_by' => $user->id,
                    'meeting_number' => (int) $subject->attendanceSessions()->max('meeting_number') + 1,
                    'date' => $validated['date'],
                ])->load('subject:id,class_id,name');
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'date' => ['Pertemuan untuk mata pelajaran dan tanggal tersebut baru saja dibuat. Muat ulang lalu coba lagi.'],
            ]);
        }

        return response()->json([
            'message' => 'Pertemuan absensi siap digunakan.',
            'data' => $attendanceSession,
        ]);
    }

    private function ensureClassAccess(User $user, Subject $subject): void
    {
        abort_unless(
            $user->role === 'admin'
                || $user->teacher?->schoolClasses()->whereKey($subject->class_id)->exists(),
            Response::HTTP_FORBIDDEN,
            'Anda tidak memiliki akses ke kelas ini.',
        );
    }
}
