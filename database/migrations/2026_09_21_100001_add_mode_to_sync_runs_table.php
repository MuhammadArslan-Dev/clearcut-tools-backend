<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_runs', function (Blueprint $table) {
            // 'incremental' (only new/changed rows) or 'full' (rewrite every row).
            $table->string('mode', 20)->default('incremental');
            // Report what would change without writing anything.
            $table->boolean('dry_run')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->dropColumn(['mode', 'dry_run']);
        });
    }
};
