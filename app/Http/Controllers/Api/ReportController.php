<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'class_id' => ['sometimes', 'integer', 'exists:classes,id'],
            'status' => ['sometimes', 'in:hadir,izin,sakit,alpa'],
        ]);
        $attendance = Attendance::query()
            ->when(isset($filters['from']), fn ($query) => $query->whereDate('date', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($query) => $query->whereDate('date', '<=', $filters['to']))
            ->when(isset($filters['class_id']), fn ($query) => $query->whereHas(
                'student',
                fn ($studentQuery) => $studentQuery->where('class_id', $filters['class_id']),
            ))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']));
        $counts = (clone $attendance)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
        $total = (int) $counts->sum();
        $dailyTrends = (clone $attendance)
            ->selectRaw('DATE(attendance.date) as attendance_date, attendance.status, COUNT(*) as total')
            ->groupByRaw('DATE(attendance.date), attendance.status')
            ->orderBy('attendance_date')
            ->get()
            ->groupBy('attendance_date')
            ->map(function ($dateRecords, string $date): array {
                $dateCounts = $dateRecords->pluck('total', 'status');
                $dateTotal = (int) $dateCounts->sum();

                return [
                    'date' => $date,
                    'total' => $dateTotal,
                    'hadir' => (int) $dateCounts->get('hadir', 0),
                    'izin' => (int) $dateCounts->get('izin', 0),
                    'sakit' => (int) $dateCounts->get('sakit', 0),
                    'alpa' => (int) $dateCounts->get('alpa', 0),
                    'attendance_rate' => $dateTotal > 0
                        ? round(((int) $dateCounts->get('hadir', 0) / $dateTotal) * 100, 1)
                        : 0,
                ];
            })
            ->values();
        $frequentStudents = (clone $attendance)
            ->whereIn('attendance.status', ['alpa', 'izin', 'sakit'])
            ->join('students', 'attendance.student_id', '=', 'students.id')
            ->join('classes', 'students.class_id', '=', 'classes.id')
            ->select(
                'students.id as student_id',
                'students.student_number',
                'students.name',
                'classes.name as class_name',
            )
            ->selectRaw('SUM(CASE WHEN attendance.status = ? THEN 1 ELSE 0 END) as alpa', ['alpa'])
            ->selectRaw('SUM(CASE WHEN attendance.status = ? THEN 1 ELSE 0 END) as izin', ['izin'])
            ->selectRaw('SUM(CASE WHEN attendance.status = ? THEN 1 ELSE 0 END) as sakit', ['sakit'])
            ->selectRaw('COUNT(*) as absence_count')
            ->groupBy('students.id', 'students.student_number', 'students.name', 'classes.name')
            ->orderByDesc('absence_count')
            ->orderBy('students.name')
            ->limit(10)
            ->get()
            ->map(fn ($student): array => [
                'student_id' => (int) $student->student_id,
                'student_number' => $student->student_number,
                'name' => $student->name,
                'class_name' => $student->class_name,
                'alpa' => (int) $student->alpa,
                'izin' => (int) $student->izin,
                'sakit' => (int) $student->sakit,
                'absence_count' => (int) $student->absence_count,
            ]);

        return response()->json([
            'data' => [
                'total' => $total,
                'hadir' => (int) $counts->get('hadir', 0),
                'izin' => (int) $counts->get('izin', 0),
                'sakit' => (int) $counts->get('sakit', 0),
                'alpa' => (int) $counts->get('alpa', 0),
                'attendance_rate' => $total > 0
                    ? round(((int) $counts->get('hadir', 0) / $total) * 100, 1)
                    : 0,
                'daily_trends' => $dailyTrends,
                'frequent_students' => $frequentStudents,
            ],
        ]);
    }
}
