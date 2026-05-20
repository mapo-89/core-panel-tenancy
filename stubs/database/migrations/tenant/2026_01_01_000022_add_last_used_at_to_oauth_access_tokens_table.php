<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('oauth_access_tokens', 'last_used_at')) {
            Schema::table('oauth_access_tokens', function (Blueprint $table) {
                $table->timestamp('last_used_at')->nullable()->after('updated_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('oauth_access_tokens', 'last_used_at')) {
            Schema::table('oauth_access_tokens', function (Blueprint $table) {
                $table->dropColumn('last_used_at');
            });
        }
    }
};
