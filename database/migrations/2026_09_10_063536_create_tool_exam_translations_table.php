<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tool_exam_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tool_exam_id')->constrained('tool_exams')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            // Per-locale, not per-row: the English copy can be real/final
            // while a translation is still pending — this used to live on
            // tool_exam_data, which only had room for one flag total.
            $table->boolean('is_placeholder')->default(false);
            $table->timestamps();

            $table->unique(['tool_exam_id', 'locale']);
        });

        $defaultLocale = config('app.locale');
        $now = now();

        DB::table('tool_exam_data')->select('tool_exam_id', 'seo_title', 'seo_description', 'is_placeholder')->orderBy('id')
            ->each(function ($data) use ($defaultLocale, $now) {
                DB::table('tool_exam_translations')->insert([
                    'tool_exam_id' => $data->tool_exam_id,
                    'locale' => $defaultLocale,
                    'seo_title' => $data->seo_title,
                    'seo_description' => $data->seo_description,
                    'is_placeholder' => $data->is_placeholder,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });

        Schema::table('tool_exam_data', function (Blueprint $table) {
            $table->dropColumn(['seo_title', 'seo_description', 'is_placeholder']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tool_exam_data', function (Blueprint $table) {
            $table->string('seo_title')->nullable()->after('tool_exam_id');
            $table->text('seo_description')->nullable()->after('seo_title');
            $table->boolean('is_placeholder')->default(false)->after('seo_description');
        });

        $defaultLocale = config('app.locale');

        DB::table('tool_exam_translations')->where('locale', $defaultLocale)
            ->orderBy('id')
            ->each(function ($translation) {
                DB::table('tool_exam_data')->where('tool_exam_id', $translation->tool_exam_id)->update([
                    'seo_title' => $translation->seo_title,
                    'seo_description' => $translation->seo_description,
                    'is_placeholder' => $translation->is_placeholder,
                ]);
            });

        Schema::dropIfExists('tool_exam_translations');
    }
};
