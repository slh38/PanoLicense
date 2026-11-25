<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lisansa bağlı cihazlar (aktivasyonlar). Aktif cihaz sayısı lisansın device_limit değerini aşamaz;
 * kontrol lisans satırı kilitlenerek tek transaction'da yapılır. Kayıt silinmez, serbest bırakılır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_id')->constrained()->restrictOnDelete();
            // İstemcinin donanım kimliğinden ürettiği SHA-256 (hex). Ham donanım bilgisi gelmez/saklanmaz.
            $table->char('fingerprint_hash', 64);
            $table->string('name', 100)->nullable();
            $table->string('app_version', 30)->nullable();
            $table->string('status', 20)->default('active');
            $table->string('last_ip', 45)->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['license_id', 'fingerprint_hash']);
            $table->index('fingerprint_hash');
            $table->index(['license_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
