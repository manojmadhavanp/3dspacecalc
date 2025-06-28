```php
<?php

namespace App\Model;

use DateTime;
use DateTimeZone;

class Search {
    public ?int $searchId = null;        // SearchID INT AUTO_INCREMENT PRIMARY KEY
    public int $uid;                    // UID INT NOT NULL (references users.UID)
    public int $companyId;              // CompanyID INT NOT NULL (references company.CompanyID)
    public ?int $ccid = null;           // CCID INT (Client Company ID, references client.CCID), can be NULL

    public ?string $searchFormData = null;    // TEXT - JSON string of input to calculation
    public ?string $searchReturnData = null;  // TEXT - JSON string of raw output from calculation engine
    public ?string $reportHTML = null;        // TEXT - Stored HTML report (optional)
    public ?string $visualizationData = null; // TEXT - Data for 3D visualization (often part of SearchReturnData)
    public ?string $shareableLink = null;     // VARCHAR(255) UNIQUE (stores the token part or full link)

    public ?DateTime $searchDateTime = null; // TIMESTAMP DEFAULT CURRENT_TIMESTAMP

    public function __construct(
        int $uid,
        int $companyId,
        ?int $ccid = null,
        ?string $searchFormData = null,
        ?string $searchReturnData = null,
        ?string $reportHTML = null,
        ?string $visualizationData = null,
        ?string $shareableLink = null,
        ?int $searchId = null, // For loading existing records
        ?string $searchDateTimeStr = null
    ) {
        $this->uid = $uid;
        $this->companyId = $companyId;
        $this->ccid = $ccid;
        $this->searchFormData = $searchFormData;
        $this->searchReturnData = $searchReturnData;
        $this->reportHTML = $reportHTML;
        $this->visualizationData = $visualizationData; // Often same as searchReturnData
        $this->shareableLink = $shareableLink;
        $this->searchId = $searchId;

        $utc = new DateTimeZone('UTC');
        if ($searchDateTimeStr) {
            try {
                $this->searchDateTime = new DateTime($searchDateTimeStr, $utc);
            } catch (\Exception $e) {
                error_log("Error parsing SearchDateTime string '{$searchDateTimeStr}': " . $e->getMessage());
                $this->searchDateTime = new DateTime('now', $utc); // Fallback
            }
        } else if ($searchId === null) { // If it's a new record not yet saved
            $this->searchDateTime = new DateTime('now', $utc);
        }
        // If $searchId is not null but $searchDateTimeStr is, it means it will be loaded by fromDbRow
    }

    /**
     * Creates a Search object from a database row (associative array).
     */
    public static function fromDbRow(array $row): Search {
        $search = new self(
            (int)$row['UID'],
            (int)$row['CompanyID'],
            isset($row['CCID']) ? (int)$row['CCID'] : null,
            $row['SearchFormData'] ?? null,
            $row['SearchReturnData'] ?? null,
            $row['ReportHTML'] ?? null,
            $row['VisualizationData'] ?? $row['SearchReturnData'] ?? null, // Default viz data to return data
            $row['ShareableLink'] ?? null,
            (int)$row['SearchID'],
            $row['SearchDateTime'] ?? null
        );
        // Ensure searchDateTime is DateTime object if loaded from DB string
        if (is_string($row['SearchDateTime'] ?? null) && $search->searchDateTime === null) {
             try {
                $search->searchDateTime = new DateTime($row['SearchDateTime'], new DateTimeZone('UTC'));
            } catch (\Exception $e) {
                 error_log("Error re-parsing SearchDateTime from DB row for SearchID {$row['SearchID']}: " . $e->getMessage());
            }
        }
        return $search;
    }

    /**
     * Converts Search object to an array, typically for internal use or specific API responses.
     * The main API for viewing a report will likely return the SearchReturnData directly.
     */
    public function toArray(): array {
        return [
            'searchId' => $this->searchId,
            'uid' => $this->uid,
            'companyId' => $this->companyId,
            'ccid' => $this->ccid,
            'searchFormData' => $this->searchFormData ? json_decode($this->searchFormData, true) : null, // Optionally decode
            'searchReturnData' => $this->searchReturnData ? json_decode($this->searchReturnData, true) : null, // Optionally decode
            'reportHTML' => $this->reportHTML,
            'visualizationData' => $this->visualizationData ? json_decode($this->visualizationData, true) : null, // Optionally decode
            'shareableLink' => $this->shareableLink,
            'searchDateTime' => $this->searchDateTime ? $this->searchDateTime->format(DateTime::ATOM) : null,
        ];
    }

    /**
     * Helper to extract just the token from a full shareable link if stored that way.
     * Or, if ShareableLink column stores only the token, this returns it directly.
     */
    public function getShareToken(): ?string {
        if (!$this->shareableLink) {
            return null;
        }
        // Example: if link is "https://.../report/123?token=ABCDEFG"
        // This parsing logic depends on how ShareableLink is constructed and stored.
        // If ShareableLink *is* just the token, then: return $this->shareableLink;
        $parts = parse_url($this->shareableLink);
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
            return $query['token'] ?? null;
        }
        // If the ShareableLink column directly stores the token:
        // return $this->shareableLink;
        // For now, let's assume it's just the token for simplicity of the example.
        return $this->shareableLink; // Assuming ShareableLink column stores just the token itself
    }
}
```

**Key Features of `Search.php` Model:**

1.  **Properties:** Correspond to the `search` table columns. `DateTime` objects for `SearchDateTime`.
2.  **Constructor:** Allows initialization. Handles default `SearchDateTime` to now if creating a new (unsaved) record.
3.  **`fromDbRow(array $row)`:** Static factory to create a `Search` object from a database record.
    *   It sets `visualizationData` to `searchReturnData` if `visualizationData` itself is null, as the main calculation output is often directly usable for visualization.
4.  **`toArray()`:** Converts the object to an array. It optionally decodes the JSON string fields (`searchFormData`, `searchReturnData`, `visualizationData`) into arrays/objects. This is useful if the service layer needs to work with the data but should be used judiciously if these strings are very large. The report viewing API endpoint will likely just pass through the `searchReturnData` string.
5.  **`getShareToken()`:** A conceptual helper method. Its logic depends on whether the `ShareableLink` column stores the full URL or just the unique token part. For this implementation, it assumes `ShareableLink` directly stores the token.

This `Search` model provides a structured way to represent search/calculation records.The `Search` model class has been conceptually defined in `backend/src/Model/Search.php`.

**Key aspects of the `Search.php` model:**

1.  **Properties:** Includes properties corresponding to the columns in the `search` database table: `searchId`, `uid`, `companyId`, `ccid`, `searchFormData` (string), `searchReturnData` (string), `reportHTML` (string), `visualizationData` (string), `shareableLink` (string), and `searchDateTime` (as `?DateTime`).
2.  **Constructor:** Initializes the object. If it's a new record being created (no `searchId` provided), `searchDateTime` defaults to the current time.
3.  **`fromDbRow(array $row)`:** A static factory method to instantiate a `Search` object from an associative array (typically a database row). It handles parsing of the `SearchDateTime` string into a `DateTime` object (UTC). It also has a fallback for `visualizationData` to use `searchReturnData` if the former is not explicitly set.
4.  **`toArray()`:** Converts the `Search` object into an array. For the TEXT fields that store JSON strings (`searchFormData`, `searchReturnData`, `visualizationData`), this method *optionally decodes them into PHP arrays/objects*. This is useful for internal use but for API responses sending the raw JSON string from `searchReturnData` might be preferred.
5.  **`getShareToken()`:** A conceptual helper method to extract the token part if `shareableLink` stores a full URL. Currently, it assumes `shareableLink` stores just the token.

This model provides a structured way to work with saved calculation data. This completes the first step of Phase 4. The next step is "Create `SearchService.php`".
