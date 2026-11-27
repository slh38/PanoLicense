<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            // Mevcut kayıtlar da varsayılan standart politikaya geçer.
            $table->unsignedSmallInteger('offline_grace_days')->default(7);
        });
    }

    public function down(): void
    {
        Schema::table('licenses', fn (Blueprint $table) => $table->dropColumn('offline_grace_days'));
    }
};