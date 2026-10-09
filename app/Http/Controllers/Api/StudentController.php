<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class StudentController extends Controller
{
    public function index(): JsonResponse
    {
        $students = Student::query()
            ->with(['schoolClass:id,name', 'user:id,name,email'])
            ->orderBy('name')
            ->paginate(15);

        return response()->json(['data' => $students]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id', 'unique:students,user_id'],
            'class_id' => ['required', 'integer', 'exists:classes,id'],
            'student_number' => ['required', 'string', 'max:255', 'unique:students,student_number'],
            'name' => ['required', 'string', 'max:255'],
            'parent_whatsapp_phone' => ['nullable', 'string', 'max:16', 'regex:/^\+[1-9]\d{7,14}$/'],
        ]);
        if (! empty($validated['user_id'])) {
            abort_unless(
                User::whereKey($validated['user_id'])->where('role', 'siswa')->exists(),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'Akun yang dipilih harus memiliki role siswa.',
            );
        }
        $student = Student::create($validated);

        return response()->json([
            'message' => 'Siswa berhasil ditambahkan.',
            'data' => $student->load(['schoolClass:id,name', 'user:id,name,email']),
        ], Response::HTTP_CREATED);
    }

    public function show(Student $student): JsonResponse
    {
        return response()->json([
            'data' => $student->load(['schoolClass:id,name', 'user:id,name,email']),
        ]);
    }

    public function update(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => [
                'nullable',
                'integer',
                'exists:users,id',
                Rule::unique('students', 'user_id')->ignore($student),
            ],
            'class_id' => ['required', 'integer', 'exists:classes,id'],
            'student_number' => [
                'required',
                'string',
                'max:255',
                Rule::unique('students', 'student_number')->ignore($student),
            ],
            'name' => ['required', 'string', 'max:255'],
            'parent_whatsapp_phone' => ['nullable', 'string', 'max:16', 'regex:/^\+[1-9]\d{7,14}$/'],
        ]);
        if (! empty($validated['user_id'])) {
            abort_unless(
                User::whereKey($validated['user_id'])->where('role', 'siswa')->exists(),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'Akun yang dipilih harus memiliki role siswa.',
            );
        }
        $student->update($validated);

        return response()->json([
            'message' => 'Data siswa berhasil diperbarui.',
            'data' => $student->refresh()->load(['schoolClass:id,name', 'user:id,name,email']),
        ]);
    }

    public function destroy(Student $student): JsonResponse
    {
        if ($student->attendanceRecords()->exists()) {
            return response()->json([
                'message' => 'Siswa tidak dapat dihapus karena memiliki riwayat absensi.',
            ], Response::HTTP_CONFLICT);
        }
        $student->delete();

        return response()->json(['message' => 'Siswa berhasil dihapus.']);
    }
}
