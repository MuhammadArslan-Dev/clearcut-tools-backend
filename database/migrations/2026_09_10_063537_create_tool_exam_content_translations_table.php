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
        // EAV rather than a jsonb blob per locale, by design: every tool
        // type's translatable prose (resizer's officialRequirements text,
        // age-calculator's FAQs/notes/category names, whatever the next
        // tool adds) shows up here as its own row/key instead of a
        // duplicated per-language JSON document — real columns, filterable
        // and indexable per field, no migration needed for a new tool's
        // translatable fields (same flexibility jsonb was chosen for
        // originally, without losing relational structure for the actual
        // translated strings). field_key is an app-level convention (e.g.
        // "faqs.0.question", "official_body") — not FK-enforced, since the
        // whole point is that new keys never require a migration.
        Schema::create('tool_exam_content_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tool_exam_id')->constrained('tool_exams')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('field_key');
            $table->text('field_value')->nullable();
            $table->timestamps();

            $table->unique(['tool_exam_id', 'locale', 'field_key']);
            $table->index(['tool_exam_id', 'locale']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tool_exam_content_translations');
    }
};
