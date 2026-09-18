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
        Schema::create('tool_exam_data', function (Blueprint $table) {
            $table->id();
            // One data row per tool+exam pairing — tool_exams already
            // enforces the (tool, exam) uniqueness this depends on, so a
            // single FK here (rather than duplicating tool_id/exam_id) is
            // enough to keep this 1:1 (sheet-structure.html §5, tree diagram).
            $table->foreignId('tool_exam_id')->unique()->constrained('tool_exams')->cascadeOnDelete();
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->boolean('is_placeholder')->default(false);
            // jsonb (not json): the whole point of this table is that a new
            // tool's shape (resizer's photoSpec/signatureSpec vs age
            // calculator's categories/relaxations — see the two data_json
            // examples in sheet-structure.html §5) never needs a migration;
            // jsonb also gets indexable/queryable storage on Postgres that
            // plain json does not.
            $table->jsonb('data_json')->default('{}');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tool_exam_data');
    }
};
