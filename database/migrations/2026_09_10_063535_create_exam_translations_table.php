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
        Schema::create('exam_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('short_name');
            $table->string('full_name');
            $table->string('conducting_body')->nullable();
            $table->timestamps();

            $table->unique(['exam_id', 'locale']);
        });

        $defaultLocale = config('app.locale');
        $now = now();

        DB::table('exams')->select('id', 'short_name', 'full_name', 'conducting_body')->orderBy('id')
            ->each(function ($exam) use ($defaultLocale, $now) {
                DB::table('exam_translations')->insert([
                    'exam_id' => $exam->id,
                    'locale' => $defaultLocale,
                    'short_name' => $exam->short_name,
                    'full_name' => $exam->full_name,
                    'conducting_body' => $exam->conducting_body,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });

        Schema::table('exams', function (Blueprint $table) {
            $table->dropColumn(['short_name', 'full_name', 'conducting_body']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->string('short_name')->nullable()->after('exam_slug');
            $table->string('full_name')->nullable()->after('short_name');
            $table->string('conducting_body')->nullable()->after('full_name');
        });

        $defaultLocale = config('app.locale');

        DB::table('exam_translations')->where('locale', $defaultLocale)
            ->orderBy('id')
            ->each(function ($translation) {
                DB::table('exams')->where('id', $translation->exam_id)->update([
                    'short_name' => $translation->short_name,
                    'full_name' => $translation->full_name,
                    'conducting_body' => $translation->conducting_body,
                ]);
            });

        Schema::dropIfExists('exam_translations');
    }
};
