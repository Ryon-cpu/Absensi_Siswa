<?php
namespace Tests\Feature\Api;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class AuthApiTest extends TestCase
{
    use RefreshDatabase;
    public function test_login_returns_safe_user_data_and_session_authenticates_me_endpoint(): void
    {
        User::factory()->create([
            'email' => 'student@example.test',
            'password' => 'correct-password',
        ]);
        $this->withHeader('Origin', 'http://127.0.0.1:8000')
            ->postJson('/api/login', [
                'email' => 'student@example.test',
                'password' => 'correct-password',
            ])
            ->assertOk()
            ->assertJsonPath('data.user.email', 'student@example.test')
            ->assertJsonPath('data.user.role', 'siswa')
            ->assertJsonMissingPath('data.user.password');
        $this->withHeader('Origin', 'http://127.0.0.1:8000')
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'student@example.test');
        $this->withHeader('Origin', 'http://127.0.0.1:8000')
            ->postJson('/api/logout')
            ->assertOk()
            ->assertSessionMissing('login_web_'.sha1(User::class));
    }
    public function test_invalid_login_returns_validation_message(): void
    {
        $this->withHeader('Origin', 'http://localhost:5173')
            ->postJson('/api/login', [
                'email' => 'missing@example.test',
                'password' => 'incorrect-password',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Email atau password tidak sesuai.');
    }
    public function test_me_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
    }
    public function test_csrf_cookie_endpoint_allows_configured_frontend_origin_with_credentials(): void
    {
        $this->withHeader('Origin', 'http://localhost:5173')
            ->get('/sanctum/csrf-cookie')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }
}
