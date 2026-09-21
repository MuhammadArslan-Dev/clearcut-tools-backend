<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * popular_rank orders the "Popular" exams a tool page highlights (the
     * age calculator hub's Popular Calculators cards and the Popular badge).
     * 1 = shown first; NULL = not popular. Set from the popular_rank column
     * of the tools_exam_mapping sheet.
     */
    public function up(): void
    {
        Schema::table('tool_exams', function (Blueprint $table) {
            $table->unsignedSmallInteger('popular_rank')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tool_exams', function (Blueprint $table) {
            $table->dropColumn('popular_rank');
        });
    }
};
