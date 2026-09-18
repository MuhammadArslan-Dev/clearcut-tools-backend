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
        Schema::create('tool_category_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tool_category_id')->constrained('tool_categories')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('label');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['tool_category_id', 'locale']);
        });

        // icon and sort_order/is_active stay on tool_categories — they're
        // not text, so there's nothing language-specific about them.
        $defaultLocale = config('app.locale');
        $now = now();

        DB::table('tool_categories')->select('id', 'label', 'description')->orderBy('id')
            ->each(function ($category) use ($defaultLocale, $now) {
                DB::table('tool_category_translations')->insert([
                    'tool_category_id' => $category->id,
                    'locale' => $defaultLocale,
                    'label' => $category->label,
                    'description' => $category->description,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });

        Schema::table('tool_categories', function (Blueprint $table) {
            $table->dropColumn(['label', 'description']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tool_categories', function (Blueprint $table) {
            $table->string('label')->nullable()->after('category_slug');
            $table->text('description')->nullable()->after('icon');
        });

        $defaultLocale = config('app.locale');

        DB::table('tool_category_translations')->where('locale', $defaultLocale)
            ->orderBy('id')
            ->each(function ($translation) {
                DB::table('tool_categories')->where('id', $translation->tool_category_id)->update([
                    'label' => $translation->label,
                    'description' => $translation->description,
                ]);
            });

        Schema::dropIfExists('tool_category_translations');
    }
};
