<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_has_every_permission_including_admin_only(): void
    {
        $admin = User::factory()->admin()->create();

        foreach (Permission::cases() as $permission) {
            $this->assertTrue(Gate::forUser($admin)->allows($permission->value), $permission->value);
        }
        $this->assertTrue(Gate::forUser($admin)->allows('user.manage'));
    }

    public function test_user_only_has_granted_permissions(): void
    {
        $user = User::factory()->create();
        $user->permissions()->create(['permission' => Permission::LicenseExtend->value]);

        $this->assertTrue(Gate::forUser($user)->allows('license.extend'));
        $this->assertFalse(Gate::forUser($user)->allows('license.revoke'));
        $this->assertFalse(Gate::forUser($user)->allows('license.create'));
    }

    public function test_user_can_never_use_admin_only_abilities(): void
    {
        $user = User::factory()->create();

        $this->assertFalse(Gate::forUser($user)->allows('user.manage'));
        $this->assertFalse(Gate::forUser($user)->allows('settings.manage'));
    }

    public function test_inactive_users_are_denied_even_if_admin(): void
    {
        $admin = User::factory()->admin()->inactive()->create();
        $user = User::factory()->inactive()->create();
        $user->permissions()->create(['permission' => Permission::LicenseView->value]);

        $this->assertFalse(Gate::forUser($admin)->allows('license.view'));
        $this->assertFalse(Gate::forUser($user)->allows('license.view'));
    }

    public function test_permission_check_queries_database_once_per_request(): void
    {
        $user = User::factory()->create();
        $user->permissions()->create(['permission' => Permission::LicenseView->value]);
        $user = User::find($user->id);

        \DB::enableQueryLog();
        $user->hasPermission(Permission::LicenseView);
        $user->hasPermission(Permission::LicenseCreate);
        $user->hasPermission(Permission::DeviceView);

        $this->assertCount(1, \DB::getQueryLog());
    }
}
