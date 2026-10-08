<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
class TeacherController extends Controller
{
    public function index(): JsonResponse
    {
        $teachers = Teacher::query()
            ->with(['user:id,name,email', 'schoolClasses:id,name'])
            ->orderBy('name')
            ->paginate(15);
        return response()->json(['data' => $teachers]);
    }
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);
        $teacher = DB::transaction(function () use ($validated): Teacher {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
            ]);
            $user->role = 'guru';
            $user->save();
            return Teacher::create([
                'user_id' => $user->id,
                'name' => $validated['name'],
            ]);
        });
        return response()->json([
            'message' => 'Guru berhasil ditambahkan.',
            'data' => $teacher->load('user:id,name,email'),
        ], Response::HTTP_CREATED);
    }
    public function show(Teacher $teacher): JsonResponse
    {
        return response()->json([
            'data' => $teacher->load(['user:id,name,email', 'schoolClasses:id,name']),
        ]);
    }
    public function update(Request $request, Teacher $teacher): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($teacher->user_id),
            ],
            'password' => ['nullable', 'string', 'min:8'],
        ]);
        DB::transaction(function () use ($teacher, $validated): void {
            $teacher->update(['name' => $validated['name']]);
            $userData = [
                'name' => $validated['name'],
                'email' => $validated['email'],
            ];
            if (! empty($validated['password'])) {
                $userData['password'] = $validated['password'];
            }
            $teacher->user->update($userData);
        });
        return response()->json([
            'message' => 'Data guru berhasil diperbarui.',
            'data' => $teacher->refresh()->load('user:id,name,email'),
        ]);
    }
    public function destroy(Teacher $teacher): JsonResponse
    {
        if ($teacher->user->attendanceRecords()->exists()) {
            return response()->json([
                'message' => 'Guru tidak dapat dihapus karena pernah mencatat absensi.',
            ], Response::HTTP_CONFLICT);
        }
        DB::transaction(function () use ($teacher): void {
            $user = $teacher->user;
            $teacher->delete();
            $user->delete();
        });
        return response()->json(['message' => 'Guru berhasil dihapus.']);
    }
}
