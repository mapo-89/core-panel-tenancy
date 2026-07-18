<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authentication_logs', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('user_id')->nullable()->index();
            $table->string('login')->nullable()->index();
            $table->string('guard')->nullable()->index();
            $table->string('session_id')->nullable()->index();
            $table->string('device_name')->nullable();
            $table->string('device_type')->nullable();
            $table->string('browser')->nullable();
            $table->string('platform')->nullable();
            $table->string('ip_address', 45)->nullable()->index();
            $table->boolean('login_successful')->index();
            $table->timestampTz('login_at')->nullable()->index();
            $table->timestampTz('logout_at')->nullable()->index();
            $table->timestampTz('last_active_at')->nullable()->index();
            $table->json('properties')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authentication_logs');
    }
};
