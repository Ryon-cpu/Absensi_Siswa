<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        if (! Auth::guard('web')->attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['Email atau password tidak sesuai.'],
            ]);
        }
        $request->session()->regenerate();
        return response()->json([
            'message' => 'Login berhasil.',
            'data' => [
                'user' => $request->user('web')->only(['id', 'name', 'email', 'role']),
            ],
        ]);
    }
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return response()->json(['message' => 'Logout berhasil.']);
    }
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'user' => $request->user()->only(['id', 'name', 'email', 'role']),
            ],
        ]);
    }
}
