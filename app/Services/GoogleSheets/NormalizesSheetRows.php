<?php

namespace App\Services\GoogleSheets;

/**
 * Shared by GoogleSheetsClient and XlsxSheetsClient: turns a raw grid (first
 * row = header, rest = data, every cell already a string) into associative
 * rows keyed by header, so SheetSyncService sees identical shapes from
 * either source.
 */
trait NormalizesSheetRows
{
    /**
     * @param  array<int, array<int, string>>  $grid  raw rows, header row included
     * @return array<int, array<string, string>>
     */
    protected function normalizeGrid(array $grid): array
    {
        if (count($grid) < 1) {
            return [];
        }

        $header = array_map(fn ($cell) => trim((string) $cell), array_shift($grid));
        $columns = count($header);

        $records = [];

        foreach ($grid as $row) {
            // A row shorter than the header (trailing blank cells) or
            // longer (a stray extra column) is normal in a client-edited
            // sheet — pad/trim to the header width rather than treating
            // either as malformed.
            $row = array_slice(array_pad($row, $columns, ''), 0, $columns);

            $record = array_combine($header, array_map(
                fn ($cell) => trim((string) $cell),
                $row,
            ));

            $isBlankRow = count(array_filter($record, fn ($value) => $value !== '')) === 0;
            if ($isBlankRow) {
                continue; // trailing gap in the sheet
            }

            $records[] = $record;
        }

        return $records;
    }
}
