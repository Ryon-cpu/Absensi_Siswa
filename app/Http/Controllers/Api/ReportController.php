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
        return response()->json([
            'data' => [
                'total' => $counts->sum(),
                'hadir' => (int) $counts->get('hadir', 0),
                'izin' => (int) $counts->get('izin', 0),
                'sakit' => (int) $counts->get('sakit', 0),
                'alpa' => (int) $counts->get('alpa', 0),
            ],
        ]);
    }
}
