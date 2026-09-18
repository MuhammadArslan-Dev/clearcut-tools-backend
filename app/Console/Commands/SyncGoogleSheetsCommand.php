<?php

namespace App\Console\Commands;

use App\Services\GoogleSheets\SheetSyncService;
use App\Services\GoogleSheets\XlsxSheetsClient;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class SyncGoogleSheetsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sheets:sync {--file= : Path to a local .xlsx export instead of the live Google Sheet}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync the 5 client sheets (Tools, Exams, Tool Categories, Tool Exams, Tool Exam Data) into PostgreSQL — from the live Google Sheet, or a local .xlsx via --file=';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $file = $this->option('file');
        $this->info($file ? "Syncing from local file [{$file}]…" : 'Syncing from Google Sheets…');

        try {
            // Resolved here rather than via method injection: both this
            // service and its sheet source below it are constructed
            // eagerly by Laravel before handle() even starts if declared
            // as a parameter, which throws the credentials/file-not-found
            // RuntimeException before this try block exists to catch it.
            $sync = $file
                ? new SheetSyncService(new XlsxSheetsClient($file))
                : app(SheetSyncService::class);
            $result = $sync->sync();
        } catch (RuntimeException $e) {
            // Config/credentials problems — message is already written to
            // be read directly by whoever is setting this up.
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Sync failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(
            ['Sheet', 'Created', 'Updated', 'Skipped'],
            collect($result['summary'])->map(fn (array $stats, string $sheet) => [
                $sheet,
                $stats['created'],
                $stats['updated'],
                $stats['skipped'],
            ])->values(),
        );

        if ($result['warnings']) {
            $this->newLine();
            $this->warn(count($result['warnings']).' warning(s):');
            foreach ($result['warnings'] as $warning) {
                $this->line("  - {$warning}");
            }
        }

        $this->newLine();
        $this->info('Sync complete.');

        return self::SUCCESS;
    }
}
