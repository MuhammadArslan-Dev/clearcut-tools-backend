<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stable row identity for the BigQuery/sheet syncs.
     *
     * `uid` is the id written in the sheet's first column (T_0001, E_0001,
     * TC_0001, TE_0001, TEC_0001). It is what a sync matches on, so a row
     * whose slug/name is edited in the sheet updates the same DB row
     * instead of creating a new one. `content_hash` is a sha256 of the
     * row's synced fields, letting an incremental sync skip rows that did
     * not change.
     *
     * Both are nullable: rows created before this migration have neither
     * until the next sync adopts them (matched by their old natural key).
     * Postgres/SQLite unique indexes allow many NULLs.
     */
    protected const TABLES = ['tools', 'exams', 'tool_categories', 'tool_exams', 'tool_exam_data'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('uid', 40)->nullable()->unique();
                $t->string('content_hash', 64)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropUnique("{$table}_uid_unique");
                $t->dropColumn(['uid', 'content_hash']);
            });
        }
    }
};
