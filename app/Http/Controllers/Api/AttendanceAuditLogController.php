<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AttendanceAuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'class_id' => ['sometimes', 'integer', 'min:1'],
            'student_id' => ['sometimes', 'integer', 'min:1'],
            'action' => ['sometimes', 'in:created,updated,deleted'],
        ]);
        $classIds = $request->user()->teacher?->schoolClasses()->pluck('classes.id') ?? collect();

        $auditLogs = AttendanceAuditLog::query()
            ->whereIn('class_id', $classIds)
            ->when(
                isset($filters['from']),
                fn ($query) => $query->where('occurred_at', '>=', $filters['from'].' 00:00:00'),
            )
            ->when(
                isset($filters['to']),
                fn ($query) => $query->where(
                    'occurred_at',
                    '<',
                    Carbon::parse($filters['to'])->addDay()->startOfDay(),
                ),
            )
            ->when(isset($filters['class_id']), fn ($query) => $query->where('class_id', $filters['class_id']))
            ->when(isset($filters['student_id']), fn ($query) => $query->where('student_id', $filters['student_id']))
            ->when(isset($filters['action']), fn ($query) => $query->where('action', $filters['action']))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return response()->json(['data' => $auditLogs]);
    }
}
