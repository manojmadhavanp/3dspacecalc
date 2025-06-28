```php
<?php

namespace App\Model;

use DateTime;
use DateTimeZone;

class Client {
    public ?int $ccid = null; // Client Company ID (Primary Key)
    public string $clientId;   // Unique ClientID (e.g., user-defined or generated like CUST-001)
    public string $companyName;
    public ?string $email = null;
    public ?string $address = null;
    public int $addedByCompanyId; // The CompanyID from 'company' table that added this client
    public ?DateTime $createdOn = null;
    public ?DateTime $updatedOn = null;

    // Optional: To hold associated ClientContact objects
    public array $contacts = [];

    public function __construct(
        string $clientId,
        string $companyName,
        int $addedByCompanyId,
        ?string $email = null,
        ?string $address = null,
        ?int $ccid = null // Allow setting PK if loading from DB
    ) {
        $this->clientId = $clientId;
        $this->companyName = $companyName;
        $this->addedByCompanyId = $addedByCompanyId;
        $this->email = $email;
        $this->address = $address;
        if ($ccid !== null) {
            $this->ccid = $ccid;
        }
        // CreatedOn and UpdatedOn are typically handled by DB defaults or on load
    }

    // Example: Method to add a contact to this client object
    public function addContact(ClientContact $contact): void {
        if ($contact->ccid === $this->ccid || $contact->ccid === null) { // Ensure contact belongs to this client
            $this->contacts[] = $contact;
        } else {
            // Or throw an exception if contact is for a different client
            error_log("Attempted to add contact with CCID {$contact->ccid} to Client {$this->ccid}");
        }
    }

    /**
     * Creates a Client object from a database row (associative array).
     * @param array $row Data from the database.
     * @return Client
     */
    public static function fromDbRow(array $row): Client {
        $client = new self(
            $row['ClientID'],
            $row['CompanyName'],
            (int)$row['AddedByCompanyID'],
            $row['Email'] ?? null,
            $row['Address'] ?? null,
            (int)$row['CCID']
        );
        try {
            if (!empty($row['CreatedOn'])) {
                $client->createdOn = new DateTime($row['CreatedOn'], new DateTimeZone('UTC'));
            }
            if (!empty($row['UpdatedOn'])) {
                $client->updatedOn = new DateTime($row['UpdatedOn'], new DateTimeZone('UTC'));
            }
        } catch (\Exception $e) {
            error_log("Error parsing date for Client CCID {$row['CCID']}: " . $e->getMessage());
            // Dates remain null or default
        }
        return $client;
    }

    /**
     * Converts Client object to an array for JSON response, excluding sensitive/internal data.
     * @return array
     */
    public function toArray(): array {
        $data = [
            'ccid' => $this->ccid,
            'clientId' => $this->clientId,
            'companyName' => $this->companyName,
            'email' => $this->email,
            'address' => $this->address,
            'addedByCompanyId' => $this->addedByCompanyId, // Might not be needed in all client-facing responses
            'createdOn' => $this->createdOn ? $this->createdOn->format(DateTime::ATOM) : null,
            'updatedOn' => $this->updatedOn ? $this->updatedOn->format(DateTime::ATOM) : null,
            'contacts' => []
        ];
        foreach ($this->contacts as $contact) {
            if ($contact instanceof ClientContact) {
                $data['contacts'][] = $contact->toArray();
            }
        }
        return $data;
    }
}
```
