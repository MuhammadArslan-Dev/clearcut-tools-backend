<?php

namespace App\Services\BigQuery;

use App\Models\Exam;
use App\Models\Tool;
use App\Models\ToolExam;
use App\Models\ToolExamData;
use App\Models\ToolExamTranslation;
use App\Services\BigQuery\Concerns\DecodesLocaleJson;
use Illuminate\Support\Facades\DB;

/**
 * Syncs the `tool_exam_content` BigQuery table into this app's
 * `tool_exam_data` + `tool_exam_translations` tables — the BigQuery
 * counterpart of GoogleSheets\SheetSyncService::syncToolExamData().
 * Depends on `tool_exam_mapping` having already been synced (resolves
 * tool_exam_id via tool_slug + exam_slug).
 *
 * Confirmed live schema (clear-cutoff-435016.content.tool_exam_content):
 *   tool_slug                plain string
 *   exam_slug                plain string
 *   seo_title_{loc}          flat, per-locale SEO title columns — locale
 *   seo_description_{loc}    suffix is "eng"/"hi"/"mr"/"pun" (note: "eng"
 *                            not "en", mapped below to match this app's
 *                            "en" locale convention elsewhere)
 *   is_placeholder           bool / "TRUE"/"FALSE" — a single flag, not
 *                            per-locale, so the same value is written to
 *                            every locale's translation row
 *   data_json                already a JSON string (photoSpec/signatureSpec/
 *                            officialRequirements) — stored as-is on
 *                            tool_exam_data, not per-locale
 */
class BigQueryToolExamContentSyncService
{
    use DecodesLocaleJson;

    protected const QUERY = 'SELECT * FROM `clear-cutoff-435016.content.tool_exam_content`';

    // BigQuery column locale suffix => this app's locale value.
    protected const LOCALE_SUFFIXES = [
        'eng' => 'en',
        'hi' => 'hi',
        'mr' => 'mr',
        'pun' => 'pun',
    ];

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
                $exam = Exam::where('exam_slug', $examSlug)->first();

                if (! $tool || ! $exam) {
                    $this->warnings[] = "row {$i}: tool_slug '{$toolSlug}' or exam_slug '{$examSlug}' not found — sync tools/exams first, row skipped";
                    $stats['skipped']++;

                    continue;
                }

                $toolExam = ToolExam::where('tool_id', $tool->id)->where('exam_id', $exam->id)->first();

                if (! $toolExam) {
                    $this->warnings[] = "row {$i}: no tool_exam_mapping row for tool '{$toolSlug}' + exam '{$examSlug}' — sync tool_exam_mapping first, row skipped";
                    $stats['skipped']++;

                    continue;
                }

                $rawDataJson = $row['data_json'] ?? null;
                $data = [];

                if ($rawDataJson) {
                    $decoded = json_decode($rawDataJson, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $data = $decoded;
                    } else {
                        $this->warnings[] = "row {$i}: data_json for tool '{$toolSlug}' + exam '{$examSlug}' is not valid JSON — saved as empty";
                    }
                }

                ToolExamData::updateOrCreate(
                    ['tool_exam_id' => $toolExam->id],
                    ['data_json' => $data],
                );

                $isPlaceholder = $this->normalizeBool($row['is_placeholder'] ?? null, false);

                foreach (self::LOCALE_SUFFIXES as $suffix => $locale) {
                    $title = $row["seo_title_{$suffix}"] ?? null;
                    $description = $row["seo_description_{$suffix}"] ?? null;

                    if ($title === null && $description === null) {
                        continue;
                    }

                    $translation = ToolExamTranslation::updateOrCreate(
                        ['tool_exam_id' => $toolExam->id, 'locale' => $locale],
                        [
                            'seo_title' => $title,
                            'seo_description' => $description,
                            'is_placeholder' => $isPlaceholder,
                        ],
                    );

                    $stats[$translation->wasRecentlyCreated ? 'created' : 'updated']++;
                }
            }
        });

        return [
            'stats' => $stats,
            'warnings' => $this->warnings,
        ];
    }
}
