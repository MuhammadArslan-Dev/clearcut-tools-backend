<?php

namespace App\Providers;

use App\Services\GoogleSheets\GoogleSheetsClient;
use App\Services\GoogleSheets\SheetSource;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Default sheet source is the live Google Sheet; `sheets:sync
        // --file=` overrides this per-run with an XlsxSheetsClient instead
        // (see SyncGoogleSheetsCommand) rather than changing this binding.
        $this->app->bind(SheetSource::class, GoogleSheetsClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
