<?php

namespace App\Services\GoogleSheets;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use RuntimeException;

/**
 * Reads the same 5 sheets from a local .xlsx export instead of a live
 * Google Sheet — same row shape either way (see NormalizesSheetRows), so
 * SheetSyncService doesn't care which one it's given.
 */
class XlsxSheetsClient implements SheetSource
{
    use NormalizesSheetRows;

    protected Spreadsheet $spreadsheet;

    public function __construct(protected string $path)
    {
        if (! is_file($path)) {
            throw new RuntimeException("Excel file not found at [{$path}].");
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $this->spreadsheet = $reader->load($path);
    }

    public function getRows(string $sheetName): array
    {
        $sheet = $this->spreadsheet->getSheetByName($sheetName);

        if (! $sheet) {
            throw new RuntimeException(
                "No tab named '{$sheetName}' in [{$this->path}]. ".
                'Available tabs: '.implode(', ', $this->spreadsheet->getSheetNames()),
            );
        }

        // formatData=true (3rd arg): render boolean/number cells as their
        // displayed string ("TRUE", "1") rather than PHP true/1, so
        // downstream parsing (SheetSyncService's boolOr/intOr) sees the
        // exact same string shapes it already handles from the Sheets API.
        $grid = $sheet->toArray(null, true, true, false);

        return $this->normalizeGrid($grid);
    }
}
