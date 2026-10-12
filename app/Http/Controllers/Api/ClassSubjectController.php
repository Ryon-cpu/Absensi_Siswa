<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class ClassSubjectController extends Controller
{
    public function index(Request $request, SchoolClass $schoolClass): JsonResponse
    {
        $this->ensureClassAccess($request->user(), $schoolClass);

        return response()->json([
            'data' => $schoolClass->subjects()->orderBy('name')->get(['id', 'class_id', 'name']),
        ]);
    }

    public function store(Request $request, SchoolClass $schoolClass): JsonResponse
    {
        $this->ensureClassAccess($request->user(), $schoolClass);
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('subjects', 'name')->where('class_id', $schoolClass->id),
            ],
        ]);

        try {
            $subject = $schoolClass->subjects()->create($validated);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'name' => ['Mata pelajaran tersebut sudah terdaftar di kelas ini.'],
            ]);
        }

        return response()->json([
            'message' => 'Mata pelajaran berhasil ditambahkan.',
            'data' => $subject,
        ], Response::HTTP_CREATED);
    }

    private function ensureClassAccess(User $user, SchoolClass $schoolClass): void
    {
        abort_unless(
            $user->role === 'admin'
                || $user->teacher?->schoolClasses()->whereKey($schoolClass->id)->exists(),
            Response::HTTP_FORBIDDEN,
            'Anda tidak memiliki akses ke kelas ini.',
        );
    }
}
