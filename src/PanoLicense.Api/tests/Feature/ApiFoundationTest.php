<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_is_public_under_v1_prefix(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJson(['status' => 'ok']);
    }

    public function test_unversioned_api_path_is_not_served(): void
    {
        $this->getJson('/api/health')->assertNotFound();
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_me_returns_role_and_permissions_for_authenticated_user(): void
    {
        $user = User::factory()->create();
        $user->permissions()->create(['permission' => 'license.view']);

        $this->actingAs($user)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJson(['role' => 'user', 'permissions' => ['license.view']])
            ->assertJsonMissingPath('password');
    }
}
