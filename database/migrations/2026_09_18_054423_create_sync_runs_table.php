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
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            // Which sync this run is: 'all', or one of the individual
            // BigQuery content sync keys (exams/tools/tool_categories/...).
            $table->string('kind');
            $table->string('status')->default('pending'); // pending|running|completed|failed
            // Ordered list of {key, label, status, result, error} — the
            // admin UI polls this to render live per-step progress.
            $table->json('steps');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
