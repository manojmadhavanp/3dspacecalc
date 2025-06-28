```php
<?php

namespace App\Service;

use App\Config\Database;
use App\Model\User; // Assuming a User model similar to Client, Item etc. will be needed or data is raw array
use PDO;
use Ramsey\Uuid\Uuid;

class UserService {
    private PDO $db;

    public function __construct(?PDO $dbConnection = null) {
        $this->db = $dbConnection ?? (new Database())->getConnection();
    }

    /**
     * Get all users for a given company, including their role and status.
     * @param int $companyId The CompanyID.
     * @return array Array of user data (associative arrays, not full User objects yet).
     */
    public function getUsersByCompanyId(int $companyId): array {
        $sql = "SELECT u.UID, u.UUID, u.FirstName, u.MiddleName, u.LastName, u.PhoneNumber, u.Email,
                       r.RoleName, r.RoleRUID, a.Status as AuthStatus, a.LastLogin
                FROM users u
                JOIN roles r ON u.RID = r.RID
                JOIN auth a ON u.UID = a.UID
                WHERE u.CompanyID = :companyId
                ORDER BY u.LastName ASC, u.FirstName ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':companyId', $companyId, PDO::PARAM_INT);
        $stmt->execute();

        $usersData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Convert LastLogin to a more friendly format or keep as is
        foreach($usersData as &$user) {
            if ($user['LastLogin']) {
                // Example formatting, adjust as needed for frontend
                // $user['LastLogin'] = (new \DateTime($user['LastLogin']))->format(\DateTime::ATOM);
            }
        }
        return $usersData; // Returns array of associative arrays
    }

    /**
     * Adds a new user to a specific company.
     * Called by a CompanyAdmin.
     *
     * @param int $actorCompanyId The CompanyID of the admin performing the action.
     * @param array $newUserData ['firstName', 'lastName', 'middleName' (opt), 'email', 'phoneNumber', 'password', 'RID' (role ID for new user)]
     * @return array ['success' => bool, 'message' => string, 'data' => array (optional: new user's UUID)]
     */
    public function addUserToCompany(int $actorCompanyId, array $newUserData): array {
        // --- Input Validation ---
        $requiredKeys = ['firstName', 'lastName', 'email', 'phoneNumber', 'password', 'RID'];
        foreach ($requiredKeys as $key) {
            if (empty($newUserData[$key])) throw new \InvalidArgumentException("New user field '$key' is required.");
        }
        if (!filter_var($newUserData['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Invalid new user email format.");
        }
        if (strlen($newUserData['password']) < 8) { // Consistent with registration password policy
            throw new \InvalidArgumentException("Password must be at least 8 characters long.");
        }
        if (!is_numeric($newUserData['RID']) || (int)$newUserData['RID'] <= 0) {
             throw new \InvalidArgumentException("Valid Role ID (RID) is required.");
        }
        $newUserRid = (int)$newUserData['RID'];

        // --- Check for existing user (email/phone globally) ---
        // This reuses logic that could be in AuthService or a shared UserValidationService
        $stmtCheckUser = $this->db->prepare("SELECT UID FROM users WHERE Email = :email OR PhoneNumber = :phone");
        $stmtCheckUser->execute([':email' => $newUserData['email'], ':phone' => $newUserData['phoneNumber']]);
        if ($stmtCheckUser->fetchColumn()) {
            return ['success' => false, 'message' => 'A user with this email or phone number already exists in the system.'];
        }

        $this->db->beginTransaction();
        try {
            // 1. Check Subscription MaxUsers limit
            $stmtSub = $this->db->prepare(
                "SELECT cs.MaxUsers, COUNT(u.UID) as CurrentUsers
                 FROM company_subscription cs
                 LEFT JOIN users u ON cs.CompanyID = u.CompanyID
                                  AND u.UID IN (SELECT UID FROM auth WHERE Status != 'inactive') -- Count active/trial/suspended
                 WHERE cs.CompanyID = :companyId
                 GROUP BY cs.MaxUsers"
            );
            $stmtSub->bindParam(':companyId', $actorCompanyId, PDO::PARAM_INT);
            $stmtSub->execute();
            $subscriptionInfo = $stmtSub->fetch(PDO::FETCH_ASSOC);

            if (!$subscriptionInfo) {
                throw new \Exception("Subscription information not found for company ID {$actorCompanyId}. Cannot add user.");
            }
            if ($subscriptionInfo['CurrentUsers'] >= $subscriptionInfo['MaxUsers']) {
                $this->db->rollBack(); // No changes made yet, but good practice
                return ['success' => false, 'message' => 'Cannot add new user. Maximum user limit ('.$subscriptionInfo['MaxUsers'].') for the company subscription has been reached.'];
            }

            // 2. Verify the provided RID is valid and perhaps assignable (e.g., not a system-level Admin role)
            $stmtRoleCheck = $this->db->prepare("SELECT RoleName FROM roles WHERE RID = :rid");
            $stmtRoleCheck->bindParam(':rid', $newUserRid, PDO::PARAM_INT);
            $stmtRoleCheck->execute();
            $roleName = $stmtRoleCheck->fetchColumn();
            if (!$roleName) {
                 throw new \InvalidArgumentException("Invalid Role ID (RID) provided for the new user.");
            }
            // Optional: Prevent assigning 'Admin' (system admin) role this way
            if ($roleName === 'Admin') { // Assuming 'Admin' is the RoleName for system-wide admin
                 throw new \InvalidArgumentException("System Admin role cannot be assigned via company user management.");
            }


            // 3. Create User record
            $userUUID = Uuid::uuid4()->toString();
            $sqlUser = "INSERT INTO users (UUID, FirstName, MiddleName, LastName, PhoneNumber, Email, CompanyID, RID)
                        VALUES (:uuid, :firstName, :middleName, :lastName, :phoneNumber, :email, :companyId, :rid)";
            $stmtUser = $this->db->prepare($sqlUser);
            $stmtUser->execute([
                ':uuid' => $userUUID,
                ':firstName' => $newUserData['firstName'],
                ':middleName' => $newUserData['middleName'] ?? null,
                ':lastName' => $newUserData['lastName'],
                ':phoneNumber' => $newUserData['phoneNumber'],
                ':email' => $newUserData['email'],
                ':companyId' => $actorCompanyId, // User belongs to the actor's company
                ':rid' => $newUserRid
            ]);
            $newUserId = $this->db->lastInsertId();

            // 4. Create Auth record
            $hashedPassword = password_hash($newUserData['password'], PASSWORD_ARGON2ID);
            // New users added by admin could be 'active' or 'pending_confirmation' if email verification is added
            $initialAuthStatus = 'active';
            $sqlAuth = "INSERT INTO auth (UID, PasswordEncrypted, Status)
                        VALUES (:uid, :passwordEncrypted, :status)";
            $stmtAuth = $this->db->prepare($sqlAuth);
            $stmtAuth->execute([
                ':uid' => $newUserId,
                ':passwordEncrypted' => $hashedPassword,
                ':status' => $initialAuthStatus
            ]);

            $this->db->commit();
            return ['success' => true, 'message' => 'User added successfully to the company.', 'data' => ['userUUID' => $userUUID, 'uid' => $newUserId]];

        } catch (\PDOException $e) {
            $this->db->rollBack();
            error_log("AddUserToCompany PDOException: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to add user due to a database error.'];
        } catch (\Exception $e) {
            $this->db->rollBack();
            error_log("AddUserToCompany Exception: " . $e->getMessage());
            return ['success' => false, 'message' => 'An unexpected error occurred: ' . $e->getMessage()];
        }
    }

    /**
     * Updates the status of a user within a specific company.
     *
     * @param int $actorCompanyId CompanyID of the admin performing action.
     * @param int $targetUserUid UID of the user to update.
     * @param string $newAuthStatus New status from ENUM('active', 'inactive', 'suspended').
     * @return array ['success' => bool, 'message' => string]
     */
    public function updateUserStatusInCompany(int $actorCompanyId, int $targetUserUid, string $newAuthStatus): array {
        // Validate newAuthStatus against ENUM values
        $validStatuses = ['active', 'inactive', 'suspended']; // 'trial' is usually set at creation/payment
        if (!in_array($newAuthStatus, $validStatuses)) {
            throw new \InvalidArgumentException("Invalid status provided. Must be one of: " . implode(', ', $validStatuses));
        }

        // Check if targetUserUid belongs to actorCompanyId
        $stmtCheck = $this->db->prepare("SELECT UID FROM users WHERE UID = :targetUserUid AND CompanyID = :actorCompanyId");
        $stmtCheck->execute([':targetUserUid' => $targetUserUid, ':actorCompanyId' => $actorCompanyId]);
        if (!$stmtCheck->fetchColumn()) {
            return ['success' => false, 'message' => 'User not found in your company or access denied.'];
        }

        // Prevent CompanyAdmin from deactivating themselves if they are the only CompanyAdmin (optional safety)
        // This logic would be more complex: check role and count of other admins. Skipped for now.

        try {
            $sql = "UPDATE auth SET Status = :newStatus WHERE UID = :targetUserUid";
            $stmt = $this->db->prepare($sql);
            $stmt->bindParam(':newStatus', $newAuthStatus);
            $stmt->bindParam(':targetUserUid', $targetUserUid, PDO::PARAM_INT);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                return ['success' => true, 'message' => "User status updated to '{$newAuthStatus}'."];
            } else {
                // This might happen if status was already $newAuthStatus, or UID not in auth table (data integrity issue)
                return ['success' => false, 'message' => 'User status not changed. User may not exist in auth table or status is already set.'];
            }
        } catch (\PDOException $e) {
            error_log("UpdateUserStatus PDOException: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to update user status due to a database error.'];
        }
    }

    /**
     * Updates the role of a user within a specific company. (Optional Feature)
     *
     * @param int $actorCompanyId CompanyID of the admin.
     * @param int $targetUserUid UID of the user to update.
     * @param int $newRoleId New RID for the user.
     * @return array ['success' => bool, 'message' => string]
     */
    public function updateUserRoleInCompany(int $actorCompanyId, int $targetUserUid, int $newRoleId): array {
        // Validate newRoleId (e.g., exists in 'roles' table and is assignable, not system 'Admin')
        $stmtRoleCheck = $this->db->prepare("SELECT RoleName FROM roles WHERE RID = :rid");
        $stmtRoleCheck->bindParam(':rid', $newRoleId, PDO::PARAM_INT);
        $stmtRoleCheck->execute();
        $roleName = $stmtRoleCheck->fetchColumn();
        if (!$roleName) {
            throw new \InvalidArgumentException("Invalid new Role ID (RID) provided.");
        }
        if ($roleName === 'Admin') { // System-wide Admin
            throw new \InvalidArgumentException("System Admin role cannot be assigned via company user management.");
        }

        // Check if targetUserUid belongs to actorCompanyId
        $stmtCheck = $this->db->prepare("SELECT UID FROM users WHERE UID = :targetUserUid AND CompanyID = :actorCompanyId");
        $stmtCheck->execute([':targetUserUid' => $targetUserUid, ':actorCompanyId' => $actorCompanyId]);
        if (!$stmtCheck->fetchColumn()) {
            return ['success' => false, 'message' => 'User not found in your company or access denied.'];
        }

        // Prevent CompanyAdmin from changing their own role if they are the only CompanyAdmin (optional safety)
        // Skipped for now.

        try {
            $sql = "UPDATE users SET RID = :newRoleId WHERE UID = :targetUserUid AND CompanyID = :actorCompanyId";
            $stmt = $this->db->prepare($sql);
            $stmt->bindParam(':newRoleId', $newRoleId, PDO::PARAM_INT);
            $stmt->bindParam(':targetUserUid', $targetUserUid, PDO::PARAM_INT);
            $stmt->bindParam(':actorCompanyId', $actorCompanyId, PDO::PARAM_INT); // Extra check
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                return ['success' => true, 'message' => "User role updated successfully."];
            } else {
                return ['success' => false, 'message' => 'User role not changed. Role may already be set or user not found.'];
            }
        } catch (\PDOException $e) {
            error_log("UpdateUserRole PDOException: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to update user role due to a database error.'];
        }
    }
}
```

**Key Features of `UserService.php`:**

1.  **`getUsersByCompanyId(int $companyId)`:**
    *   Fetches users joined with `roles` and `auth` tables to get `RoleName`, `RoleRUID`, and `AuthStatus`.
    *   Returns an array of associative arrays (could be mapped to `User` model objects if a full `User` model with these joined properties was defined).

2.  **`addUserToCompany(int $actorCompanyId, array $newUserData)`:**
    *   Performs input validation.
    *   Checks for globally unique email/phone for the new user.
    *   **Subscription Check:** Counts current active/non-inactive users in the company and compares against `MaxUsers` from `company_subscription`. Returns an error if limit reached.
    *   Validates the provided `RID` for the new user (ensuring it's a valid role and not, e.g., the system-level 'Admin' role).
    *   Creates records in `users` and `auth` tables within a transaction.
    *   Sets initial auth status to 'active' (could be 'pending_confirmation' if email verification was part of the flow).

3.  **`updateUserStatusInCompany(int $actorCompanyId, int $targetUserUid, string $newAuthStatus)`:**
    *   Validates the `newAuthStatus` against the ENUM definition.
    *   Verifies that the `targetUserUid` belongs to the `actorCompanyId`.
    *   Updates the `auth.Status`.

4.  **`updateUserRoleInCompany(int $actorCompanyId, int $targetUserUid, int $newRoleId)`:**
    *   Validates the `newRoleId`.
    *   Verifies `targetUserUid` belongs to `actorCompanyId`.
    *   Updates `users.RID`.

**Assumptions/TODOs:**
*   A `User` model (`App\Model\User`) might be created later to strongly type user objects, similar to `Client.php`. For now, `getUsersByCompanyId` returns associative arrays.
*   The logic for preventing a CompanyAdmin from deactivating/changing the role of the *last* CompanyAdmin in a company is noted as an optional safety measure but not implemented for brevity.
*   Global uniqueness check for email/phone in `addUserToCompany` is present.
*   The `actorRoleRUID` passed to service methods would be used by the *Controller* for initial authorization before calling the service. The service methods themselves primarily ensure data integrity and that actions are performed on users within the specified `actorCompanyId`.

This service class provides the core business logic for company user management. The next step is to create `CompanyUserController.php`.The `UserService.php` has been conceptually created.

**Key features of `UserService.php`:**

1.  **Constructor:** Initializes a database connection.
2.  **`getUsersByCompanyId(int $companyId)`:**
    *   Fetches users for a given `$companyId`.
    *   Joins with `roles` and `auth` tables to include `RoleName`, `RoleRUID`, `AuthStatus` (user's current status like 'active', 'inactive'), and `LastLogin`.
    *   Returns an array of associative arrays containing this user data.

3.  **`addUserToCompany(int $actorCompanyId, array $newUserData)`:**
    *   Performs input validation on `$newUserData` (required fields, email format, password length, valid `RID`).
    *   Checks for global uniqueness of the new user's email and phone number.
    *   **Subscription Limit Check:** Queries the `company_subscription` table for `MaxUsers` and compares it against the current number of non-inactive users in the `$actorCompanyId`. If the limit is reached, it returns an error.
    *   Validates the provided `RID` for the new user (ensuring it's a valid role and not a system-level 'Admin' role if such a distinction exists).
    *   Within a database transaction:
        *   Creates a new record in the `users` table, linking to `$actorCompanyId` and the new user's `RID`. Generates a UUID for the new user.
        *   Creates a corresponding record in the `auth` table, hashing the provided password and setting an initial status (e.g., 'active').
    *   Returns a success message with the new user's UUID or an error message.

4.  **`updateUserStatusInCompany(int $actorCompanyId, int $targetUserUid, string $newAuthStatus)`:**
    *   Validates `newAuthStatus` against the allowed ENUM values from the `auth.Status` column.
    *   Crucially, verifies that the `targetUserUid` (the user whose status is being changed) belongs to the `$actorCompanyId` (the company of the admin performing the action).
    *   Updates the `auth.Status` for the `targetUserUid`.
    *   Returns a success or failure message.

5.  **`updateUserRoleInCompany(int $actorCompanyId, int $targetUserUid, int $newRoleId)` (Optional feature):**
    *   Validates the `newRoleId` (e.g., ensuring it exists and is an assignable role).
    *   Verifies that `targetUserUid` belongs to `$actorCompanyId`.
    *   Updates the `users.RID` for the `targetUserUid`.
    *   Returns a success or failure message.

This service class provides the core business logic for managing users within the scope of a company, including adherence to subscription limits. The next step is to create `CompanyUserController.php` to expose these functionalities via API endpoints.
