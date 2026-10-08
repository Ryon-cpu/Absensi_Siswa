<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->role === 'admin') {
            $data = [
                'students' => Student::count(),
                'classes' => SchoolClass::count(),
                'teachers' => Teacher::count(),
                'today_attendance' => $this->attendanceCounts(Attendance::whereDate('date', today())),
            ];
        } elseif ($user->role === 'guru') {
            $teacher = $user->teacher;
            $classIds = $teacher?->schoolClasses()->pluck('classes.id') ?? collect();
            $data = [
                'classes' => $classIds->count(),
                'students' => Student::whereIn('class_id', $classIds)->count(),
                'today_attendance' => $this->attendanceCounts(
                    Attendance::whereDate('date', today())
                        ->whereHas('student', fn ($query) => $query->whereIn('class_id', $classIds)),
                ),
            ];
        } else {
            $student = $user->student;
            $data = [
                'attendance' => $student
                    ? $this->attendanceCounts($student->attendanceRecords())
                    : ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => 0, 'total' => 0],
            ];
        }
        return response()->json(['data' => $data]);
    }
    private function attendanceCounts(Builder|HasMany $query): array
    {
        $counts = $query->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
        return [
            'hadir' => (int) $counts->get('hadir', 0),
            'izin' => (int) $counts->get('izin', 0),
            'sakit' => (int) $counts->get('sakit', 0),
            'alpa' => (int) $counts->get('alpa', 0),
            'total' => (int) $counts->sum(),
        ];
    }
}
