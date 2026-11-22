<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Sanctum SPA: istek panelin domain'inden geliyormuş gibi (oturum middleware'i devreye girer).
        $this->withHeader('Referer', 'http://localhost:8000');
    }

    public function test_user_can_log_in_with_username_and_password(): void
    {
        User::factory()->create(['username' => 'ayse', 'password' => 'Gizli-Parola-123']);

        $this->postJson('/api/v1/auth/login', ['username' => 'Ayse', 'password' => 'Gizli-Parola-123'])
            ->assertOk();

        $this->assertAuthenticated('web');
        $this->getJson('/api/v1/me')->assertOk()->assertJson(['username' => 'ayse']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login']);
    }

    public function test_wrong_password_and_unknown_user_get_the_same_message(): void
    {
        User::factory()->create(['username' => 'ayse', 'password' => 'Gizli-Parola-123']);

        $wrong = $this->postJson('/api/v1/auth/login', ['username' => 'ayse', 'password' => 'yanlis'])
            ->assertUnprocessable()->json('errors.username.0');
        $unknown = $this->postJson('/api/v1/auth/login', ['username' => 'yok', 'password' => 'yanlis'])
            ->assertUnprocessable()->json('errors.username.0');

        $this->assertSame($wrong, $unknown);
        $this->assertGuest('web');
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        User::factory()->inactive()->create(['username' => 'pasif', 'password' => 'Gizli-Parola-123']);

        $this->postJson('/api/v1/auth/login', ['username' => 'pasif', 'password' => 'Gizli-Parola-123'])
            ->assertUnprocessable();
        $this->assertGuest('web');
    }

    public function test_login_is_rate_limited(): void
    {
        foreach (range(1, 5) as $_) {
            $this->postJson('/api/v1/auth/login', ['username' => 'x', 'password' => 'y'])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', ['username' => 'x', 'password' => 'y'])->assertTooManyRequests();
    }

    public function test_logout_ends_session(): void
    {
        User::factory()->create(['username' => 'ayse', 'password' => 'Gizli-Parola-123']);
        $this->postJson('/api/v1/auth/login', ['username' => 'ayse', 'password' => 'Gizli-Parola-123'])->assertOk();

        $this->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertGuest('web');
        $this->assertSame(2, AuditLog::whereIn('action', ['auth.login', 'auth.logout'])->count());
    }

    public function test_create_admin_command_creates_admin_without_password_in_arguments(): void
    {
        $this->artisan('panolicense:create-admin')
            ->expectsQuestion('Kullanıcı adı (küçük harf, rakam, . _ -)', 'yonetici')
            ->expectsQuestion('Ad Soyad', 'Yönetici')
            ->expectsQuestion('Parola (en az 12 karakter, harf ve rakam)', 'Cok-Gizli-Parola-42')
            ->expectsQuestion('Parola (tekrar)', 'Cok-Gizli-Parola-42')
            ->assertSuccessful();

        $admin = User::where('username', 'yonetici')->firstOrFail();
        $this->assertTrue($admin->isAdmin());
    }

    public function test_create_admin_command_rejects_weak_password(): void
    {
        $this->artisan('panolicense:create-admin')
            ->expectsQuestion('Kullanıcı adı (küçük harf, rakam, . _ -)', 'yonetici')
            ->expectsQuestion('Ad Soyad', 'Yönetici')
            ->expectsQuestion('Parola (en az 12 karakter, harf ve rakam)', 'kisa')
            ->expectsQuestion('Parola (tekrar)', 'kisa')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['username' => 'yonetici']);
    }
}
