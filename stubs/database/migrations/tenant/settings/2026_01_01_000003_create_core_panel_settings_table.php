<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('group');
            $table->string('key');
            $table->jsonb('value_json')->nullable();
            $table->string('type');
            $table->boolean('is_public')->default(false);
            $table->boolean('is_localized')->default(false);
            $table->timestampsTz();

            $table->unique(['group', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
