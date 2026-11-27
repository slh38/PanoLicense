<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ürün → paket → modül kataloğu ve lisansa özel ek modüller.
 * Geçerli modüller = paket modülleri + lisansa özel ek modüller (yalnız aktif modüller).
 * PanoPOS başlangıç paketleri: Lite, Pano, Restaurant, Pro (modül matrisi panelden tanımlanır).
 * Mevcut lisanslar Pro'ya eşlenir; demo paketi Pro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained();
            $table->string('code', 30);
            $table->string('name', 100);
            $table->unsignedSmallInteger('default_device_limit')->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['product_id', 'code']);
        });

        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained();
            $table->string('code', 40);
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['product_id', 'code']);
        });

        Schema::create('package_module', function (Blueprint $table) {
            $table->foreignId('package_id')->constrained();
            $table->foreignId('module_id')->constrained();
            $table->primary(['package_id', 'module_id']);
        });

        Schema::create('license_module', function (Blueprint $table) {
            $table->foreignId('license_id')->constrained();
            $table->foreignId('module_id')->constrained();
            $table->foreignId('granted_by')->nullable()->constrained('users');
            $table->timestamp('created_at')->nullable();
            $table->primary(['license_id', 'module_id']);
        });

        Schema::table('licenses', function (Blueprint $table) {
            $table->foreignId('package_id')->nullable()->after('product_id')->constrained();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('demo_package_id')->nullable()->after('key_prefix')->constrained('packages');
        });

        $panopos = DB::table('products')->where('code', 'PANOPOS')->value('id');
        if ($panopos) {
            $now = now();
            foreach ([['LITE', 'Lite'], ['PANO', 'Pano'], ['RESTAURANT', 'Restaurant'], ['PRO', 'Pro']] as $i => [$code, $name]) {
                DB::table('packages')->insert([
                    'product_id' => $panopos, 'code' => $code, 'name' => $name,
                    'default_device_limit' => 1, 'sort_order' => ($i + 1) * 10, 'is_active' => true,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $pro = DB::table('packages')->where('product_id', $panopos)->where('code', 'PRO')->value('id');
            DB::table('products')->where('id', $panopos)->update(['demo_package_id' => $pro]);
            DB::table('licenses')->where('product_id', $panopos)->whereNull('package_id')->update(['package_id' => $pro]);
        }
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropConstrainedForeignId('demo_package_id'));
        Schema::table('licenses', fn (Blueprint $t) => $t->dropConstrainedForeignId('package_id'));
        Schema::dropIfExists('license_module');
        Schema::dropIfExists('package_module');
        Schema::dropIfExists('modules');
        Schema::dropIfExists('packages');
    }
};
