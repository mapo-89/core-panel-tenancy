<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('color', 7)->default('#6366F1');
            $table->softDeletesTz();
            $table->timestampsTz();
        });

        Schema::create('user_group_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_group_id')->constrained('user_groups')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestampsTz();
            $table->unique(['user_group_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_group_user');
        Schema::dropIfExists('user_groups');
    }
};
