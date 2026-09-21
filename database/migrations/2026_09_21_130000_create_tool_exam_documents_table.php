<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which documents an exam asks for (photo, signature, left thumb,
     * handwritten declaration, ...) and each one's upload spec — one row per
     * (tool_exam, document type), synced from the tools_exam_documents sheet.
     *
     * Kept as rows rather than a JSON blob so every sheet row has its own
     * uid/content_hash (incremental sync), (tool_exam_id, doc_type) can be
     * unique, and verification status is tracked per document.
     *
     * A document dropped from the sheet is deactivated (is_active = false),
     * never deleted, so the API stops serving it but it can be brought back.
     */
    public function up(): void
    {
        Schema::create('tool_exam_documents', function (Blueprint $table) {
            $table->id();
            $table->string('uid', 40)->unique();
            $table->string('content_hash', 64)->nullable();
            $table->foreignId('tool_exam_id')->constrained('tool_exams')->cascadeOnDelete();
            $table->string('doc_type', 40);
            $table->unsignedSmallInteger('sort_order')->default(0);
            // upload | live_capture (the portal captures it; nothing to resize)
            $table->string('mode', 20)->default('upload');
            // Nullable: many official notices give KB only, or cm instead of px.
            $table->unsignedSmallInteger('width_px')->nullable();
            $table->unsignedSmallInteger('height_px')->nullable();
            $table->unsignedInteger('min_kb')->nullable();
            $table->unsignedInteger('max_kb')->nullable();
            $table->string('format', 10)->default('jpg');
            $table->boolean('is_required')->default(true);
            // unverified | partly_verified | official_verified
            $table->string('verification', 20)->default('unverified');
            $table->text('source_url')->nullable();
            $table->date('verified_on')->nullable();
            // Internal editor note (not shown to users).
            $table->text('note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tool_exam_id', 'doc_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_exam_documents');
    }
};
