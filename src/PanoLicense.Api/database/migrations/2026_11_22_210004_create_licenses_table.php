<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Firma + ürün bazlı lisans. Fiziksel silinmez: iptal edilir (status=revoked) ve kim/ne zaman/neden tutulur.
 * Kuralı: bir firma + ürün için aynı anda tek aktif lisans (active_key üzerinde unique index).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licenses', function (Blueprint $table) {
            $table->id();
            $table->string('license_key', 40)->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('device_limit');
            $table->date('starts_at');
            $table->date('expires_at');
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();

            // Lisansı kim verdi (Admin veya User)
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();

            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('revoke_reason', 500)->nullable();
            $table->timestamps();

            // MySQL'de kısmi index yok: yalnız aktif lisanslarda dolu olan üretilmiş sütun.
            $table->string('active_key', 50)->nullable()
                ->storedAs("IF(`status` = 'active', CONCAT(`customer_id`, '-', `product_id`), NULL)");
            $table->unique('active_key');

            $table->index(['status', 'expires_at']);
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licenses');
    }
};
