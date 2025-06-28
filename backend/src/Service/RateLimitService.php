```php
<?php

namespace App\Service;

use App\Config\Database;
use PDO;

class RateLimitService {
    private PDO $db;

    // Define limits (could be moved to a config file or .env variables)
    // Example: Max 10 views per specific report link per IP per hour
    private const LIMIT_PER_IDENTIFIER_PER_IP_COUNT = 10;
    private const LIMIT_PER_IDENTIFIER_PER_IP_WINDOW_SECONDS = 3600; // 1 hour

    // Example: Max 100 report views globally per IP per hour
    private const LIMIT_GLOBAL_PER_IP_COUNT = 100;
    private const LIMIT_GLOBAL_PER_IP_WINDOW_SECONDS = 3600; // 1 hour

    public function __construct(?PDO $dbConnection = null) {
        $this->db = $dbConnection ?? (new Database())->getConnection();
    }

    /**
     * Checks if an access attempt is within defined rate limits and logs the attempt.
     *
     * @param string $ipAddress The IP address of the requester.
     * @param string $identifier The specific resource identifier being accessed (e.g., shareableIdentifier).
     * @param string $actionType A string identifying the type of action (e.g., 'report_view', 'api_login_attempt').
     * @return array ['allowed' => bool, 'message' => string, 'retryAfterSeconds' => ?int]
     */
    public function checkAndLogAccess(string $ipAddress, string $identifier, string $actionType): array {
        if (empty($ipAddress) || empty($identifier) || empty($actionType)) {
            throw new \InvalidArgumentException("IP address, identifier, and action type are required for rate limiting.");
        }

        // --- Check limit for specific identifier + IP ---
        $windowStartSpecific = date('Y-m-d H:i:s', time() - self::LIMIT_PER_IDENTIFIER_PER_IP_WINDOW_SECONDS);
        $sqlSpecific = "SELECT COUNT(LogID) as AttemptCount
                        FROM ip_access_logs
                        WHERE IPAddress = :ipAddress
                          AND AccessedIdentifier = :identifier
                          AND ActionType = :actionType
                          AND AccessTimestamp >= :windowStart";
        $stmtSpecific = $this->db->prepare($sqlSpecific);
        $stmtSpecific->execute([
            ':ipAddress' => $ipAddress,
            ':identifier' => $identifier,
            ':actionType' => $actionType,
            ':windowStart' => $windowStartSpecific
        ]);
        $specificCount = (int)($stmtSpecific->fetchColumn() ?: 0);

        if ($specificCount >= self::LIMIT_PER_IDENTIFIER_PER_IP_COUNT) {
            // TODO: Calculate Retry-After more precisely based on oldest log in window
            return ['allowed' => false, 'message' => 'Too many requests for this specific resource from your IP. Please try again later.', 'retryAfterSeconds' => self::LIMIT_PER_IDENTIFIER_PER_IP_WINDOW_SECONDS / 2];
        }

        // --- Check global limit for IP + ActionType ---
        $windowStartGlobal = date('Y-m-d H:i:s', time() - self::LIMIT_GLOBAL_PER_IP_WINDOW_SECONDS);
        $sqlGlobal = "SELECT COUNT(LogID) as AttemptCount
                      FROM ip_access_logs
                      WHERE IPAddress = :ipAddress
                        AND ActionType = :actionType
                        AND AccessTimestamp >= :windowStart";
        $stmtGlobal = $this->db->prepare($sqlGlobal);
        $stmtGlobal->execute([
            ':ipAddress' => $ipAddress,
            ':actionType' => $actionType,
            ':windowStart' => $windowStartGlobal
        ]);
        $globalCount = (int)($stmtGlobal->fetchColumn() ?: 0);

        if ($globalCount >= self::LIMIT_GLOBAL_PER_IP_COUNT) {
            // TODO: Calculate Retry-After
            return ['allowed' => false, 'message' => 'Too many requests of this type from your IP. Please try again later.', 'retryAfterSeconds' => self::LIMIT_GLOBAL_PER_IP_WINDOW_SECONDS / 2];
        }

        // If all limits are fine, log this access attempt
        try {
            $sqlLog = "INSERT INTO ip_access_logs (IPAddress, AccessedIdentifier, ActionType, UserAgent)
                       VALUES (:ipAddress, :identifier, :actionType, :userAgent)";
            $stmtLog = $this->db->prepare($sqlLog);
            $stmtLog->execute([
                ':ipAddress' => $ipAddress,
                ':identifier' => $identifier,
                ':actionType' => $actionType,
                ':userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
            return ['allowed' => true, 'message' => 'Access permitted.', 'remaining_specific' => self::LIMIT_PER_IDENTIFIER_PER_IP_COUNT - ($specificCount +1) , 'remaining_global' => self::LIMIT_GLOBAL_PER_IP_COUNT - ($globalCount+1)];
        } catch (\PDOException $e) {
            error_log("RateLimitService: Failed to log access for IP {$ipAddress}, Identifier {$identifier}: " . $e->getMessage());
            // Fail open or closed? If logging fails, should we still allow access?
            // For now, let's fail open if logging is the issue but limits were not hit.
            return ['allowed' => true, 'message' => 'Access permitted (logging issue).', 'remaining_specific' => -1, 'remaining_global' => -1]; // Indicate logging issue
        }
    }
}
```

**Key Features of `RateLimitService.php`:**

1.  **Constructor:** Initializes database connection.
2.  **Rate Limit Definitions (Constants):**
    *   `LIMIT_PER_IDENTIFIER_PER_IP_COUNT`: Max attempts for a specific resource (e.g., a shareable link) from one IP within a time window.
    *   `LIMIT_PER_IDENTIFIER_PER_IP_WINDOW_SECONDS`: The time window for the specific limit.
    *   `LIMIT_GLOBAL_PER_IP_COUNT`: Max attempts for any resource of a given `actionType` from one IP within a time window.
    *   `LIMIT_GLOBAL_PER_IP_WINDOW_SECONDS`: The time window for the global limit.
    *   *These limits are currently hardcoded as constants. For more flexibility, they should be moved to a configuration file or database table, potentially varying per `actionType`.*
3.  **`checkAndLogAccess(...)` Method:**
    *   Takes `$ipAddress`, `$identifier` (e.g., the shareableIdentifier), and `$actionType` (e.g., 'report_view').
    *   **Specific Limit Check:** Queries the `ip_access_logs` table to count attempts for the exact `ipAddress` + `identifier` + `actionType` within `LIMIT_PER_IDENTIFIER_PER_IP_WINDOW_SECONDS`. If count exceeds `LIMIT_PER_IDENTIFIER_PER_IP_COUNT`, returns `allowed: false`.
    *   **Global Limit Check:** Queries `ip_access_logs` to count attempts for the `ipAddress` + `actionType` (across all identifiers) within `LIMIT_GLOBAL_PER_IP_WINDOW_SECONDS`. If count exceeds `LIMIT_GLOBAL_PER_IP_COUNT`, returns `allowed: false`.
    *   **Log Access:** If both limits pass, it inserts a new record into `ip_access_logs` with the current IP, identifier, action type, and `UserAgent`.
    *   **Return Value:** An array `['allowed' => bool, 'message' => string, 'retryAfterSeconds' => ?int (TODO), 'remaining_specific' => int, 'remaining_global' => int]`.
        *   `retryAfterSeconds` is a placeholder for a more precise calculation if desired.
        *   `remaining_...` gives an idea of remaining quota.
        *   If DB logging fails but limits were not hit, it currently "fails open" (allows access) but logs the error. This behavior might need adjustment based on security policy.
4.  **Error Handling:** Basic `PDOException` handling for the logging insert.

**Database Schema for `ip_access_logs` (Assumed by this Service):**
```sql
CREATE TABLE ip_access_logs (
    LogID INT AUTO_INCREMENT PRIMARY KEY,
    IPAddress VARCHAR(45) NOT NULL, -- Accommodate IPv6
    AccessedIdentifier VARCHAR(255) NOT NULL, -- e.g., the shareableIdentifier
    ActionType VARCHAR(50) NOT NULL, -- e.g., 'report_view', 'login_attempt'
    AccessTimestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UserAgent TEXT NULL,
    INDEX idx_ip_action_time (IPAddress, ActionType, AccessTimestamp),
    INDEX idx_ip_identifier_action_time (IPAddress, AccessedIdentifier, ActionType, AccessTimestamp)
);
```
*Note: The schema provided in the previous step used `CalculationCount` and a unique key on `(CompanyID, CalculationDate)` for *subscription limits*. This `ip_access_logs` table is for *public access rate limiting* and logs individual attempts.*

**Important Considerations:**
*   **Limit Configuration:** Hardcoded limits should be moved to a more flexible configuration system.
*   **Time Window Precision:** The current `AccessTimestamp >= :windowStart` is simple. More precise sliding window logic can be more complex but also more accurate for some rate-limiting scenarios.
*   **`Retry-After` Header:** If returning 429, the controller should ideally set a `Retry-After` HTTP header using the `'retryAfterSeconds'` value.
*   **IP Address Spoofing/Proxies:** `$_SERVER['REMOTE_ADDR']` can sometimes be unreliable if users are behind proxies. Checking `HTTP_X_FORWARDED_FOR` (and trusting it carefully from configured reverse proxies) is a more advanced topic. For now, `REMOTE_ADDR` is a common starting point.
*   **Performance:** For very high traffic, writing to `ip_access_logs` on every request and then querying it can become a bottleneck. Solutions like Redis or Memcached are often used for high-performance rate limiting counters.

This `RateLimitService.php` provides a conceptual database-backed rate limiting mechanism.The `RateLimitService.php` has been conceptually created.

**Key features of `RateLimitService.php`:**

1.  **Constructor:** Initializes a database connection.
2.  **Rate Limit Definitions:** Defines constants for rate limits (e.g., attempts per specific identifier per IP, global attempts per IP for an action type) and their respective time windows (e.g., per hour). *These are currently hardcoded and would ideally be configurable.*
3.  **`checkAndLogAccess(string $ipAddress, string $identifier, string $actionType)` Method:**
    *   Takes the requester's `$ipAddress`, the `$identifier` of the resource being accessed (e.g., the composite shareable report ID), and an `$actionType` (e.g., 'report_view').
    *   **Specific Identifier Limit:** It first queries the (conceptual) `ip_access_logs` table to count how many times this specific `$ipAddress` has accessed this exact `$identifier` for this `$actionType` within the defined time window. If the count meets or exceeds `LIMIT_PER_IDENTIFIER_PER_IP_COUNT`, it returns `['allowed' => false, ...]`
    *   **Global IP Limit:** If the specific limit passes, it then queries `ip_access_logs` to count how many times this `$ipAddress` has performed this `$actionType` (regardless of identifier) within its time window. If this count meets or exceeds `LIMIT_GLOBAL_PER_IP_COUNT`, it returns `['allowed' => false, ...]`.
    *   **Log Access Attempt:** If both limit checks pass, it inserts a new record into `ip_access_logs` detailing the access (IP, identifier, action type, UserAgent).
    *   **Return Value:** Returns a structured array `['allowed' => bool, 'message' => string, 'retryAfterSeconds' => ?int, 'remaining_specific' => int, 'remaining_global' => int]`. It currently "fails open" (allows access but logs an error) if the database log *insert* fails but prior limit checks passed.
4.  **`ip_access_logs` Table Assumption:** This service assumes a database table (e.g., `ip_access_logs`) exists to store access attempts, with columns like `IPAddress`, `AccessedIdentifier`, `ActionType`, `AccessTimestamp`, and `UserAgent`.

This service provides a foundational mechanism for IP-based rate limiting for public endpoints. The next step is to integrate its usage into `ReportController.php`.
