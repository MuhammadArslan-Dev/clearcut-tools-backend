<?php

namespace App\Services\BigQuery;

use App\Models\Exam;
use App\Models\Tool;
use App\Models\ToolCategory;
use App\Models\ToolExam;
use App\Services\BigQuery\Concerns\DecodesLocaleJson;
use Illuminate\Support\Facades\DB;

/**
 * Syncs the `tool_exam_mapping` BigQuery table into this app's
 * `tool_exams` pivot table — the BigQuery counterpart of
 * GoogleSheets\SheetSyncService::syncToolExams(). Depends on `tools`,
 * `exams`, and `tool_categories` having already been synced.
 *
 * Confirmed live schema (clear-cutoff-435016.content.tool_exam_mapping):
 *   tool_slug       plain string
 *   exam_slug       plain string
 *   category_slug   plain string — optional; a category link is dropped
 *                   (not fatal) if it can't be resolved for this tool
 *   public_slug     plain string
 *   is_active       bool / "TRUE"/"FALSE"
 *   sort_order      numeric
 *
 * No per-locale fields here — one `ToolExam` row per (tool, exam) pair.
 *
 * Tool/exam/category lookups are preloaded into slug-keyed maps up front
 * (3 queries total) instead of a `where(...)->first()` per row, and the
 * resulting rows are written with one `ToolExam::upsert()` instead of
 * one `updateOrCreate()` per row — see BigQueryExamSyncService's docblock
 * for why (a remote, production-shared DB makes per-row round trips the
 * dominant cost, not BigQuery or PHP time).
 */
class BigQueryToolExamSyncService
{
    use DecodesLocaleJson;

    protected const QUERY = 'SELECT * FROM `clear-cutoff-435016.content.tool_exam_mapping`';

    /** @var array<int, string> */
    protected array $warnings = [];

    public function __construct(protected BigQueryClient $client) {}

    /**
     * @return array{stats: array{created: int, updated: int, skipped: int}, warnings: array<int, string>}
     */
    public function sync(): array
    {
        $this->warnings = [];
        $rows = $this->client->runQuery(self::QUERY);
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        $toolIdsBySlug = Tool::pluck('id', 'tool_slug');
        $examIdsBySlug = Exam::pluck('id', 'exam_slug');
        $categoryIdsByToolAndSlug = ToolCategory::get(['id', 'tool_id', 'category_slug'])
            ->keyBy(fn ($c) => $c->tool_id.'|'.$c->category_slug);

        $mappingRows = [];

        foreach ($rows as $i => $row) {
            $toolSlug = $row['tool_slug'] ?? null;
            $examSlug = $row['exam_slug'] ?? null;

            if (! $toolSlug || ! $examSlug) {
                $this->warnings[] = "row {$i}: missing tool_slug or exam_slug";
                $stats['skipped']++;

                continue;
            }

            $toolId = $toolIdsBySlug[$toolSlug] ?? null;

            if (! $toolId) {
                $this->warnings[] = "row {$i}: tool_slug '{$toolSlug}' not found — sync tools first, row skipped";
                $stats['skipped']++;

                continue;
            }

            $examId = $examIdsBySlug[$examSlug] ?? null;

            if (! $examId) {
                $this->warnings[] = "row {$i}: exam_slug '{$examSlug}' not found — sync exams first, row skipped";
                $stats['skipped']++;

                continue;
            }

            $categorySlug = $row['category_slug'] ?? null;
            $categoryId = null;

            if ($categorySlug) {
                $category = $categoryIdsByToolAndSlug->get($toolId.'|'.$categorySlug);

                if ($category) {
                    $categoryId = $category->id;
                } else {
                    $this->warnings[] = "row {$i}: category_slug '{$categorySlug}' not found for tool '{$toolSlug}' — saved without a category";
                }
            }

            // Keyed by tool_id|exam_id so a slug pair repeated in the
            // source collapses to one upsert row instead of erroring on a
            // duplicate ON CONFLICT target.
            $mappingRows[$toolId.'|'.$examId] = [
                'tool_id' => $toolId,
                'exam_id' => $examId,
                'tool_category_id' => $categoryId,
                'public_slug' => $row['public_slug'] ?? $examSlug,
                'is_active' => $this->normalizeBool($row['is_active'] ?? null, true),
                'sort_order' => (int) ($row['sort_order'] ?? 0),
            ];
        }

        if (empty($mappingRows)) {
            return ['stats' => $stats, 'warnings' => $this->warnings];
        }

        DB::transaction(function () use ($mappingRows, &$stats) {
            $now = now();

            $existingPairs = ToolExam::query()
                ->get(['tool_id', 'exam_id'])
                ->map(fn ($t) => $t->tool_id.'|'.$t->exam_id)
                ->flip();

            $upsertRows = [];

            foreach ($mappingRows as $key => $row) {
                $stats[$existingPairs->has($key) ? 'updated' : 'created']++;

                $upsertRows[] = [...$row, 'created_at' => $now, 'updated_at' => $now];
            }

            ToolExam::query()->upsert(
                $upsertRows,
                ['tool_id', 'exam_id'],
                ['tool_category_id', 'public_slug', 'is_active', 'sort_order', 'updated_at'],
            );
        });

        return [
            'stats' => $stats,
            'warnings' => $this->warnings,
        ];
    }
}
