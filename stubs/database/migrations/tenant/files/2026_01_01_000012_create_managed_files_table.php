<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('managed_files', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('folder_id')->nullable()->index();
            $table->string('name');
            $table->string('collection')->default('files');
            $table->string('disk')->default('public');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('extension')->nullable();
            $table->jsonb('meta_json')->nullable();
            $table->string('uploaded_by')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_files');
    }
};
