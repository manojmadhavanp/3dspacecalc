```php
<?php

namespace App\Service;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\RowIterator;

class FileParsingService {
    // Define expected headers and their mapping to item object keys
    // Case-insensitive matching for headers is good practice.
    private const HEADER_MAP = [
        'name' => ['name', 'item name', 'product name'],
        'type' => ['type', 'item type', 'category'],
        'qty' => ['qty', 'quantity', 'count'],
        'widthcm' => ['widthcm', 'width (cm)', 'width', 'item width'],
        'lengthcm' => ['lengthcm', 'length (cm)', 'length', 'item length'],
        'heightcm' => ['heightcm', 'height (cm)', 'height', 'item height'],
        'weightkg' => ['weightkg', 'weight (kg)', 'weight', 'item weight'],
        'stackable' => ['stackable', 'isstackable', 'can stack'],
        'tiltable' => ['tiltable', 'istiltable', 'can tilt'],
        'maxsupportoverridekg' => ['maxsupportoverridekg', 'max support (kg)', 'support override']
        // 'color' => ['color', 'item color'] // If color is also in the file
    ];

    // Define which fields are absolutely required for an item to be valid
    private const REQUIRED_FIELDS = ['name', 'type', 'qty', 'widthcm', 'lengthcm', 'heightcm', 'weightkg'];


    public function __construct() {
        // Constructor can be empty or used for dependency injection if needed later
    }

    /**
     * Parses an uploaded item file (CSV or Excel) into an array of item data.
     *
     * @param array $uploadedFile An element from PHP's $_FILES superglobal.
     * @return array ['success' => bool, 'items' => array, 'message' => string, 'errors' => array, 'warnings' => array]
     */
    public function parseItemFile(array $uploadedFile): array {
        $items = [];
        $fileErrors = []; // Errors related to the file itself or overall parsing
        $rowErrors = [];   // Errors specific to rows
        $warnings = [];  // Non-critical issues

        $filePath = $uploadedFile['tmp_name'];
        $fileName = $uploadedFile['name'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        try {
            if ($fileExtension === 'csv') {
                $items = $this->parseCsv($filePath, $rowErrors, $warnings);
            } elseif ($fileExtension === 'xlsx' || $fileExtension === 'xls') {
                $items = $this->parseExcel($filePath, $rowErrors, $warnings);
            } else {
                $fileErrors[] = "Unsupported file type: {$fileExtension}. Please upload a CSV or Excel file.";
            }
        } catch (\Exception $e) {
            error_log("FileParsingService Exception: " . $e->getMessage() . " for file " . $fileName);
            $fileErrors[] = "An error occurred while processing the file: " . $e->getMessage();
        }

        if (!empty($fileErrors)) {
            return ['success' => false, 'items' => [], 'message' => 'File processing failed.', 'errors' => $fileErrors, 'warnings' => $warnings];
        }
        if (!empty($rowErrors)) {
             return ['success' => false, 'items' => $items, 'message' => 'File processed with some invalid rows.', 'errors' => $rowErrors, 'warnings' => $warnings];
        }

        return ['success' => true, 'items' => $items, 'message' => 'File parsed successfully.', 'errors' => [], 'warnings' => $warnings];
    }

    private function parseCsv(string $filePath, array &$rowErrors, array &$warnings): array {
        $items = [];
        $header = null;
        $rowIndex = 0;

        if (($handle = fopen($filePath, 'r')) !== false) {
            while (($rowData = fgetcsv($handle)) !== false) {
                $rowIndex++;
                if ($rowIndex === 1) { // First row is header
                    $header = $this->normalizeHeaders(array_map('trim', $rowData));
                    // Validate header against expected columns (optional but good)
                    if (!$this->validateHeader($header, $warnings)) {
                        // $rowErrors[] = "CSV Error: Invalid or missing required headers.";
                        // fclose($handle); return []; // Stop processing if headers are critical
                    }
                    continue;
                }
                if ($header === null) { // Should not happen if file has rows
                     $rowErrors[] = "CSV Error: Header row not found or file is empty.";
                     break;
                }
                // Skip empty rows
                if (count(array_filter($rowData)) == 0) continue;


                $mappedRow = $this->mapRowToItem($rowData, $header, $rowIndex, $rowErrors);
                if ($mappedRow) {
                    $items[] = $mappedRow;
                }
            }
            fclose($handle);
        } else {
            throw new \RuntimeException("Could not open CSV file for reading.");
        }
        return $items;
    }

    private function parseExcel(string $filePath, array &$rowErrors, array &$warnings): array {
        $items = [];
        $header = null;
        $rowIndex = 0;

        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet(); // Get first sheet

        foreach ($worksheet->getRowIterator() as $row) {
            $rowIndex++;
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);
            $rowData = [];
            foreach ($cellIterator as $cell) {
                $rowData[] = $cell->getCalculatedValue(); // Get value, not formula
            }

            if ($rowIndex === 1) { // Header row
                $header = $this->normalizeHeaders(array_map(fn($val) => trim((string)$val), $rowData));
                 if (!$this->validateHeader($header, $warnings)) {
                    // $rowErrors[] = "Excel Error: Invalid or missing required headers.";
                    // return [];
                }
                continue;
            }
            if ($header === null) {
                $rowErrors[] = "Excel Error: Header row not found or sheet is empty.";
                break;
            }
             // Skip empty rows
            if (count(array_filter($rowData, fn($val) => $val !== null && $val !== '')) == 0) continue;

            $mappedRow = $this->mapRowToItem($rowData, $header, $rowIndex, $rowErrors);
            if ($mappedRow) {
                $items[] = $mappedRow;
            }
        }
        return $items;
    }

    private function normalizeHeaders(array $headerRow): array {
        $normalized = [];
        foreach ($headerRow as $headerCell) {
            $normalized[] = strtolower(trim(preg_replace("/\s+/", "", $headerCell))); // Remove spaces, tolower
        }
        return $normalized;
    }

    private function validateHeader(array $normalizedHeader, array &$warnings) : bool {
        $foundRequired = 0;
        foreach(self::REQUIRED_FIELDS as $reqFieldKey) {
            $possibleHeaders = self::HEADER_MAP[$reqFieldKey] ?? [$reqFieldKey];
            $foundThis = false;
            foreach($possibleHeaders as $pHeader) {
                if(in_array(strtolower(trim(preg_replace("/\s+/", "", $pHeader))), $normalizedHeader)) {
                    $foundThis = true; break;
                }
            }
            if($foundThis) $foundRequired++;
            else $warnings[] = "Header Validation: Missing recommended header for '{$reqFieldKey}'. Possible names: " . implode(', ', $possibleHeaders);
        }
        return $foundRequired >= count(self::REQUIRED_FIELDS); // For now, only warn
    }


    private function mapRowToItem(array $rowData, array $normalizedHeader, int $rowIndex, array &$rowErrors): ?array {
        $item = [];
        $colIndex = 0;
        $tempItemData = [];

        foreach ($normalizedHeader as $colIdx => $headerNameNormalized) {
            $cellValue = $rowData[$colIdx] ?? null;
            foreach (self::HEADER_MAP as $itemKey => $possibleHeaders) {
                $foundMatch = false;
                foreach($possibleHeaders as $pHeader) {
                    if (strtolower(trim(preg_replace("/\s+/", "", $pHeader))) === $headerNameNormalized) {
                        $tempItemData[$itemKey] = $cellValue;
                        $foundMatch = true;
                        break;
                    }
                }
                if($foundMatch) break;
            }
        }

        // Now, build the final item array with type conversions and validation
        $item['name'] = trim((string)($tempItemData['name'] ?? ''));
        $item['type'] = strtolower(trim((string)($tempItemData['type'] ?? 'box'))); // Default to box
        $item['qty'] = isset($tempItemData['qty']) ? (int)$tempItemData['qty'] : 0;
        $item['width'] = isset($tempItemData['widthcm']) ? (float)$tempItemData['widthcm'] : 0;
        $item['length'] = isset($tempItemData['lengthcm']) ? (float)$tempItemData['lengthcm'] : 0;
        $item['height'] = isset($tempItemData['heightcm']) ? (float)$tempItemData['heightcm'] : 0;
        $item['weight'] = isset($tempItemData['weightkg']) ? (float)$tempItemData['weightkg'] : 0;

        $stackableStr = strtolower(trim((string)($tempItemData['stackable'] ?? 'true')));
        $item['stackable'] = in_array($stackableStr, ['true', '1', 'yes', 'y']);

        $tiltableStr = strtolower(trim((string)($tempItemData['tiltable'] ?? 'false')));
        $item['tiltable'] = in_array($tiltableStr, ['true', '1', 'yes', 'y']);

        if (isset($tempItemData['maxsupportoverridekg']) && is_numeric($tempItemData['maxsupportoverridekg'])) {
            $item['maxSupportOverrideKg'] = (float)$tempItemData['maxsupportoverridekg'];
        }

        // Validate required fields for this parsed item
        $currentMissing = [];
        foreach(self::REQUIRED_FIELDS as $reqKeyDisplay) {
             // Map reqKeyDisplay (e.g. widthcm) to actual key used in $item (e.g. width)
            $actualKey = str_replace(['cm','kg'], '', $reqKeyDisplay); // simplistic map
            if ($reqKeyDisplay === 'qty' && $item[$actualKey] <= 0) $currentMissing[] = $reqKeyDisplay;
            elseif ($reqKeyDisplay !== 'qty' && ($item[$actualKey] <= 0 && !in_array($actualKey, ['stackable','tiltable']))) $currentMissing[] = $reqKeyDisplay; // Dims/weight must be >0
            elseif (empty($item[$actualKey]) && $item[$actualKey] !== '0' && !in_array($actualKey, ['stackable','tiltable'])) $currentMissing[] = $reqKeyDisplay; // Name/type cannot be empty
        }

        if (!empty($currentMissing)) {
            $rowErrors[] = "Row {$rowIndex}: Missing or invalid required data for fields: " . implode(', ', $currentMissing) . ". Row data: [" . implode('; ', $rowData) . "]";
            return null;
        }

        // Add other optional fields like color if they exist in HEADER_MAP and tempItemData

        return $item; // This structure matches the API input for one item in the "items" array
    }
}
```

**Key Features of `FileParsingService.php`:**

1.  **`HEADER_MAP` Constant:**
    *   Defines a mapping from desired internal item property keys (e.g., `widthcm`, `weightkg`) to an array of possible header names that might appear in user files (case-insensitive, spaces removed for matching). This provides flexibility in what users name their columns.
2.  **`REQUIRED_FIELDS` Constant:** Lists the internal item property keys that *must* be found and have valid data for a row to be processed.
3.  **`parseItemFile(array $uploadedFile)`:**
    *   Main public method. Takes the file array from `$_FILES`.
    *   Determines file type by extension (.csv, .xlsx, .xls).
    *   Calls the appropriate private parsing method (`parseCsv` or `parseExcel`).
    *   Catches exceptions during parsing.
    *   Returns a structured array: `['success', 'items', 'message', 'errors' (file/row level), 'warnings' (e.g. missing optional headers)]`.
4.  **`parseCsv(...)`:**
    *   Uses `fopen` and `fgetcsv` to read CSV data.
    *   Assumes the first row is a header. Calls `normalizeHeaders` and `validateHeader`.
    *   Skips empty rows.
    *   For each data row, calls `mapRowToItem`.
5.  **`parseExcel(...)`:**
    *   Uses `PhpOffice\PhpSpreadsheet\IOFactory::load()` to open Excel files.
    *   Gets the active sheet. Iterates through rows using `getRowIterator()`.
    *   Extracts cell values using `getCalculatedValue()` (to get results of formulas).
    *   Similar header processing and row mapping as CSV.
6.  **`normalizeHeaders(array $headerRow)`:** Converts header cells to lowercase and removes spaces to allow for flexible matching against `HEADER_MAP`.
7.  **`validateHeader(array $normalizedHeader, array &$warnings)`:** Checks if all `REQUIRED_FIELDS` (via their `HEADER_MAP` possibilities) are present in the uploaded file's header. Currently, it only adds warnings if recommended headers are missing, but could be made stricter.
8.  **`mapRowToItem(array $rowData, array $normalizedHeader, int $rowIndex, array &$rowErrors)`:**
    *   This is the core data mapping logic.
    *   It iterates through the `normalizedHeader` from the file. For each header, it finds the corresponding internal item key using `HEADER_MAP`.
    *   It populates a temporary data array with raw cell values.
    *   Then, it explicitly casts and validates data for each expected item property (`name`, `type`, `qty`, dimensions, weight, `stackable`, `tiltable`, `maxSupportOverrideKg`).
    *   Performs validation for required fields and positive values for dimensions/qty/weight.
    *   If a row has missing/invalid critical data, an error is added to `$rowErrors`, and `null` is returned for that row.
    *   Otherwise, an associative array matching the structure of an item object for the API input is returned.

**Important Notes:**
*   **PhpSpreadsheet Dependency:** This service relies on `phpoffice/phpspreadsheet`. This library needs to be installed via Composer (`composer require phpoffice/phpspreadsheet`) in your `backend/` directory, and Composer's autoloader should be used in `index.php`. If not using Composer, the library files would need to be manually included, which is not recommended.
*   **Error Reporting:** The service collects file-level errors, row-level errors, and warnings, which are returned to the controller for a comprehensive response to the user.
*   **Flexibility vs. Strictness:** The `HEADER_MAP` provides flexibility. `validateHeader` can be made stricter if exact header names are required.
*   **Data Type Conversion:** Explicit casting `(int)`, `(float)`, `(bool)` and string-to-boolean conversion logic is included.

This service provides a robust way to handle item list uploads from common file formats.The `FileParsingService.php` has been conceptually created.

**Key features of `FileParsingService.php`:**

1.  **Header Mapping (`HEADER_MAP`):** A flexible mapping is defined to allow various common column names in uploaded files (e.g., "Name", "Item Name", "Product Name" all map to the internal `name` property). Headers are normalized (lowercase, spaces removed) for matching.
2.  **Required Fields (`REQUIRED_FIELDS`):** Specifies which item attributes must be present and valid in each row.
3.  **`parseItemFile(array $uploadedFile)` (Public Method):**
    *   Takes the PHP `$_FILES` entry for the uploaded file.
    *   Determines file type (CSV, XLSX, XLS) by extension.
    *   Calls respective private parsing methods (`parseCsv` or `parseExcel`).
    *   Returns a structured array: `['success' => bool, 'items' => array (parsed item data), 'message' => string, 'errors' => array (file/row errors), 'warnings' => array]`.
4.  **`parseCsv(...)`:**
    *   Uses `fgetcsv` to read CSV data.
    *   Handles header row processing and normalization.
    *   Calls `mapRowToItem` for each data row.
5.  **`parseExcel(...)`:**
    *   Uses the `PhpOffice\PhpSpreadsheet\IOFactory::load()` to read Excel files (requires `phpoffice/phpspreadsheet` library).
    *   Iterates rows and cells, extracting calculated values.
    *   Handles header row and calls `mapRowToItem`.
6.  **`normalizeHeaders(...)`:** Converts read header names to a standard format for reliable mapping.
7.  **`validateHeader(...)`:** Checks if all required columns (as per `HEADER_MAP` and `REQUIRED_FIELDS`) are present in the file's header, adding warnings if not (can be made stricter to error out).
8.  **`mapRowToItem(...)`:**
    *   Maps data from a row (array of cell values) to a structured item data array based on the detected header positions.
    *   Performs data type conversion (e.g., string to int/float, string 'true'/'yes'/'1' to boolean for `stackable`/`tiltable`).
    *   Validates that required fields have valid data (e.g., name not empty, qty/dimensions/weight > 0).
    *   If a row fails validation, an error message detailing the issue and row number is added to the `$rowErrors` array, and `null` is returned for that row.
    *   Successfully parsed rows are returned as associative arrays matching the structure expected by the `/calculation/generatereport` API for each item.

This service provides robust parsing for CSV and Excel files, including flexible header mapping and error reporting, to prepare item data for the calculation engine. The next step is to conduct conceptual testing.
