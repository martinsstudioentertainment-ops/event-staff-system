<?php

declare(strict_types=1);

require_once __DIR__ . '/staff-repository.php';
require_once __DIR__ . '/staff-employee-export.php';

function staffRosterDownloadNormalizeProfile(string $profile): string
{
    $profile = strtolower(trim($profile));

    if (!in_array($profile, ['complete', 'incomplete'], true)) {
        throw new InvalidArgumentException('Profile must be complete or incomplete.');
    }

    return $profile;
}

function staffRosterDownloadNormalizeFormat(string $format): string
{
    $format = strtolower(trim($format));

    if (!in_array($format, ['xlsx', 'pdf'], true)) {
        throw new InvalidArgumentException('Format must be xlsx or pdf.');
    }

    return $format;
}

/**
 * @return array<int, array<string, mixed>>
 */
function staffRosterDownloadFetchRows(PDO $pdo, string $profile): array
{
    $profile = staffRosterDownloadNormalizeProfile($profile);

    return getStaffWithFilters($pdo, [
        'blacklisted' => false,
        'profile'     => $profile,
    ]);
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array{headers: list<string>, sheetRows: list<list<string>>}
 */
function staffRosterDownloadBuildSheetRows(array $rows): array
{
    $headers   = getEmployeeSpreadsheetHeaders();
    $sheetRows = [];

    foreach ($rows as $row) {
        $sheetRows[] = buildEmployeeSpreadsheetRow($row);
    }

    return [
        'headers'   => $headers,
        'sheetRows' => $sheetRows,
    ];
}

function staffRosterXmlEscape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function staffRosterColumnLetter(int $index): string
{
    $index += 1;
    $letters = '';

    while ($index > 0) {
        $mod     = ($index - 1) % 26;
        $letters = chr(65 + $mod) . $letters;
        $index   = (int) (($index - $mod) / 26);
    }

    return $letters;
}

/**
 * @param list<string> $headers
 * @param list<list<string>> $rows
 * @return list<float>
 */
function staffRosterColumnWidths(array $headers, array $rows): array
{
    $widths = array_map(static fn (string $h): float => max(12.0, min(42.0, mb_strlen($h) * 1.15)), $headers);

    foreach ($rows as $row) {
        foreach ($row as $i => $cell) {
            $len        = mb_strlen((string) $cell);
            $widths[$i] = max($widths[$i] ?? 12.0, min(42.0, $len * 1.05 + 2));
        }
    }

    return $widths;
}

/**
 * @param list<string> $headers
 * @param list<list<string>> $rows
 */
function staffRosterBuildSheetXml(array $headers, array $rows): string
{
    $widths = staffRosterColumnWidths($headers, $rows);
    $cols   = '';

    foreach ($widths as $i => $width) {
        $n    = $i + 1;
        $cols .= '<col min="' . $n . '" max="' . $n . '" width="' . number_format($width, 2, '.', '') . '" customWidth="1"/>';
    }

    $sheet  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $sheet .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    $sheet .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
    $sheet .= '<cols>' . $cols . '</cols>';
    $sheet .= '<sheetData>';

    $sheet .= '<row r="1" ht="24" customHeight="1">';
    foreach ($headers as $i => $header) {
        $ref   = staffRosterColumnLetter($i) . '1';
        $label = mb_strtoupper($header, 'UTF-8');
        $sheet .= '<c r="' . $ref . '" s="1" t="inlineStr"><is><t>' . staffRosterXmlEscape($label) . '</t></is></c>';
    }
    $sheet .= '</row>';

    $rowNum = 2;
    foreach ($rows as $row) {
        $sheet .= '<row r="' . $rowNum . '" ht="18" customHeight="1">';
        foreach ($row as $i => $cell) {
            $ref = staffRosterColumnLetter($i) . (string) $rowNum;
            $sheet .= '<c r="' . $ref . '" s="2" t="inlineStr"><is><t>' . staffRosterXmlEscape((string) $cell) . '</t></is></c>';
        }
        $sheet .= '</row>';
        ++$rowNum;
    }

    $sheet .= '</sheetData></worksheet>';

    return $sheet;
}

function staffRosterBuildStylesXml(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="2">
    <font><sz val="11"/><name val="Calibri"/><family val="2"/></font>
    <font><b/><sz val="11"/><name val="Calibri"/><family val="2"/></font>
  </fonts>
  <fills count="3">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFD9D9D9"/><bgColor indexed="64"/></patternFill></fill>
  </fills>
  <borders count="2">
    <border><left/><right/><top/><bottom/><diagonal/></border>
    <border>
      <left style="thin"><color auto="1"/></left>
      <right style="thin"><color auto="1"/></right>
      <top style="thin"><color auto="1"/></top>
      <bottom style="thin"><color auto="1"/></bottom>
      <diagonal/>
    </border>
  </borders>
  <cellStyleXfs count="1">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>
  </cellStyleXfs>
  <cellXfs count="3">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">
      <alignment horizontal="center" vertical="center" wrapText="1"/>
    </xf>
    <xf numFmtId="49" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1">
      <alignment vertical="top" wrapText="1"/>
    </xf>
  </cellXfs>
</styleSheet>
XML;
}

/**
 * @param list<string> $headers
 * @param list<list<string>> $rows
 */
function staffRosterSendCsvDownload(array $headers, array $rows, string $basename): void
{
    $filename = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $basename) ?: 'staff_pool.csv';
    if (!str_ends_with(strtolower($filename), '.csv')) {
        $filename .= '.csv';
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    if ($output === false) {
        throw new RuntimeException('Could not open CSV output stream.');
    }

    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($output, $headers);

    foreach ($rows as $row) {
        fputcsv($output, $row);
    }

    fclose($output);
}

/**
 * @param list<string> $headers
 * @param list<list<string>> $rows
 */
function staffRosterSendXlsxDownload(array $headers, array $rows, string $basename): void
{
    if (!class_exists('ZipArchive')) {
        staffRosterSendCsvDownload($headers, $rows, $basename);

        return;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'staff_roster_xlsx_');
    if ($tmp === false) {
        staffRosterSendCsvDownload($headers, $rows, $basename);

        return;
    }

    try {
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not build Excel workbook.');
        }

        $zip->addFromString('[Content_Types].xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>
XML);
        $zip->addFromString('_rels/.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML);
        $zip->addFromString('xl/workbook.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Staff pool" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>
XML);
        $zip->addFromString('xl/_rels/workbook.xml.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>
XML);
        $zip->addFromString('xl/styles.xml', staffRosterBuildStylesXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', staffRosterBuildSheetXml($headers, $rows));
        $zip->close();

        $filename = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $basename) ?: 'staff_pool.xlsx';
        if (substr(strtolower($filename), -5) !== '.xlsx') {
            $filename .= '.xlsx';
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string) filesize($tmp));
        header('Cache-Control: max-age=0, no-cache, must-revalidate');
        header('Pragma: public');

        readfile($tmp);
    } catch (Throwable $e) {
        staffRosterSendCsvDownload($headers, $rows, $basename);
    } finally {
        @unlink($tmp);
    }
}
