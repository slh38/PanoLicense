<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Lisanslanan yazılımlar. Yeni ürün eklemek kod değil kayıt işidir. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            // Lisans anahtarının ön eki (ör. PAN-XXXX-XXXX-XXXX)
            $table->string('key_prefix', 5);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // İlk ürün: referans verisi (müşteri verisi değildir).
        DB::table('products')->insert([
            'code' => 'PANOPOS',
            'name' => 'PanoPOS',
            'key_prefix' => 'PAN',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
