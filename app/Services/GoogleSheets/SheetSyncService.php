<?php

namespace App\Services\GoogleSheets;

use App\Models\Exam;
use App\Models\ExamTranslation;
use App\Models\Tool;
use App\Models\ToolCategory;
use App\Models\ToolCategoryTranslation;
use App\Models\ToolExam;
use App\Models\ToolExamData;
use App\Models\ToolExamTranslation;
use App\Models\ToolTranslation;
use Illuminate\Support\Facades\DB;

/**
 * Syncs the 5 client-facing sheets (see sheet-structure.html) into
 * PostgreSQL, in dependency order: Tools/Exams have no dependencies,
 * Tool Categories needs Tools, Tool Exams needs Tools+Exams+Tool
 * Categories, Tool Exam Data needs Tool Exams.
 *
 * Every sheet's text columns (tool_name, short_name/full_name, label,
 * seo_title/seo_description) land in that table's `*_translations` row for
 * the row's locale, not the base table — see the create_*_translations_
 * table migrations for why. A sheet may add a `locale` column to sync more
 * than one language; without one, every row syncs as config('app.locale').
 * data_json stays on tool_exam_data as-is: it's structural/non-text config
 * (dimensions, size limits, formats), not translated content.
 *
 * Upsert-only, keyed by each table's natural slug key (plus locale for
 * translations) — a row already in the database but removed from the sheet
 * is left alone rather than deleted, so a client typo or an accidentally-
 * cleared row can't silently wipe data. Each table syncs inside its own
 * transaction, so a bad row in one sheet can't corrupt an earlier,
 * already-synced table; a bad row within a sheet is skipped and reported,
 * not fatal to the rest of that sheet.
 */
class SheetSyncService
{
    protected const SHEET_TOOLS = 'Tools';

    protected const SHEET_EXAMS = 'Exams';

    protected const SHEET_TOOL_CATEGORIES = 'Tool Categories';

    protected const SHEET_TOOL_EXAMS = 'Tool Exams';

    protected const SHEET_TOOL_EXAM_DATA = 'Tool Exam Data';

    /** @var array<int, string> */
    protected array $warnings = [];

    public function __construct(protected SheetSource $client) {}

    /**
     * @return array{summary: array<string, array{created: int, updated: int, skipped: int}>, warnings: array<int, string>}
     */
    public function sync(): array
    {
        $this->warnings = [];

        $summary = [
            'tools' => $this->syncTools(),
            'exams' => $this->syncExams(),
            'tool_categories' => $this->syncToolCategories(),
            'tool_exams' => $this->syncToolExams(),
            'tool_exam_data' => $this->syncToolExamData(),
        ];

        return [
            'summary' => $summary,
            'warnings' => $this->warnings,
        ];
    }

    protected function syncTools(): array
    {
        $rows = $this->client->getRows(self::SHEET_TOOLS);
        $stats = $this->emptyStats();

        DB::transaction(function () use ($rows, &$stats) {
            foreach ($rows as $i => $row) {
                $slug = $row['tool_slug'] ?? '';
                if ($slug === '') {
                    $this->warn(self::SHEET_TOOLS, $i, 'missing tool_slug — row skipped');
                    $stats['skipped']++;

                    continue;
                }

                $tool = Tool::updateOrCreate(
                    ['tool_slug' => $slug],
                    [
                        'status' => $this->blankOr($row['status'] ?? '', 'active'),
                        'sort_order' => $this->intOr($row['sort_order'] ?? '', 0),
                    ],
                );

                $translation = ToolTranslation::updateOrCreate(
                    ['tool_id' => $tool->id, 'locale' => $this->resolveLocale($row)],
                    [
                        'tool_name' => $row['tool_name'] ?? $slug,
                        'description' => $this->nullIfBlank($row['description'] ?? ''),
                    ],
                );

                $stats[$translation->wasRecentlyCreated ? 'created' : 'updated']++;
            }
        });

        return $stats;
    }

    protected function syncExams(): array
    {
        $rows = $this->client->getRows(self::SHEET_EXAMS);
        $stats = $this->emptyStats();

        DB::transaction(function () use ($rows, &$stats) {
            foreach ($rows as $i => $row) {
                $slug = $row['exam_slug'] ?? '';
                if ($slug === '') {
                    $this->warn(self::SHEET_EXAMS, $i, 'missing exam_slug — row skipped');
                    $stats['skipped']++;

                    continue;
                }

                $exam = Exam::updateOrCreate(['exam_slug' => $slug], []);

                $translation = ExamTranslation::updateOrCreate(
                    ['exam_id' => $exam->id, 'locale' => $this->resolveLocale($row)],
                    [
                        'short_name' => $row['short_name'] ?? $slug,
                        'full_name' => $row['full_name'] ?? ($row['short_name'] ?? $slug),
                        'conducting_body' => $this->nullIfBlank($row['conducting_body'] ?? ''),
                    ],
                );

                $stats[$translation->wasRecentlyCreated ? 'created' : 'updated']++;
            }
        });

        return $stats;
    }

    protected function syncToolCategories(): array
    {
        $rows = $this->client->getRows(self::SHEET_TOOL_CATEGORIES);
        $stats = $this->emptyStats();

        DB::transaction(function () use ($rows, &$stats) {
            foreach ($rows as $i => $row) {
                $toolSlug = $row['tool_slug'] ?? '';
                $categorySlug = $row['category_slug'] ?? '';

                if ($toolSlug === '' || $categorySlug === '') {
                    $this->warn(self::SHEET_TOOL_CATEGORIES, $i, 'missing tool_slug or category_slug — row skipped');
                    $stats['skipped']++;

                    continue;
                }

                $tool = Tool::where('tool_slug', $toolSlug)->first();
                if (! $tool) {
                    $this->warn(self::SHEET_TOOL_CATEGORIES, $i, "tool_slug '{$toolSlug}' not found in Tools sheet — row skipped");
                    $stats['skipped']++;

                    continue;
                }

                $category = ToolCategory::updateOrCreate(
                    ['tool_id' => $tool->id, 'category_slug' => $categorySlug],
                    [
                        'icon' => $this->nullIfBlank($row['icon'] ?? ''),
                        'sort_order' => $this->intOr($row['sort_order'] ?? '', 0),
                        'is_active' => $this->boolOr($row['is_active'] ?? '', true),
                    ],
                );

                $translation = ToolCategoryTranslation::updateOrCreate(
                    ['tool_category_id' => $category->id, 'locale' => $this->resolveLocale($row)],
                    [
                        'label' => $row['label'] ?? $categorySlug,
                        'description' => $this->nullIfBlank($row['description'] ?? ''),
                    ],
                );

                $stats[$translation->wasRecentlyCreated ? 'created' : 'updated']++;
            }
        });

        return $stats;
    }

    protected function syncToolExams(): array
    {
        $rows = $this->client->getRows(self::SHEET_TOOL_EXAMS);
        $stats = $this->emptyStats();

        DB::transaction(function () use ($rows, &$stats) {
            foreach ($rows as $i => $row) {
                $toolSlug = $row['tool_slug'] ?? '';
                $examSlug = $row['exam_slug'] ?? '';

                if ($toolSlug === '' || $examSlug === '') {
                    $this->warn(self::SHEET_TOOL_EXAMS, $i, 'missing tool_slug or exam_slug — row skipped');
                    $stats['skipped']++;

                    continue;
                }

                $tool = Tool::where('tool_slug', $toolSlug)->first();
                if (! $tool) {
                    $this->warn(self::SHEET_TOOL_EXAMS, $i, "tool_slug '{$toolSlug}' not found in Tools sheet — row skipped");
                    $stats['skipped']++;

                    continue;
                }

                $exam = Exam::where('exam_slug', $examSlug)->first();
                if (! $exam) {
                    $this->warn(self::SHEET_TOOL_EXAMS, $i, "exam_slug '{$examSlug}' not found in Exams sheet — row skipped");
                    $stats['skipped']++;

                    continue;
                }

                $categorySlug = $row['category_slug'] ?? '';
                $categoryId = null;
                if ($categorySlug !== '') {
                    $category = ToolCategory::where('tool_id', $tool->id)
                        ->where('category_slug', $categorySlug)
                        ->first();

                    if ($category) {
                        $categoryId = $category->id;
                    } else {
                        // Not fatal to the row — public_slug/is_active are
                        // still meaningful without a category — but flagged
                        // since it silently drops the category link.
                        $this->warn(
                            self::SHEET_TOOL_EXAMS,
                            $i,
                            "category_slug '{$categorySlug}' not found for tool '{$toolSlug}' in Tool Categories sheet — saved without a category",
                        );
                    }
                }

                $publicSlug = $this->blankOr($row['public_slug'] ?? '', $examSlug);

                $toolExam = ToolExam::updateOrCreate(
                    ['tool_id' => $tool->id, 'exam_id' => $exam->id],
                    [
                        'tool_category_id' => $categoryId,
                        'public_slug' => $publicSlug,
                        'is_active' => $this->boolOr($row['is_active'] ?? '', true),
                        'sort_order' => $this->intOr($row['sort_order'] ?? '', 0),
                    ],
                );

                $stats[$toolExam->wasRecentlyCreated ? 'created' : 'updated']++;
            }
        });

        return $stats;
    }

    protected function syncToolExamData(): array
    {
        $rows = $this->client->getRows(self::SHEET_TOOL_EXAM_DATA);
        $stats = $this->emptyStats();

        DB::transaction(function () use ($rows, &$stats) {
            foreach ($rows as $i => $row) {
                $toolSlug = $row['tool_slug'] ?? '';
                $examSlug = $row['exam_slug'] ?? '';

                if ($toolSlug === '' || $examSlug === '') {
                    $this->warn(self::SHEET_TOOL_EXAM_DATA, $i, 'missing tool_slug or exam_slug — row skipped');
                    $stats['skipped']++;

                    continue;
                }

                $toolExam = ToolExam::whereHas('tool', fn ($q) => $q->where('tool_slug', $toolSlug))
                    ->whereHas('exam', fn ($q) => $q->where('exam_slug', $examSlug))
                    ->first();

                if (! $toolExam) {
                    $this->warn(
                        self::SHEET_TOOL_EXAM_DATA,
                        $i,
                        "no matching row in Tool Exams for tool_slug '{$toolSlug}' + exam_slug '{$examSlug}' — add it there first, row skipped",
                    );
                    $stats['skipped']++;

                    continue;
                }

                $rawJson = $row['data_json'] ?? '';
                $data = [];
                if ($rawJson !== '') {
                    $decoded = json_decode($rawJson, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $this->warn(
                            self::SHEET_TOOL_EXAM_DATA,
                            $i,
                            "data_json for tool_slug '{$toolSlug}' + exam_slug '{$examSlug}' is not valid JSON (".json_last_error_msg().') — row skipped',
                        );
                        $stats['skipped']++;

                        continue;
                    }
                    $data = $decoded ?? [];
                }

                // data_json is locale-agnostic structural config — see the
                // class docblock — so it's the only field here that still
                // writes to the base table rather than a translation row.
                ToolExamData::updateOrCreate(
                    ['tool_exam_id' => $toolExam->id],
                    ['data_json' => $data],
                );

                $translation = ToolExamTranslation::updateOrCreate(
                    ['tool_exam_id' => $toolExam->id, 'locale' => $this->resolveLocale($row)],
                    [
                        'seo_title' => $this->nullIfBlank($row['seo_title'] ?? ''),
                        'seo_description' => $this->nullIfBlank($row['seo_description'] ?? ''),
                        'is_placeholder' => $this->boolOr($row['is_placeholder'] ?? '', false),
                    ],
                );

                $stats[$translation->wasRecentlyCreated ? 'created' : 'updated']++;
            }
        });

        return $stats;
    }

    /** @return array{created: int, updated: int, skipped: int} */
    protected function emptyStats(): array
    {
        return ['created' => 0, 'updated' => 0, 'skipped' => 0];
    }

    protected function warn(string $sheet, int $rowIndex, string $message): void
    {
        // +2: 1 to undo the 0-based index, 1 more because the header row
        // was already shifted off before data rows were indexed — this
        // makes the number match the actual row the client sees in Sheets.
        $this->warnings[] = "[{$sheet}] row ".($rowIndex + 2).": {$message}";
    }

    /**
     * A sheet doesn't have to carry a `locale` column at all — every row
     * then syncs as the app's default locale, exactly like before
     * translation tables existed. Once a client sheet adds that column
     * (or the client sends a whole extra sheet with it set to e.g. "hi"),
     * the very same sync call starts populating that language too.
     */
    protected function resolveLocale(array $row): string
    {
        return $this->blankOr($row['locale'] ?? '', config('app.locale'));
    }

    protected function nullIfBlank(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    protected function blankOr(string $value, string $default): string
    {
        return $value === '' ? $default : $value;
    }

    protected function intOr(string $value, int $default): int
    {
        return $value === '' || ! is_numeric($value) ? $default : (int) $value;
    }

    protected function boolOr(string $value, bool $default): bool
    {
        $normalized = strtolower(trim($value));

        return match ($normalized) {
            '' => $default,
            'true', '1', 'yes', 'active' => true,
            'false', '0', 'no', 'inactive' => false,
            default => $default,
        };
    }
}
