<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_user_with_selected_permissions(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson('/api/v1/users', [
            'name' => 'Bayi Ali',
            'username' => 'bayi.ali',
            'password' => 'Parola12345',
            'permissions' => ['license.view', 'license.create'],
        ])->assertCreated()
            ->assertJsonPath('role', 'user')
            ->assertJsonPath('permissions', ['license.view', 'license.create'])
            ->assertJsonMissingPath('password');

        $created = User::where('username', 'bayi.ali')->firstOrFail();
        $this->assertNotSame('Parola12345', $created->password);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.create', 'subject_id' => $created->id]);
    }

    public function test_user_cannot_manage_users(): void
    {
        $user = User::factory()->create();
        $user->permissions()->create(['permission' => 'license.create']);

        $this->actingAs($user)->getJson('/api/v1/users')->assertForbidden();
        $this->actingAs($user)->postJson('/api/v1/users', [])->assertForbidden();
    }

    public function test_weak_password_and_duplicate_username_are_rejected(): void
    {
        $admin = User::factory()->admin()->create(['username' => 'admin']);

        $this->actingAs($admin)->postJson('/api/v1/users', [
            'name' => 'X', 'username' => 'admin', 'password' => 'kisa',
        ])->assertUnprocessable()->assertJsonValidationErrors(['username', 'password', 'name']);
    }

    public function test_admin_can_deactivate_user_but_not_self(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin)->patchJson("/api/v1/users/{$user->id}", ['is_active' => false])
            ->assertOk()->assertJsonPath('is_active', false);
        $this->actingAs($admin)->patchJson("/api/v1/users/{$admin->id}", ['is_active' => false])
            ->assertUnprocessable();

        $this->assertDatabaseHas('users', ['id' => $user->id]); // silinmez
    }
}
