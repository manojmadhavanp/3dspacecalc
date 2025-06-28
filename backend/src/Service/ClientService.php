```php
<?php

namespace App\Service;

use App\Config\Database;
use App\Model\Client;
use App\Model\ClientContact;
use PDO;
use Ramsey\Uuid\Uuid; // For ClientID generation

class ClientService {
    private PDO $db;

    public function __construct(?PDO $dbConnection = null) {
        $this->db = $dbConnection ?? (new Database())->getConnection();
    }

    /**
     * Get all clients for a given company.
     * @param int $companyId The CompanyID of the company whose clients are to be fetched.
     * @return Client[] Array of Client objects.
     */
    public function getClientsByCompany(int $companyId): array {
        $stmt = $this->db->prepare("SELECT * FROM client WHERE AddedByCompanyID = :companyId ORDER BY CompanyName ASC");
        $stmt->bindParam(':companyId', $companyId, PDO::PARAM_INT);
        $stmt->execute();
        $clients = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $clients[] = Client::fromDbRow($row);
        }
        return $clients;
    }

    /**
     * Add a new client for a company.
     * @param int $addedByCompanyId The CompanyID adding this client.
     * @param array $clientData Associative array of client data (companyName, email, address, optional clientId).
     * @return Client|array Returns the created Client object on success, or an error array.
     */
    public function addClient(int $addedByCompanyId, array $clientData): Client|array {
        $requiredKeys = ['companyName']; // ClientID can be auto-generated
        foreach ($requiredKeys as $key) {
            if (empty($clientData[$key])) throw new \InvalidArgumentException("Client field '$key' is required.");
        }
        if (!empty($clientData['email']) && !filter_var($clientData['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Invalid client email format.");
        }

        $clientId = $clientData['clientId'] ?? ('CLT-' . strtoupper(substr(Uuid::uuid4()->toString(), 0, 8)));

        // Check if ClientID already exists for this company (or globally if ClientID must be globally unique)
        // For now, let's assume ClientID should be unique for the company adding it.
        $stmtCheck = $this->db->prepare("SELECT CCID FROM client WHERE ClientID = :clientId AND AddedByCompanyID = :addedByCompanyId");
        $stmtCheck->bindParam(':clientId', $clientId);
        $stmtCheck->bindParam(':addedByCompanyId', $addedByCompanyId, PDO::PARAM_INT);
        $stmtCheck->execute();
        if ($stmtCheck->fetchColumn()) {
            return ['success' => false, 'message' => "Client ID '{$clientId}' already exists for your company."];
        }

        $sql = "INSERT INTO client (ClientID, CompanyName, Email, Address, AddedByCompanyID)
                VALUES (:clientId, :companyName, :email, :address, :addedByCompanyId)";
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->bindParam(':clientId', $clientId);
            $stmt->bindParam(':companyName', $clientData['companyName']);
            $stmt->bindParam(':email', $clientData['email']);
            $stmt->bindParam(':address', $clientData['address']);
            $stmt->bindParam(':addedByCompanyId', $addedByCompanyId, PDO::PARAM_INT);
            $stmt->execute();
            $ccid = $this->db->lastInsertId();

            // Fetch the newly created client to return the full object
            return $this->getClientByCcid($ccid, $addedByCompanyId);
        } catch (\PDOException $e) {
            error_log("AddClient PDOException: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to add client due to a database error.'];
        }
    }

    /**
     * Get a specific client's details by their CCID, ensuring it belongs to the company.
     * Also fetches associated contacts.
     * @param int $ccid The Client's CCID (Primary Key).
     * @param int $companyId The CompanyID that should own this client.
     * @return Client|null Client object with contacts, or null if not found/not owned.
     */
    public function getClientByCcid(int $ccid, int $companyId): ?Client {
        $stmt = $this->db->prepare("SELECT * FROM client WHERE CCID = :ccid AND AddedByCompanyID = :companyId");
        $stmt->bindParam(':ccid', $ccid, PDO::PARAM_INT);
        $stmt->bindParam(':companyId', $companyId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $client = Client::fromDbRow($row);
        // Fetch contacts for this client
        $client->contacts = $this->getClientContacts($ccid);
        return $client;
    }

    /**
     * Update an existing client.
     * @param int $ccid The CCID of the client to update.
     * @param int $companyId The CompanyID that owns this client (for verification).
     * @param array $updateData Associative array of data to update (companyName, email, address, clientId).
     * @return Client|array Updated Client object or error array.
     */
    public function updateClient(int $ccid, int $companyId, array $updateData): Client|array {
        $client = $this->getClientByCcid($ccid, $companyId); // Verifies ownership
        if (!$client) return ['success' => false, 'message' => 'Client not found or access denied.'];

        // Prepare SQL update statement (only update fields that are provided)
        $fields = []; $params = [':ccid' => $ccid, ':companyId' => $companyId];
        if (!empty($updateData['companyName'])) { $fields[] = "CompanyName = :companyName"; $params[':companyName'] = $updateData['companyName']; }
        if (isset($updateData['email'])) { // Allow setting email to null or empty
            if(!empty($updateData['email']) && !filter_var($updateData['email'], FILTER_VALIDATE_EMAIL)){
                 throw new \InvalidArgumentException("Invalid client email format for update.");
            }
            $fields[] = "Email = :email"; $params[':email'] = $updateData['email'] ?: null;
        }
        if (isset($updateData['address'])) { $fields[] = "Address = :address"; $params[':address'] = $updateData['address'] ?: null; }
        if (!empty($updateData['clientId'])) { // If changing ClientID, check for uniqueness again
            if($updateData['clientId'] !== $client->clientId) {
                $stmtCheck = $this->db->prepare("SELECT CCID FROM client WHERE ClientID = :clientId AND AddedByCompanyID = :companyId AND CCID != :currentCcid");
                $stmtCheck->execute([':clientId' => $updateData['clientId'], ':companyId' => $companyId, ':currentCcid' => $ccid]);
                if ($stmtCheck->fetchColumn()) {
                    return ['success' => false, 'message' => "New Client ID '{$updateData['clientId']}' already exists for your company."];
                }
            }
            $fields[] = "ClientID = :clientId"; $params[':clientId'] = $updateData['clientId'];
        }

        if (empty($fields)) return ['success' => false, 'message' => 'No data provided for update.'];

        $sql = "UPDATE client SET " . implode(', ', $fields) . " WHERE CCID = :ccid AND AddedByCompanyID = :companyId";
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $this->getClientByCcid($ccid, $companyId); // Return updated client
        } catch (\PDOException $e) {
            error_log("UpdateClient PDOException: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to update client due to a database error.'];
        }
    }

    /**
     * Delete a client (and its contacts due to ON DELETE CASCADE).
     * @param int $ccid The CCID of the client to delete.
     * @param int $companyId The CompanyID that owns this client.
     * @return array ['success' => bool, 'message' => string]
     */
    public function deleteClient(int $ccid, int $companyId): array {
        // Verify client belongs to company before deleting
        $client = $this->getClientByCcid($ccid, $companyId);
        if (!$client) return ['success' => false, 'message' => 'Client not found or access denied.'];

        $sql = "DELETE FROM client WHERE CCID = :ccid AND AddedByCompanyID = :companyId";
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->bindParam(':ccid', $ccid, PDO::PARAM_INT);
            $stmt->bindParam(':companyId', $companyId, PDO::PARAM_INT);
            $stmt->execute();
            return ['success' => $stmt->rowCount() > 0, 'message' => $stmt->rowCount() > 0 ? 'Client deleted successfully.' : 'Client deletion failed or client not found.'];
        } catch (\PDOException $e) {
            error_log("DeleteClient PDOException: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to delete client due to a database error.'];
        }
    }

    // --- Client Contact Methods ---

    public function getClientContacts(int $ccid): array {
        $stmt = $this->db->prepare("SELECT * FROM client_contacts WHERE CCID = :ccid ORDER BY ContactName ASC");
        $stmt->bindParam(':ccid', $ccid, PDO::PARAM_INT);
        $stmt->execute();
        $contacts = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $contacts[] = ClientContact::fromDbRow($row);
        }
        return $contacts;
    }

    /**
     * Add a contact to a specific client.
     * @param int $ccid Client's CCID.
     * @param int $companyId Owning company's CompanyID (to verify client ownership).
     * @param array $contactData (contactName, email, phoneNumber).
     * @return ClientContact|array New ClientContact object or error array.
     */
    public function addClientContact(int $ccid, int $companyId, array $contactData): ClientContact|array {
        // First, verify the client (ccid) belongs to the company
        $client = $this->getClientByCcid($ccid, $companyId);
        if (!$client) return ['success' => false, 'message' => 'Client not found or access denied for adding contact.'];

        if (empty($contactData['contactName'])) throw new \InvalidArgumentException("Contact name is required.");
        if (!empty($contactData['email']) && !filter_var($contactData['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Invalid contact email format.");
        }

        $sql = "INSERT INTO client_contacts (CCID, ContactName, Email, PhoneNumber)
                VALUES (:ccid, :contactName, :email, :phoneNumber)";
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->bindParam(':ccid', $ccid, PDO::PARAM_INT);
            $stmt->bindParam(':contactName', $contactData['contactName']);
            $stmt->bindParam(':email', $contactData['email']);
            $stmt->bindParam(':phoneNumber', $contactData['phoneNumber']);
            $stmt->execute();
            $cccid = $this->db->lastInsertId();
            // Fetch and return the newly created contact
            $newContactStmt = $this->db->prepare("SELECT * FROM client_contacts WHERE CCCID = :cccid");
            $newContactStmt->bindParam(':cccid', $cccid, PDO::PARAM_INT);
            $newContactStmt->execute();
            $contactRow = $newContactStmt->fetch(PDO::FETCH_ASSOC);
            return $contactRow ? ClientContact::fromDbRow($contactRow) : ['success' => false, 'message' => 'Failed to retrieve newly added contact.'];

        } catch (\PDOException $e) {
            error_log("AddClientContact PDOException: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to add contact due to a database error.'];
        }
    }

    /**
     * Update a client contact.
     * @param int $cccid Contact's CCCID.
     * @param int $ccid Client's CCID (for verification).
     * @param int $companyId Owning company's CompanyID (for verification).
     * @param array $updateData Data to update.
     * @return ClientContact|array Updated contact or error array.
     */
    public function updateClientContact(int $cccid, int $ccid, int $companyId, array $updateData): ClientContact|array {
        // Verify client ownership first
        $client = $this->getClientByCcid($ccid, $companyId);
        if (!$client) return ['success' => false, 'message' => 'Client not found or access denied for updating contact.'];

        // Verify contact belongs to this client
        $contactStmt = $this->db->prepare("SELECT * FROM client_contacts WHERE CCCID = :cccid AND CCID = :ccid");
        $contactStmt->execute([':cccid' => $cccid, ':ccid' => $ccid]);
        $existingContact = $contactStmt->fetch(PDO::FETCH_ASSOC);
        if (!$existingContact) return ['success' => false, 'message' => 'Contact not found for this client.'];


        $fields = []; $params = [':cccid' => $cccid, ':ccid' => $ccid];
        if (!empty($updateData['contactName'])) { $fields[] = "ContactName = :contactName"; $params[':contactName'] = $updateData['contactName']; }
        if (isset($updateData['email'])) {
             if(!empty($updateData['email']) && !filter_var($updateData['email'], FILTER_VALIDATE_EMAIL)){
                 throw new \InvalidArgumentException("Invalid contact email format for update.");
            }
            $fields[] = "Email = :email"; $params[':email'] = $updateData['email'] ?: null;
        }
        if (isset($updateData['phoneNumber'])) { $fields[] = "PhoneNumber = :phoneNumber"; $params[':phoneNumber'] = $updateData['phoneNumber'] ?: null; }

        if (empty($fields)) return ['success' => false, 'message' => 'No data provided for contact update.'];

        $sql = "UPDATE client_contacts SET " . implode(', ', $fields) . " WHERE CCCID = :cccid AND CCID = :ccid";
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            $updatedContactStmt = $this->db->prepare("SELECT * FROM client_contacts WHERE CCCID = :cccid");
            $updatedContactStmt->bindParam(':cccid', $cccid, PDO::PARAM_INT);
            $updatedContactStmt->execute();
            $contactRow = $updatedContactStmt->fetch(PDO::FETCH_ASSOC);
            return $contactRow ? ClientContact::fromDbRow($contactRow) : ['success' => false, 'message' => 'Failed to retrieve updated contact.'];

        } catch (\PDOException $e) {
            error_log("UpdateClientContact PDOException: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to update contact due to a database error.'];
        }
    }

    /**
     * Delete a client contact.
     * @param int $cccid Contact's CCCID.
     * @param int $ccid Client's CCID (for verification).
     * @param int $companyId Owning company's CompanyID (for verification).
     * @return array Success/failure message.
     */
    public function deleteClientContact(int $cccid, int $ccid, int $companyId): array {
        $client = $this->getClientByCcid($ccid, $companyId);
        if (!$client) return ['success' => false, 'message' => 'Client not found or access denied for deleting contact.'];

        $sql = "DELETE FROM client_contacts WHERE CCCID = :cccid AND CCID = :ccid";
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->bindParam(':cccid', $cccid, PDO::PARAM_INT);
            $stmt->bindParam(':ccid', $ccid, PDO::PARAM_INT);
            $stmt->execute();
            return ['success' => $stmt->rowCount() > 0, 'message' => $stmt->rowCount() > 0 ? 'Contact deleted successfully.' : 'Contact deletion failed or contact not found.'];
        } catch (\PDOException $e) {
            error_log("DeleteClientContact PDOException: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to delete contact due to a database error.'];
        }
    }
}
```
