<?php

namespace App\Services\BigQuery;

use Google\Cloud\BigQuery\BigQueryClient as GoogleBigQueryClient;
use RuntimeException;

/**
 * Thin wrapper around the BigQuery client: authenticates via a service
 * account and runs a raw SQL query, returning plain associative rows.
 *
 * Requests both the `bigquery` and `drive.readonly` scopes — a queried
 * table that is itself a BigQuery "Drive" external table backed by a
 * Google Sheet needs Drive access on top of BigQuery access, or every
 * query against it fails with "Permission denied while getting Drive
 * credentials" even though the key is otherwise valid for plain tables.
 * The underlying sheet must also be shared (Viewer) with the key's
 * client_email — the scope alone doesn't grant access to a sheet the
 * account was never invited to.
 */
class BigQueryClient
{
    protected GoogleBigQueryClient $client;

    public function __construct()
    {
        $credentialsPath = config('services.bigquery.credentials');
        $projectId = config('services.bigquery.project_id');

        if (! $projectId) {
            throw new RuntimeException(
                'BIGQUERY_PROJECT_ID is not set in .env.'
            );
        }

        // BIGQUERY_CREDENTIALS is documented as a path relative to the
        // project root, but a relative is_file() check resolves against
        // the PHP process's cwd — which is the project root under `php
        // artisan` (tinker/commands), but the public/ document root under
        // the built-in server and most web-server/PHP-FPM setups. Anchor
        // it to base_path() so the same .env value works in both.
        if ($credentialsPath && ! str_starts_with($credentialsPath, DIRECTORY_SEPARATOR)
            && ! preg_match('/^[A-Za-z]:[\\\\\/]/', $credentialsPath)) {
            $credentialsPath = base_path($credentialsPath);
        }

        if (! is_file($credentialsPath)) {
            throw new RuntimeException(
                "BigQuery service-account credentials not found at [{$credentialsPath}]. ".
                'Download the JSON key for a service account with BigQuery access from Google '.
                'Cloud Console and place it there (path is BIGQUERY_CREDENTIALS in .env).'
            );
        }

        $this->client = new GoogleBigQueryClient([
            'keyFilePath' => $credentialsPath,
            'projectId' => $projectId,
            'scopes' => [
                'https://www.googleapis.com/auth/bigquery',
                'https://www.googleapis.com/auth/drive.readonly',
            ],
        ]);
    }

    /**
     * Run a raw SQL query and return the rows as plain associative arrays.
     *
     * @return array<int, array<string, mixed>>
     */
    public function runQuery(string $query, bool $useCache = false): array
    {
        $job = $this->client->query($query)->useQueryCache($useCache);
        $results = $this->client->runQuery($job);

        return iterator_to_array($results);
    }
}
