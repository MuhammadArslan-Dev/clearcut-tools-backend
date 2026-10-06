<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\QueueWorkerManager;
use Illuminate\Http\JsonResponse;

/**
 * Local-dev convenience for the BigQuery Content Sync dashboard: lets a
 * queue worker be started/stopped from the page instead of a separate
 * terminal running `php artisan queue:work`. See QueueWorkerManager for
 * the process-tracking mechanics and its production caveat.
 *
 * Disabled in production: production workers are managed by Supervisor.
 */
class QueueWorkerController extends Controller
{
    public function status(QueueWorkerManager $manager)
    {
        return $this->disabledInProduction() ?? response()->json($manager->status());
    }

    public function start(QueueWorkerManager $manager)
    {
        return $this->disabledInProduction() ?? response()->json($manager->start());
    }

    public function stop(QueueWorkerManager $manager)
    {
        return $this->disabledInProduction() ?? response()->json($manager->stop());
    }

    private function disabledInProduction(): ?JsonResponse
    {
        if (! app()->isProduction()) {
            return null;
        }

        return response()->json([
            'running' => false,
            'pid' => null,
            'message' => 'Manual queue worker control is disabled in production; Supervisor manages the workers.',
        ], 403);
    }
}
