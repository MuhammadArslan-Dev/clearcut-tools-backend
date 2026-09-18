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

        DB::transaction(function () use ($rows, &$stats) {
            foreach ($rows as $i => $row) {
                $toolSlug = $row['tool_slug'] ?? null;
                $examSlug = $row['exam_slug'] ?? null;

                if (! $toolSlug || ! $examSlug) {
                    $this->warnings[] = "row {$i}: missing tool_slug or exam_slug";
                    $stats['skipped']++;

                    continue;
                }

                $tool = Tool::where('tool_slug', $toolSlug)->first();

                if (! $tool) {
                    $this->warnings[] = "row {$i}: tool_slug '{$toolSlug}' not found — sync tools first, row skipped";
                    $stats['skipped']++;

                    continue;
                }

                $exam = Exam::where('exam_slug', $examSlug)->first();

                if (! $exam) {
                    $this->warnings[] = "row {$i}: exam_slug '{$examSlug}' not found — sync exams first, row skipped";
                    $stats['skipped']++;

                    continue;
                }

                $categorySlug = $row['category_slug'] ?? null;
                $categoryId = null;

                if ($categorySlug) {
                    $category = ToolCategory::where('tool_id', $tool->id)
                        ->where('category_slug', $categorySlug)
                        ->first();

                    if ($category) {
                        $categoryId = $category->id;
                    } else {
                        $this->warnings[] = "row {$i}: category_slug '{$categorySlug}' not found for tool '{$toolSlug}' — saved without a category";
                    }
                }

                $toolExam = ToolExam::updateOrCreate(
                    ['tool_id' => $tool->id, 'exam_id' => $exam->id],
                    [
                        'tool_category_id' => $categoryId,
                        'public_slug' => $row['public_slug'] ?? $examSlug,
                        'is_active' => $this->normalizeBool($row['is_active'] ?? null, true),
                        'sort_order' => (int) ($row['sort_order'] ?? 0),
                    ],
                );

                $stats[$toolExam->wasRecentlyCreated ? 'created' : 'updated']++;
            }
        });

        return [
            'stats' => $stats,
            'warnings' => $this->warnings,
        ];
    }
}
