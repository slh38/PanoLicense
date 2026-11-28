<?php

namespace Tests\Feature;

use App\Models\License;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** DDL implicit commit nedeniyle transaction kullanan RefreshDatabase testlerinden ayrıdır. */
class PerpetualMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_existing_term_row_is_unchanged_by_nullable_migration_and_roundtrip(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $id = $this->postJson('/api/v1/licenses', [
            'customer' => ['name' => 'İzole Migration Firması'],
            'product_id' => Product::where('code', 'PANOPOS')->value('id'),
            'package_id' => Package::where('code', 'PRO')->value('id'),
            'device_limit' => 2, 'starts_at' => '2026-11-28', 'expires_at' => '2030-11-28',
        ])->assertCreated()->json('data.id');
        $migration = require database_path('migrations/2026_11_28_160000_add_perpetual_licenses.php');
        $migration->down();
        $before = (array) DB::table('licenses')->where('id', $id)->first();
        $migration->up();
        $after = (array) DB::table('licenses')->where('id', $id)->first();
        $this->assertSame('term', $after['license_type']);
        unset($after['license_type']);
        $this->assertSame($before, $after);
        $license = License::findOrFail($id);
        $license->update(['license_type' => 'perpetual', 'expires_at' => null]);
        $this->assertTrue($license->fresh()->isPerpetual());
        try {
            $migration->down();
            $this->fail('Kayıplı rollback kabul edildi.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('geri alınamaz', $e->getMessage());
        }
        $this->assertNull($license->fresh()->expires_at);
        // Yalnız izole fixture: DatabaseMigrations teardown rollback'ine tarihli satır bırak.
        DB::table('licenses')->where('id', $id)->update(['license_type' => 'term', 'expires_at' => '2030-11-28']);
    }
}
