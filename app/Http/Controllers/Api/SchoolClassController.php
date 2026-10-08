<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
class SchoolClassController extends Controller
{
    public function index(): JsonResponse
    {
        $classes = SchoolClass::query()
            ->withCount(['students', 'teachers'])
            ->orderBy('name')
            ->paginate(15);
        return response()->json(['data' => $classes]);
    }
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:classes,name'],
        ]);
        $schoolClass = SchoolClass::create($validated);
        return response()->json([
            'message' => 'Kelas berhasil ditambahkan.',
            'data' => $schoolClass,
        ], Response::HTTP_CREATED);
    }
    public function show(SchoolClass $schoolClass): JsonResponse
    {
        $schoolClass->loadCount(['students', 'teachers']);
        return response()->json(['data' => $schoolClass]);
    }
    public function update(Request $request, SchoolClass $schoolClass): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('classes', 'name')->ignore($schoolClass)],
        ]);
        $schoolClass->update($validated);
        return response()->json([
            'message' => 'Kelas berhasil diperbarui.',
            'data' => $schoolClass->refresh(),
        ]);
    }
    public function destroy(SchoolClass $schoolClass): JsonResponse
    {
        if ($schoolClass->students()->exists()) {
            return response()->json([
                'message' => 'Kelas tidak dapat dihapus selama masih memiliki siswa.',
            ], Response::HTTP_CONFLICT);
        }
        $schoolClass->delete();
        return response()->json(['message' => 'Kelas berhasil dihapus.']);
    }
    public function students(Request $request, SchoolClass $schoolClass): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user->role === 'admin'
                || $user->teacher?->schoolClasses()->whereKey($schoolClass->id)->exists(),
            Response::HTTP_FORBIDDEN,
            'Anda tidak memiliki akses ke kelas ini.',
        );
        $students = $schoolClass->students()
            ->with('user:id,name,email')
            ->orderBy('name')
            ->paginate(15);
        return response()->json(['data' => $students]);
    }
}
