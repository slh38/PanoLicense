<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demo akışı: PanoPOS kurulumunda demo seçilince lisans "demo" durumunda oluşur (14 gün).
 * Panelden onaylanınca "active" olur. Kurulumu yapan personel ve onaylayan ayrı tutulur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            // panel: panelden verildi · demo: PanoPOS kurulumundan geldi
            $table->string('source', 20)->default('panel')->after('status');

            // Demo kaydında henüz panel kullanıcısı yok; onaylayan sonradan yazılır.
            $table->foreignId('created_by')->nullable()->change();

            // Kurulumu yapan: PanoPOS'a yazılan kullanıcı adı (ham) ve eşleşen panel kullanıcısı.
            // İkisi de boşsa kurulumu son kullanıcı yapmıştır.
            $table->string('installer_username', 50)->nullable()->after('created_by');
            $table->foreignId('installed_by')->nullable()->after('installer_username')->constrained('users')->restrictOnDelete();

            $table->foreignId('approved_by')->nullable()->after('installed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');

            $table->index(['status', 'created_at']);
        });

        Schema::table('customers', function (Blueprint $table) {
            // Demo ile gelen firmayı panel kullanıcısı değil son kullanıcı oluşturur.
            $table->foreignId('created_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn('approved_at');
            $table->dropConstrainedForeignId('installed_by');
            $table->dropColumn(['installer_username', 'source']);
        });
    }
};
