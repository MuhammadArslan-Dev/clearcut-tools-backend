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
        Schema::create('tool_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tool_id')->constrained('tools')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('tool_name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['tool_id', 'locale']);
        });

        // tools.tool_name/description already held live client data before
        // this migration existed — carry it forward as the default-locale
        // translation instead of losing it, then drop the now-redundant
        // columns from the locale-agnostic base table.
        $defaultLocale = config('app.locale');
        $now = now();

        DB::table('tools')->select('id', 'tool_name', 'description')->orderBy('id')
            ->each(function ($tool) use ($defaultLocale, $now) {
                DB::table('tool_translations')->insert([
                    'tool_id' => $tool->id,
                    'locale' => $defaultLocale,
                    'tool_name' => $tool->tool_name,
                    'description' => $tool->description,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });

        Schema::table('tools', function (Blueprint $table) {
            $table->dropColumn(['tool_name', 'description']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tools', function (Blueprint $table) {
            $table->string('tool_name')->nullable()->after('tool_slug');
            $table->text('description')->nullable()->after('tool_name');
        });

        $defaultLocale = config('app.locale');

        DB::table('tool_translations')->where('locale', $defaultLocale)
            ->orderBy('id')
            ->each(function ($translation) {
                DB::table('tools')->where('id', $translation->tool_id)->update([
                    'tool_name' => $translation->tool_name,
                    'description' => $translation->description,
                ]);
            });

        Schema::dropIfExists('tool_translations');
    }
};
