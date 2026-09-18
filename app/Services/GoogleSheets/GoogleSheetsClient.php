<?php

namespace App\Services\GoogleSheets;

use Google\Client;
use Google\Service\Sheets;
use RuntimeException;

/**
 * Thin wrapper around the Google Sheets API: authenticates via a service
 * account and turns one sheet (tab)'s raw grid into an array of associative
 * rows keyed by its header row. Read-only — this never writes back to the
 * client's sheet.
 */
class GoogleSheetsClient implements SheetSource
{
    use NormalizesSheetRows;

    protected Sheets $service;

    protected string $spreadsheetId;

    public function __construct()
    {
        $credentialsPath = config('services.google_sheets.credentials');
        $spreadsheetId = config('services.google_sheets.spreadsheet_id');

        if (! $spreadsheetId) {
            throw new RuntimeException(
                'GOOGLE_SHEETS_SPREADSHEET_ID is not set in .env — copy the ID out of the sheet\'s URL '.
                '(https://docs.google.com/spreadsheets/d/{THIS_PART}/edit).'
            );
        }

        if (! is_file($credentialsPath)) {
            throw new RuntimeException(
                "Google service-account credentials not found at [{$credentialsPath}]. ".
                'Download the JSON key for a service account from Google Cloud Console and place it there '.
                '(path is GOOGLE_SHEETS_CREDENTIALS in .env), then share the Google Sheet with that key\'s '.
                'client_email as a Viewer — without that share, every sync fails with a 403.'
            );
        }

        $client = new Client();
        $client->setApplicationName('ClearCut Tools — Sheet Sync');
        $client->setAuthConfig($credentialsPath);
        $client->setScopes([Sheets::SPREADSHEETS_READONLY]);

        $this->service = new Sheets($client);
        $this->spreadsheetId = $spreadsheetId;
    }

    /**
     * Fetch a whole named sheet (tab) and return it as an array of
     * associative rows keyed by the header row's cell values.
     *
     * Rows where every cell is blank are dropped (trailing gaps are normal
     * in a client-edited sheet); rows shorter than the header — which the
     * Sheets API returns whenever trailing cells in that row are empty,
     * not just when the row is genuinely incomplete — are padded with ''
     * rather than treated as malformed.
     *
     * @return array<int, array<string, string>>
     */
    public function getRows(string $sheetName): array
    {
        $response = $this->service->spreadsheets_values->get(
            $this->spreadsheetId,
            "'{$sheetName}'",
        );

        return $this->normalizeGrid($response->getValues() ?? []);
    }
}
