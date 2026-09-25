<?php
/**
 * Shared country-wise grouping for every list-style Excel export
 * (export_vessels/export_reports/export_completed/export_cancelled/
 * export_recovery). Each export still builds its own query, headers and
 * row values exactly as before — this only takes that finished header/row
 * data and lays it out as one section per country, inserting a heading row
 * (yellow fill, red bold text, full border, centered, merged across all
 * columns) before each country's own copy of the existing column-header
 * row and its records, in the order countries first appear in the data.
 */

/**
 * Buckets rows by country (trim + uppercase, so "India"/"INDIA"/" india "
 * all fall in the same group; blank/missing becomes $unknownLabel) while
 * preserving each row's original position within its group.
 *
 * @param array  $rows  Each item: ['country' => string, 'cells' => array]
 * @return array<string, array[]> country label => list of cell arrays
 */
function groupRowsByCountry(array $rows, string $unknownLabel = 'UNKNOWN'): array {
    $groups = [];
    foreach ($rows as $row) {
        $raw = trim((string)($row['country'] ?? ''));
        $key = $raw === '' ? $unknownLabel : mb_strtoupper($raw);
        $groups[$key][] = $row['cells'];
    }
    return $groups;
}

/**
 * Writes $rows into $sheet starting at row 1, grouped country-by-country:
 * a merged/styled country heading row, then the same $headers row (styled
 * exactly as the existing single-header exports already style it), then
 * that country's data rows, then a blank separator row.
 *
 * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
 * @param string[] $headers Existing column headers, unchanged, in order
 * @param array    $rows    Each item: ['country' => string, 'cells' => array]
 */
function writeCountryGroupedSheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $headers, array $rows): void {
    $colCount = count($headers);
    $lastCol = chr(64 + $colCount); // A..Z is plenty for these exports (<=9 cols)

    $groups = groupRowsByCountry($rows);

    $rowNum = 1;
    foreach ($groups as $countryName => $countryRows) {
        // Country heading: merged across every column, yellow fill, red bold, bordered, centered.
        $headingRange = 'A' . $rowNum . ':' . $lastCol . $rowNum;
        $sheet->setCellValue('A' . $rowNum, $countryName);
        $sheet->mergeCells($headingRange);
        $headingStyle = $sheet->getStyle($headingRange);
        $headingStyle->getFont()->setBold(true)->getColor()->setRGB('FF0000');
        $headingStyle->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('FFFF00');
        $headingStyle->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $headingStyle->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)->getColor()->setRGB('000000');
        $rowNum++;

        // This country's copy of the existing column-header row (same style the exports already use).
        $sheet->fromArray([$headers], null, 'A' . $rowNum);
        $headerRange = 'A' . $rowNum . ':' . $lastCol . $rowNum;
        $headerStyle = $sheet->getStyle($headerRange);
        $headerStyle->getFont()->setBold(true)->getColor()->setRGB('FF0000');
        $headerStyle->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('FFFF00');
        $headerStyle->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)->getColor()->setRGB('000000');
        $rowNum++;

        // This country's data rows.
        foreach ($countryRows as $cells) {
            $sheet->fromArray([$cells], null, 'A' . $rowNum);
            $dataRange = 'A' . $rowNum . ':' . $lastCol . $rowNum;
            $dataStyle = $sheet->getStyle($dataRange);
            $dataStyle->getFont()->getColor()->setRGB('000000');
            $dataStyle->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_NONE);
            $dataStyle->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)->getColor()->setRGB('000000');
            $rowNum++;
        }

        $rowNum++; // blank separator row before the next country's section
    }

    foreach (range('A', $lastCol) as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
}
