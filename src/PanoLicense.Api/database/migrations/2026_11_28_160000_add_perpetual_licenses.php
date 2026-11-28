<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->string('license_type', 20)->default('term');
            $table->date('expires_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Süresiz kayıtları yapay tarihe çevirmek veya geçmişi silmek yasaktır.
        if (DB::table('licenses')->whereNull('expires_at')->exists()) {
            throw new RuntimeException('Null bitişli lisans varken migration geri alınamaz.');
        }
        Schema::table('licenses', function (Blueprint $table) {
            $table->date('expires_at')->nullable(false)->change();
            $table->dropColumn('license_type');
        });
    }
};
