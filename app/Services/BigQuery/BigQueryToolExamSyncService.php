<?php

namespace App\Services\BigQuery;

use App\Models\Exam;
use App\Models\Tool;
use App\Models\ToolCategory;
use App\Models\ToolExam;
use App\Services\BigQuery\Concerns\DecodesLocaleJson;
use App\Services\BigQuery\Concerns\SyncsByUid;
use Illuminate\Support\Facades\DB;

/**
 * Syncs the `tools_exam_mapping` BigQuery table (note the `tools_` prefix — the
 * BigQuery table names differ from this app's `tool_exam_*` step keys) into this app's
 * `tool_exams` pivot table — the BigQuery counterpart of
 * GoogleSheets\SheetSyncService::syncToolExams(). Rows are matched by
 * `uid` (TE_0001, ...), see Concerns\SyncsByUid. Depends on `tools`,
 * `exams` and `tool_categories` having been synced.
 *
 * Source columns:
 *   uid
 *   tool_uid, exam_uid   parents, resolved by uid (slug only as a fallback
 *                        when the uid cell is blank)
 *   category_uid         optional; a category that can't be resolved drops
 *                        the link (row still saved) and is warned about
 *   tool_slug, exam_slug, category_slug   readable copies / fallback keys
 *   public_slug          the exam's page URL in the tools frontend
 *   is_active            bool / "TRUE"/"FALSE"
 *   sort_order           numeric
 *   popular_rank         optional integer; 1 = first "Popular" exam, blank = not popular
 *
 * A changed public_slug on an existing row moves that page's URL, so it is
 * reported in `url_changes`.
 */
class BigQueryToolExamSyncService
{
    use DecodesLocaleJson;
    use SyncsByUid;

    protected const QUERY = 'SELECT * FROM `clear-cutoff-435016.content.tools_exam_mapping`';

    public function __construct(protected BigQueryClient $client) {}

    /**
     * @return array<string, mixed>
     */
    public function sync(string $mode = self::MODE_INCREMENTAL, bool $dryRun = false): array
    {
        $this->assertMode($mode);
        $this->resetState();

        $rows = $this->client->runQuery(self::QUERY);
        $this->requireUidColumn($rows, 'tool_exam_mapping');

        $stats = $this->emptyStats();
        $entries = [];

        $toolIdsByUid = Tool::query()->whereNotNull('uid')->pluck('id', 'uid');
        $toolIdsBySlug = Tool::query()->pluck('id', 'tool_slug');
        $examIdsByUid = Exam::query()->whereNotNull('uid')->pluck('id', 'uid');
        $examIdsBySlug = Exam::query()->pluck('id', 'exam_slug');
        $categories = ToolCategory::query()->get(['id', 'uid', 'tool_id', 'category_slug']);
        $categoryIdsByUid = $categories->whereNotNull('uid')->pluck('id', 'uid');
        $categoryIdsByToolAndSlug = $categories->mapWithKeys(fn ($c) => [$c->tool_id.'|'.$c->category_slug => $c->id]);

        foreach ($rows as $i => $row) {
            $uid = $this->cleanString($row['uid'] ?? null);
            $label = "row {$i}".($uid ? " ({$uid})" : '');

            if (! $uid) {
                $this->warnings[] = "{$label}: missing uid — row skipped";
                $stats['skipped']++;

                continue;
            }

            $toolId = $this->resolveRef(
                $toolIdsByUid,
                $toolIdsBySlug,
                $this->cleanString($row['tool_uid'] ?? null),
                $this->cleanString($row['tool_slug'] ?? null),
                'tool',
                $label,
            );

            $examId = $this->resolveRef(
                $examIdsByUid,
                $examIdsBySlug,
                $this->cleanString($row['exam_uid'] ?? null),
                $this->cleanString($row['exam_slug'] ?? null),
                'exam',
                $label,
            );

            if ($toolId === null || $examId === null) {
                $stats['skipped']++;

                continue;
            }

            // Category is optional. Its slug is only meaningful within a
            // tool, so the slug fallback is keyed by tool_id|category_slug.
            $categorySlug = $this->cleanString($row['category_slug'] ?? null);
            $categoryBySlug = collect();

            if ($categorySlug !== null && $categoryIdsByToolAndSlug->has($toolId.'|'.$categorySlug)) {
                $categoryBySlug = collect([$categorySlug => $categoryIdsByToolAndSlug->get($toolId.'|'.$categorySlug)]);
            }

            $categoryId = $this->resolveRef(
                $categoryIdsByUid,
                $categoryBySlug,
                $this->cleanString($row['category_uid'] ?? null),
                $categorySlug,
                'category',
                $label,
                required: false,
            );

            $publicSlug = $this->cleanString($row['public_slug'] ?? null)
                ?? $this->cleanString($row['exam_slug'] ?? null)
                ?? $uid;
            $isActive = $this->normalizeBool($row['is_active'] ?? null, true);
            $sortOrder = (int) ($row['sort_order'] ?? 0);
            $rankRaw = $this->cleanString($row['popular_rank'] ?? null);
            $popularRank = $rankRaw !== null && is_numeric($rankRaw) && (int) $rankRaw > 0 ? (int) $rankRaw : null;

            $entries[] = [
                'uid' => $uid,
                'label' => $label,
                'key' => $publicSlug,
                'attrs' => [
                    'tool_id' => $toolId,
                    'exam_id' => $examId,
                    'tool_category_id' => $categoryId,
                    'public_slug' => $publicSlug,
                    'is_active' => $isActive,
                    'sort_order' => $sortOrder,
                    'popular_rank' => $popularRank,
                ],
                'hash' => $this->hashOf([
                    'uid' => $uid,
                    'tool_id' => $toolId,
                    'exam_id' => $examId,
                    'tool_category_id' => $categoryId,
                    'public_slug' => $publicSlug,
                    'is_active' => $isActive,
                    'sort_order' => $sortOrder,
                    'popular_rank' => $popularRank,
                ]),
                'legacy' => ['tool_id' => $toolId, 'exam_id' => $examId],
                'unique' => [
                    ['tool_id' => $toolId, 'exam_id' => $examId],
                    ['tool_id' => $toolId, 'public_slug' => $publicSlug],
                ],
            ];
        }

        $todo = $this->plan(ToolExam::class, $entries, $mode, $stats);

        foreach ($todo as $item) {
            $old = $item['existing']?->public_slug;

            if ($old !== null && $old !== $item['attrs']['public_slug']) {
                $this->urlChanges[] = "tool_exam {$item['uid']}: public_slug '{$old}' → '{$item['attrs']['public_slug']}' — the exam page URL changes";
            }
        }

        if ($dryRun || empty($todo)) {
            return $this->result($stats, $mode, $dryRun, $todo);
        }

        DB::transaction(function () use ($todo) {
            $this->apply(ToolExam::class, $todo);
        });

        return $this->result($stats, $mode, $dryRun, $todo);
    }
}
