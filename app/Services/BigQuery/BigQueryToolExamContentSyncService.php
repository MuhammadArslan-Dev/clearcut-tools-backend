<?php

namespace App\Services\BigQuery;

use App\Models\Exam;
use App\Models\Tool;
use App\Models\ToolExam;
use App\Models\ToolExamData;
use App\Models\ToolExamTranslation;
use App\Services\BigQuery\Concerns\DecodesLocaleJson;
use App\Services\BigQuery\Concerns\SyncsByUid;
use Illuminate\Support\Facades\DB;

/**
 * Syncs the `tools_exam_content` BigQuery table (note the `tools_` prefix — the
 * BigQuery table names differ from this app's `tool_exam_*` step keys) into this app's
 * `tool_exam_data` + `tool_exam_translations` tables — the BigQuery
 * counterpart of GoogleSheets\SheetSyncService::syncToolExamData().
 * Rows are matched by `uid` (TEC_0001, ...), see Concerns\SyncsByUid.
 *
 * Depends on `tool_exam_mapping` having been synced: the row's tool_exam is
 * resolved by `mapping_uid` (falling back to tool_slug + exam_slug only when
 * mapping_uid is blank).
 *
 * Source columns:
 *   uid, mapping_uid, tool_slug, exam_slug
 *   seo_title_{loc}, seo_description_{loc}   flat per-locale SEO columns.
 *                   Suffixes: "eng" (stored as "en"), "hi", "mr", and both
 *                   "pa" and "pun" for Punjabi (each stored as written —
 *                   the sheet uses "pa", older exports used "pun").
 *   is_placeholder  bool / "TRUE"/"FALSE" — one flag, written to every
 *                   locale's translation row
 *   data_json       JSON string (photoSpec/signatureSpec/officialRequirements),
 *                   stored as-is on tool_exam_data, not per-locale
 *
 * `data_json` is `jsonb`, and `upsert()` skips Eloquent's `array` cast, so
 * it is json_encode()d by hand here.
 */
class BigQueryToolExamContentSyncService
{
    use DecodesLocaleJson;
    use SyncsByUid;

    protected const QUERY = 'SELECT * FROM `clear-cutoff-435016.content.tools_exam_content`';

    // BigQuery column locale suffix => this app's locale value.
    protected const LOCALE_SUFFIXES = [
        'eng' => 'en',
        'en' => 'en',
        'hi' => 'hi',
        'mr' => 'mr',
        'pa' => 'pa',
        'pun' => 'pun',
    ];

    public function __construct(protected BigQueryClient $client) {}

    /**
     * @return array<string, mixed>
     */
    public function sync(string $mode = self::MODE_INCREMENTAL, bool $dryRun = false): array
    {
        $this->assertMode($mode);
        $this->resetState();

        $rows = $this->client->runQuery(self::QUERY);
        $this->requireUidColumn($rows, 'tool_exam_content');

        $stats = $this->emptyStats();
        $entries = [];

        $toolIdsBySlug = Tool::query()->pluck('id', 'tool_slug');
        $examIdsBySlug = Exam::query()->pluck('id', 'exam_slug');
        $toolExams = ToolExam::query()->get(['id', 'uid', 'tool_id', 'exam_id']);
        $toolExamIdsByUid = $toolExams->whereNotNull('uid')->pluck('id', 'uid');
        $toolExamIdsByPair = $toolExams->mapWithKeys(fn ($te) => [$te->tool_id.'|'.$te->exam_id => $te->id]);

        foreach ($rows as $i => $row) {
            $uid = $this->cleanString($row['uid'] ?? null);
            $label = "row {$i}".($uid ? " ({$uid})" : '');

            if (! $uid) {
                $this->warnings[] = "{$label}: missing uid — row skipped";
                $stats['skipped']++;

                continue;
            }

            $mappingUid = $this->cleanString($row['mapping_uid'] ?? null);
            $toolSlug = $this->cleanString($row['tool_slug'] ?? null);
            $examSlug = $this->cleanString($row['exam_slug'] ?? null);

            // Slug fallback only when mapping_uid is blank: build the
            // one-entry slug lookup from the tool_slug + exam_slug pair.
            $toolExamBySlug = collect();

            if ($mappingUid === null && $toolSlug !== null && $examSlug !== null) {
                $toolId = $toolIdsBySlug->get($toolSlug);
                $examId = $examIdsBySlug->get($examSlug);
                $pairId = $toolId && $examId ? $toolExamIdsByPair->get($toolId.'|'.$examId) : null;

                if ($pairId) {
                    $toolExamBySlug = collect(["{$toolSlug}|{$examSlug}" => $pairId]);
                }
            }

            $toolExamId = $this->resolveRef(
                $toolExamIdsByUid,
                $toolExamBySlug,
                $mappingUid,
                $toolSlug !== null && $examSlug !== null ? "{$toolSlug}|{$examSlug}" : null,
                'mapping',
                $label,
            );

            if ($toolExamId === null) {
                $stats['skipped']++;

                continue;
            }

            $rawDataJson = $this->cleanString($row['data_json'] ?? null);
            $data = [];

            if ($rawDataJson !== null) {
                $decoded = json_decode($rawDataJson, true);

                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $data = $decoded;
                } else {
                    $this->warnings[] = "{$label}: data_json is not valid JSON — saved as empty";
                }
            }

            $isPlaceholder = $this->normalizeBool($row['is_placeholder'] ?? null, false);
            $translations = [];

            foreach (self::LOCALE_SUFFIXES as $suffix => $locale) {
                $title = $this->cleanString($row["seo_title_{$suffix}"] ?? null);
                $description = $this->cleanString($row["seo_description_{$suffix}"] ?? null);

                if ($title === null && $description === null) {
                    continue;
                }

                $translations[$locale] = ['seo_title' => $title, 'seo_description' => $description];
            }

            ksort($translations);

            $entries[] = [
                'uid' => $uid,
                'label' => $label,
                'key' => ($toolSlug ?? '').'/'.($examSlug ?? $mappingUid),
                'attrs' => [
                    'tool_exam_id' => $toolExamId,
                    'data_json' => json_encode($data),
                ],
                'hash' => $this->hashOf([
                    'uid' => $uid,
                    'tool_exam_id' => $toolExamId,
                    'data' => $data,
                    'is_placeholder' => $isPlaceholder,
                    'translations' => $translations,
                ]),
                'legacy' => ['tool_exam_id' => $toolExamId],
                'unique' => [['tool_exam_id' => $toolExamId]],
                'translations' => $translations,
                'isPlaceholder' => $isPlaceholder,
            ];
        }

        $todo = $this->plan(ToolExamData::class, $entries, $mode, $stats);

        if ($dryRun || empty($todo)) {
            return $this->result($stats, $mode, $dryRun, $todo);
        }

        DB::transaction(function () use ($todo) {
            $now = now();
            $this->apply(ToolExamData::class, $todo);

            $translationRows = [];

            foreach ($todo as $item) {
                foreach ($item['translations'] as $locale => $fields) {
                    $translationRows[] = [
                        'tool_exam_id' => $item['attrs']['tool_exam_id'],
                        'locale' => $locale,
                        'seo_title' => $fields['seo_title'],
                        'seo_description' => $fields['seo_description'],
                        'is_placeholder' => $item['isPlaceholder'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            if (! empty($translationRows)) {
                ToolExamTranslation::query()->upsert(
                    $translationRows,
                    ['tool_exam_id', 'locale'],
                    ['seo_title', 'seo_description', 'is_placeholder', 'updated_at'],
                );
            }
        });

        return $this->result($stats, $mode, $dryRun, $todo);
    }
}
