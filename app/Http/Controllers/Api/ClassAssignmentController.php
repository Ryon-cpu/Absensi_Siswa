<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\ClassTeacher;
use App\Models\Teacher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
class ClassAssignmentController extends Controller
{
    public function index(): JsonResponse
    {
        $assignments = ClassTeacher::query()
            ->with(['schoolClass:id,name', 'teacher:id,name,user_id'])
            ->orderBy('class_id')
            ->paginate(15);
        return response()->json(['data' => $assignments]);
    }
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'class_id' => ['required', 'integer', 'exists:classes,id'],
            'teacher_id' => ['required', 'integer', 'exists:teachers,id'],
        ]);
        $request->validate([
            'class_id' => [
                Rule::unique('class_teacher', 'class_id')
                    ->where('teacher_id', $validated['teacher_id']),
            ],
        ], [
            'class_id.unique' => 'Penugasan guru pada kelas tersebut sudah ada.',
        ]);
        $assignment = ClassTeacher::create($validated);
        return response()->json([
            'message' => 'Penugasan guru berhasil ditambahkan.',
            'data' => $assignment->load(['schoolClass:id,name', 'teacher:id,name,user_id']),
        ], Response::HTTP_CREATED);
    }
    public function destroy(ClassTeacher $classTeacher): JsonResponse
    {
        $classTeacher->delete();
        return response()->json(['message' => 'Penugasan guru berhasil dihapus.']);
    }
    public function teacherClasses(Request $request): JsonResponse
    {
        $teacher = Teacher::where('user_id', $request->user()->id)->firstOrFail();
        $classes = $teacher->schoolClasses()
            ->withCount('students')
            ->orderBy('name')
            ->paginate(15);
        return response()->json(['data' => $classes]);
    }
}
