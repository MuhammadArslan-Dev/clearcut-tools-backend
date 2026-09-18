<?php

namespace App\Services;

use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Starts/stops/checks a single `php artisan queue:work` process for local
 * dev use from the admin dashboard, so running a sync doesn't require a
 * separate terminal. Tracked via a PID file (storage/app/queue-worker.pid)
 * since each HTTP request is its own PHP process — there's no in-memory
 * handle to the worker to hold onto between requests.
 *
 * Best-effort, Windows-first (this app runs under Laragon): process
 * liveness is checked by shelling out to `tasklist`/`kill -0` rather than
 * via a held Symfony Process object. Intended for local development, not
 * for managing a queue worker in production — supervisord/systemd remain
 * the right tool there.
 *
 * Two Windows pitfalls, both found the hard way while building this:
 *
 *  - A plain `Process::start()` looked "running" right after the call but
 *    the worker was gone moments later — Symfony's Process kills its
 *    child in its own destructor if the process is still running when
 *    the object is garbage-collected, and nothing here holds it across
 *    requests. `create_new_console` avoids this: it's a genuinely
 *    independent process (own console), not tied to the launching
 *    request's Process object.
 *  - `create_new_console` also means Symfony's own getPid() returns the
 *    *console host's* pid, not the actual php.exe worker's — that host
 *    exits almost immediately, so trusting getPid() gives a pid that's
 *    already dead. Worked around by diffing `queue:work` php.exe
 *    processes (via WMI) before/after spawning instead of trusting
 *    getPid() at all.
 *  - (Tried and reverted: PowerShell's `Start-Process -WindowStyle
 *    Hidden` gives a real, correct pid via -PassThru, but the resulting
 *    process fails to open a DB socket at all — "could not create
 *    socket: the requested service provider could not be loaded"
 *    (WSA error 10106) — a Winsock-initialization quirk of processes
 *    spawned hidden this way. create_new_console doesn't have this
 *    problem, which is why it's used despite the extra pid-discovery
 *    step it requires.)
 */
class QueueWorkerManager
{
    protected string $pidFile;

    public function __construct()
    {
        $this->pidFile = storage_path('app/queue-worker.pid');
    }

    public function status(): array
    {
        $pid = $this->readPid();

        if ($pid === null) {
            return ['running' => false, 'pid' => null];
        }

        if (! $this->isRunning($pid)) {
            @unlink($this->pidFile);

            return ['running' => false, 'pid' => null];
        }

        return ['running' => true, 'pid' => $pid];
    }

    public function start(): array
    {
        $current = $this->status();

        if ($current['running']) {
            return $current;
        }

        $phpBinary = (new PhpExecutableFinder())->find() ?: 'php';
        $pid = PHP_OS_FAMILY === 'Windows'
            ? $this->startWindows($phpBinary)
            : $this->startUnix($phpBinary);

        if ($pid) {
            file_put_contents($this->pidFile, (string) $pid);
        }

        return ['running' => (bool) $pid, 'pid' => $pid];
    }

    protected function startWindows(string $phpBinary): ?int
    {
        $before = $this->queueWorkerPids();

        $process = new Process([$phpBinary, 'artisan', 'queue:work', '--tries=1'], base_path());
        $process->disableOutput();
        $process->setOptions(['create_new_console' => true]);
        $process->start();

        // Give the new console + php.exe time to actually appear in the
        // process list before diffing.
        usleep(700_000);

        $new = array_values(array_diff($this->queueWorkerPids(), $before));

        return $new[0] ?? null;
    }

    /** @return array<int, string> pids (as strings) of running `queue:work` php.exe processes */
    protected function queueWorkerPids(): array
    {
        $process = new Process([
            'powershell', '-NoProfile', '-Command',
            "Get-CimInstance Win32_Process -Filter \"Name='php.exe'\" | ".
            'Where-Object { $_.CommandLine -like "*queue:work*" } | '.
            'Select-Object -ExpandProperty ProcessId',
        ]);
        $process->run();

        return array_values(array_filter(
            array_map('trim', explode("\n", $process->getOutput())),
            fn ($v) => ctype_digit($v),
        ));
    }

    protected function startUnix(string $phpBinary): ?int
    {
        // Standard nohup+& idiom: backgrounds the process and detaches it
        // from this PHP process's lifetime, `$!` giving its PID directly —
        // no Process object is held across the async boundary here either.
        $command = sprintf(
            'nohup %s artisan queue:work --tries=1 > /dev/null 2>&1 & echo $!',
            escapeshellarg($phpBinary),
        );

        $process = Process::fromShellCommandline($command, base_path());
        $process->run();

        $output = trim($process->getOutput());

        return ctype_digit($output) ? (int) $output : null;
    }

    public function stop(): array
    {
        $pid = $this->readPid();

        if ($pid === null) {
            return ['running' => false, 'pid' => null];
        }

        if (PHP_OS_FAMILY === 'Windows') {
            (new Process(['taskkill', '/F', '/T', '/PID', (string) $pid]))->run();
        } else {
            (new Process(['kill', (string) $pid]))->run();
        }

        @unlink($this->pidFile);

        return ['running' => false, 'pid' => null];
    }

    protected function readPid(): ?int
    {
        if (! is_file($this->pidFile)) {
            return null;
        }

        $contents = trim(file_get_contents($this->pidFile));

        return $contents !== '' && ctype_digit($contents) ? (int) $contents : null;
    }

    protected function isRunning(int $pid): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $process = new Process(['tasklist', '/FI', "PID eq {$pid}"]);
            $process->run();

            return str_contains($process->getOutput(), (string) $pid);
        }

        return posix_kill($pid, 0);
    }
}
