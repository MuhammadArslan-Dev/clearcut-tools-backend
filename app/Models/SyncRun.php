<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tracks one BigQuery content-sync run (triggered from the admin
 * dashboard) so its progress can be polled live instead of blocking the
 * triggering HTTP request for however long the sync takes. `steps` is
 * the single source of truth the admin UI renders — updated in place by
 * RunBigQuerySyncJob as each step starts/finishes.
 */
class SyncRun extends Model
{
    protected $fillable = [
        'kind',
        'mode',
        'dry_run',
        'status',
        'steps',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'steps' => 'array',
        'dry_run' => 'boolean',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function markStepRunning(string $key): void
    {
        $this->updateStep($key, ['status' => 'running']);
    }

    public function markStepDone(string $key, array $result): void
    {
        $this->updateStep($key, ['status' => 'success', 'result' => $result]);
    }

    public function markStepFailed(string $key, string $error): void
    {
        $this->updateStep($key, ['status' => 'failed', 'error' => $error]);
    }

    protected function updateStep(string $key, array $changes): void
    {
        $steps = $this->steps;

        foreach ($steps as &$step) {
            if ($step['key'] === $key) {
                $step = array_merge($step, $changes);
                break;
            }
        }
        unset($step);

        $this->update(['steps' => $steps]);
    }
}
