<?php

namespace App\Services\BigQuery;

use App\Models\Exam;
use App\Models\ExamTranslation;
use App\Services\BigQuery\Concerns\DecodesLocaleJson;
use Illuminate\Support\Facades\DB;

/**
 * Syncs the `exams` BigQuery table into this app's `exams` +
 * `exam_translations` tables — the BigQuery counterpart of
 * GoogleSheets\SheetSyncService::syncExams(), following the same
 * upsert-only-by-slug convention (a row removed from the source is left
 * alone here, never deleted).
 *
 * Confirmed live schema (clear-cutoff-435016.content.exams):
 *   exam_slug        plain string, e.g. "htet"
 *   short_name       plain string, e.g. "HTET" — not itself translated
 *   full_name        JSON string keyed by locale, e.g.
 *                     {"en":{"name":"..."},"hi":{"name":"..."},"mr":{...},"pun":{...}}
 *   conducting_body  same per-locale JSON shape as full_name, optional
 *
 * One `ExamTranslation` row is written per locale found in `full_name` —
 * unlike the dashboard app's content locale (which is pinned to en/hi
 * only), this app's `exam_translations.locale` column has no such
 * restriction, so every locale present in the source (including e.g.
 * "mr"/"pun") is synced as its own row.
 *
 * Batched via `Model::upsert()` rather than one `updateOrCreate()` per
 * exam/locale — the DB here is a remote server shared with production
 * traffic, so a per-row round trip (measured ~700-800ms under load, well
 * above bare ping) turned a few hundred exam×locale combinations into a
 * sync taking 10+ minutes. Two bulk upserts (exams, then translations
 * once exam ids are known) plus two small existing-row lookups for
 * created/updated stats keeps this to a handful of queries regardless of
 * row count.
 */
class BigQueryExamSyncService
{
    use DecodesLocaleJson;

    protected const QUERY = 'SELECT * FROM `clear-cutoff-435016.content.exams`';

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

        // Keyed by slug so a slug repeated in the source (shouldn't happen,
        // but the old per-row loop silently tolerated it) collapses to one
        // upsert row instead of erroring on a duplicate ON CONFLICT target.
        $examsBySlug = [];

        foreach ($rows as $i => $row) {
            $slug = $row['exam_slug'] ?? null;

            if (! $slug) {
                $this->warn($i, 'missing exam_slug — row columns: '.implode(', ', array_keys($row)));

                continue;
            }

            $shortName = $row['short_name'] ?? $slug;
            $fullNameByLocale = $this->decodeLocaleMap($row['full_name'] ?? null);
            $conductingBodyByLocale = $this->decodeLocaleMap($row['conducting_body'] ?? null);

            if (empty($fullNameByLocale)) {
                // full_name didn't decode into a locale map (missing, or a
                // plain unlocalized string) — fall back to a single "en"
                // row rather than skipping the exam entirely.
                $fullNameByLocale = ['en' => ['name' => $row['full_name'] ?? $shortName]];
            }

            $examsBySlug[$slug] = [
                'short_name' => $shortName,
                'full_name_by_locale' => $fullNameByLocale,
                'conducting_body_by_locale' => $conductingBodyByLocale,
            ];
        }

        $stats = ['created' => 0, 'updated' => 0, 'skipped' => count($rows) - count($examsBySlug)];

        if (empty($examsBySlug)) {
            return ['stats' => $stats, 'warnings' => $this->warnings];
        }

        DB::transaction(function () use ($examsBySlug, &$stats) {
            $now = now();
            $slugs = array_keys($examsBySlug);

            Exam::query()->upsert(
                array_map(fn (string $slug) => [
                    'exam_slug' => $slug,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $slugs),
                ['exam_slug'],
                ['updated_at'],
            );

            $examIdsBySlug = Exam::whereIn('exam_slug', $slugs)->pluck('id', 'exam_slug');

            $translationRows = [];

            foreach ($examsBySlug as $slug => $exam) {
                $examId = $examIdsBySlug[$slug];

                foreach ($exam['full_name_by_locale'] as $locale => $fullNameEntry) {
                    $translationRows[] = [
                        'exam_id' => $examId,
                        'locale' => $locale,
                        'short_name' => $exam['short_name'],
                        'full_name' => $fullNameEntry['name'] ?? $exam['short_name'],
                        'conducting_body' => $exam['conducting_body_by_locale'][$locale]['name']
                            ?? $exam['conducting_body_by_locale']['en']['name']
                            ?? null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            $existingPairs = ExamTranslation::whereIn('exam_id', $examIdsBySlug->values())
                ->get(['exam_id', 'locale'])
                ->map(fn ($t) => $t->exam_id.'|'.$t->locale)
                ->flip();

            foreach ($translationRows as $row) {
                $stats[$existingPairs->has($row['exam_id'].'|'.$row['locale']) ? 'updated' : 'created']++;
            }

            ExamTranslation::query()->upsert(
                $translationRows,
                ['exam_id', 'locale'],
                ['short_name', 'full_name', 'conducting_body', 'updated_at'],
            );
        });

        return [
            'stats' => $stats,
            'warnings' => $this->warnings,
        ];
    }

    protected function warn(int $rowIndex, string $message): void
    {
        $this->warnings[] = "row {$rowIndex}: {$message}";
    }
}
