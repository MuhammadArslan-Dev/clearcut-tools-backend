<?php

namespace App\Console\Commands;

use App\Services\BigQuery\BigQueryExamSyncService;
use App\Services\BigQuery\BigQueryToolCategorySyncService;
use App\Services\BigQuery\BigQueryToolExamContentSyncService;
use App\Services\BigQuery\BigQueryToolExamDocumentSyncService;
use App\Services\BigQuery\BigQueryToolExamSyncService;
use App\Services\BigQuery\BigQueryToolSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncBigQueryCommand extends Command
{
    /**
     * Same dependency order as RunBigQuerySyncJob / BigQueryController::syncAll.
     */
    protected const STEPS = [
        'exams' => BigQueryExamSyncService::class,
        'tools' => BigQueryToolSyncService::class,
        'tool_categories' => BigQueryToolCategorySyncService::class,
        'tool_exam_mapping' => BigQueryToolExamSyncService::class,
        'tool_exam_content' => BigQueryToolExamContentSyncService::class,
        'tool_exam_documents' => BigQueryToolExamDocumentSyncService::class,
    ];

    protected $signature = 'bigquery:sync
        {kind=all : all, exams, tools, tool_categories, tool_exam_mapping, tool_exam_content or tool_exam_documents}
        {--mode=incremental : incremental = only new/changed rows, full = rewrite every row}
        {--dry-run : report what would change without writing anything}';

    protected $description = 'Sync the content tables from BigQuery into PostgreSQL, matched by uid — only changed rows by default, or everything with --mode=full';

    public function handle(): int
    {
        $kind = (string) $this->argument('kind');
        $mode = (string) $this->option('mode');
        $dryRun = (bool) $this->option('dry-run');

        if (! in_array($mode, ['incremental', 'full'], true)) {
            $this->error("Invalid --mode '{$mode}' — use 'incremental' or 'full'.");

            return self::INVALID;
        }

        if ($kind !== 'all' && ! isset(self::STEPS[$kind])) {
            $this->error("Invalid kind '{$kind}'. Use: all, ".implode(', ', array_keys(self::STEPS)).'.');

            return self::INVALID;
        }

        $steps = $kind === 'all' ? self::STEPS : [$kind => self::STEPS[$kind]];

        $this->info(($dryRun ? '[DRY RUN] ' : '')."Syncing {$kind} ({$mode})…");

        $failed = false;

        foreach ($steps as $key => $serviceClass) {
            try {
                $result = app($serviceClass)->sync($mode, $dryRun);
            } catch (Throwable $e) {
                $this->error("{$key}: ".$e->getMessage());
                $failed = true;

                continue;
            }

            $s = $result['stats'];
            $this->line(sprintf(
                '%-18s created %d, updated %d, unchanged %d, skipped %d',
                $key,
                $s['created'],
                $s['updated'],
                $s['unchanged'],
                $s['skipped'],
            ));

            if (isset($result['deactivated'])) {
                $this->line(sprintf('%-18s deactivated %d, reactivated %d', '', $result['deactivated'], $result['reactivated']));
            }

            foreach ($result['url_changes'] as $change) {
                $this->warn("  URL CHANGE: {$change}");
            }

            foreach ($result['warnings'] as $warning) {
                $this->line("  - {$warning}");
            }
        }

        $this->newLine();
        $this->info($dryRun ? 'Dry run complete — nothing was written.' : ($failed ? 'Finished with errors.' : 'Sync complete.'));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
