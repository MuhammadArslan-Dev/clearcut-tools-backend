<?php

namespace App\Services\GoogleSheets;

/**
 * Anything SheetSyncService can read the 5 client sheets from — currently
 * a live Google Sheet (GoogleSheetsClient) or a local .xlsx export
 * (XlsxSheetsClient). Same contract either way so the sync logic never
 * needs to know which one it's talking to.
 */
interface SheetSource
{
    /**
     * @return array<int, array<string, string>> rows keyed by the header row
     */
    public function getRows(string $sheetName): array;
}
