<?php
/**
 * Minimal, dependency-free XLSX writer.
 *
 * Unlike a typical XLSX writer, this does NOT use PHP's ZipArchive class
 * (which requires the php_zip extension to be enabled). Instead it builds
 * the ZIP container manually, byte by byte, using only core PHP functions
 * (pack, crc32, strlen) that are always available regardless of server
 * configuration. This guarantees it works on any XAMPP install with zero
 * php.ini changes.
 */

/**
 * Builds a valid ZIP archive (STORED / uncompressed method) from an
 * associative array of [path-inside-zip => raw file contents].
 * Returns the complete ZIP file as a binary string.
 */
function build_zip_bytes(array $files): string
{
    $localParts = [];
    $centralParts = [];
    $offset = 0;

    foreach ($files as $name => $content) {
        $crc = crc32($content);
        $size = strlen($content);
        $nameLen = strlen($name);

        // Local file header
        $localHeader = pack('V', 0x04034b50);   // signature
        $localHeader .= pack('v', 20);            // version needed
        $localHeader .= pack('v', 0);             // general purpose flag
        $localHeader .= pack('v', 0);             // compression method (0 = stored)
        $localHeader .= pack('v', 0);             // mod time
        $localHeader .= pack('v', 0x21);          // mod date (Jan 1, 1980)
        $localHeader .= pack('V', $crc);          // crc32
        $localHeader .= pack('V', $size);         // compressed size (= size, stored)
        $localHeader .= pack('V', $size);         // uncompressed size
        $localHeader .= pack('v', $nameLen);      // filename length
        $localHeader .= pack('v', 0);             // extra field length
        $localHeader .= $name;

        $localParts[] = $localHeader . $content;

        // Central directory header
        $centralHeader = pack('V', 0x02014b50);   // signature
        $centralHeader .= pack('v', 20);          // version made by
        $centralHeader .= pack('v', 20);          // version needed
        $centralHeader .= pack('v', 0);           // general purpose flag
        $centralHeader .= pack('v', 0);           // compression method
        $centralHeader .= pack('v', 0);           // mod time
        $centralHeader .= pack('v', 0x21);        // mod date
        $centralHeader .= pack('V', $crc);        // crc32
        $centralHeader .= pack('V', $size);       // compressed size
        $centralHeader .= pack('V', $size);       // uncompressed size
        $centralHeader .= pack('v', $nameLen);    // filename length
        $centralHeader .= pack('v', 0);           // extra field length
        $centralHeader .= pack('v', 0);           // file comment length
        $centralHeader .= pack('v', 0);           // disk number start
        $centralHeader .= pack('v', 0);           // internal file attributes
        $centralHeader .= pack('V', 0);           // external file attributes
        $centralHeader .= pack('V', $offset);     // relative offset of local header
        $centralHeader .= $name;

        $centralParts[] = $centralHeader;

        $offset += strlen($localParts[count($localParts) - 1]);
    }

    $localData = implode('', $localParts);
    $centralData = implode('', $centralParts);

    $endRecord = pack('V', 0x06054b50);           // signature
    $endRecord .= pack('v', 0);                   // disk number
    $endRecord .= pack('v', 0);                   // disk with central dir
    $endRecord .= pack('v', count($files));       // entries on this disk
    $endRecord .= pack('v', count($files));       // total entries
    $endRecord .= pack('V', strlen($centralData)); // size of central directory
    $endRecord .= pack('V', strlen($localData));   // offset of start of central directory
    $endRecord .= pack('v', 0);                   // comment length

    return $localData . $centralData . $endRecord;
}

/**
 * Generates a single-sheet .xlsx file from a plain 2D array and writes
 * it to $filePath. Returns true on success.
 */
function write_simple_xlsx(string $filePath, array $headers, array $rows): bool
{
    $colLetter = function (int $index): string {
        $index++; // convert to 1-based
        $letter = '';
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $index = intdiv($index - $mod, 26);
        }
        return $letter;
    };

    $escape = function ($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    };

    $sheetRows = '';
    $rowNum = 1;

    // Header row
    $cells = '';
    foreach ($headers as $i => $h) {
        $ref = $colLetter($i) . $rowNum;
        $cells .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . $escape($h) . '</t></is></c>';
    }
    $sheetRows .= '<row r="' . $rowNum . '">' . $cells . '</row>';
    $rowNum++;

    // Data rows
    foreach ($rows as $row) {
        $cells = '';
        foreach ($row as $i => $val) {
            $ref = $colLetter($i) . $rowNum;
            if (is_numeric($val)) {
                $cells .= '<c r="' . $ref . '"><v>' . $val . '</v></c>';
            } else {
                $cells .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . $escape($val) . '</t></is></c>';
            }
        }
        $sheetRows .= '<row r="' . $rowNum . '">' . $cells . '</row>';
        $rowNum++;
    }

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>' . $sheetRows . '</sheetData>'
        . '</worksheet>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '</Types>';

    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Sales" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '</Relationships>';

    $files = [
        '[Content_Types].xml' => $contentTypes,
        '_rels/.rels' => $rootRels,
        'xl/workbook.xml' => $workbookXml,
        'xl/_rels/workbook.xml.rels' => $workbookRels,
        'xl/worksheets/sheet1.xml' => $sheetXml,
    ];

    $zipBytes = build_zip_bytes($files);

    return file_put_contents($filePath, $zipBytes) !== false;
}