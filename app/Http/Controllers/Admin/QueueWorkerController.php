<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\QueueWorkerManager;

/**
 * Local-dev convenience for the BigQuery Content Sync dashboard: lets a
 * queue worker be started/stopped from the page instead of a separate
 * terminal running `php artisan queue:work`. See QueueWorkerManager for
 * the process-tracking mechanics and its production caveat.
 */
class QueueWorkerController extends Controller
{
    public function status(QueueWorkerManager $manager)
    {
        return response()->json($manager->status());
    }

    public function start(QueueWorkerManager $manager)
    {
        return response()->json($manager->start());
    }

    public function stop(QueueWorkerManager $manager)
    {
        return response()->json($manager->stop());
    }
}
