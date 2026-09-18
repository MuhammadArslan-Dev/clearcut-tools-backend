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
        Schema::create('tool_exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tool_id')->constrained('tools')->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();
            // Nullable: category is tool-specific (see tool_categories) and
            // may briefly be unset mid-sync before the sheet's category_slug
            // resolves to a row, so this must survive that state rather than
            // block the whole tool_exams sync on it.
            $table->foreignId('tool_category_id')->nullable()->constrained('tool_categories')->nullOnDelete();
            $table->string('public_slug');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            // One row per (tool, exam) — this is the mapping sheet's whole
            // purpose (sheet-structure.html §4).
            $table->unique(['tool_id', 'exam_id']);
            // The public URL (/api/v1/tools/{tool}/exams/{public_slug}) must
            // resolve to exactly one exam within a given tool.
            $table->unique(['tool_id', 'public_slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tool_exams');
    }
};
