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
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        DB::transaction(function () use ($rows, &$stats) {
            foreach ($rows as $i => $row) {
                $slug = $row['exam_slug'] ?? null;

                if (! $slug) {
                    $this->warn($i, 'missing exam_slug — row columns: '.implode(', ', array_keys($row)));
                    $stats['skipped']++;

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

                $exam = Exam::updateOrCreate(['exam_slug' => $slug], []);

                foreach ($fullNameByLocale as $locale => $fullNameEntry) {
                    $translation = ExamTranslation::updateOrCreate(
                        ['exam_id' => $exam->id, 'locale' => $locale],
                        [
                            'short_name' => $shortName,
                            'full_name' => $fullNameEntry['name'] ?? $shortName,
                            'conducting_body' => $conductingBodyByLocale[$locale]['name']
                                ?? $conductingBodyByLocale['en']['name']
                                ?? null,
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

    protected function warn(int $rowIndex, string $message): void
    {
        $this->warnings[] = "row {$rowIndex}: {$message}";
    }
}
