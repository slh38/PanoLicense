<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->text('public_key')->nullable();
            $table->char('public_key_hash', 64)->nullable();
            $table->unique(['license_id', 'public_key_hash']);
            $table->index('public_key_hash');
        });
        Schema::create('device_challenges', function (Blueprint $table) {
            $table->char('id', 64)->primary();
            $table->string('operation', 10);
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('license_key', 40);
            $table->char('fingerprint_hash', 64);
            $table->char('public_key_hash', 64);
            $table->text('message');
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_challenges');
        Schema::table('devices', function (Blueprint $table) {
            $table->dropUnique(['license_id', 'public_key_hash']);
            $table->dropIndex(['public_key_hash']);
            $table->dropColumn(['public_key', 'public_key_hash']);
        });
    }
};