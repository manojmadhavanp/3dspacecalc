```php
<?php

namespace App\Service;

use App\Config\Database;
use App\Model\Subscription;
use PDO;
// Conceptual: use Razorpay\Api\Api as RazorpayApi; // If using Razorpay PHP SDK

class SubscriptionService {
    private PDO $db;
    // Conceptual Razorpay API client
    // private ?RazorpayApi $razorpayApi = null;
    private string $razorpayKeyId;
    private string $razorpayKeySecret;

    public function __construct(?PDO $dbConnection = null) {
        $this->db = $dbConnection ?? (new Database())->getConnection();
        $this->razorpayKeyId = getenv('RAZORPAY_KEY_ID') ?: '';
        $this->razorpayKeySecret = getenv('RAZORPAY_KEY_SECRET') ?: '';

        // if (!empty($this->razorpayKeyId) && !empty($this->razorpayKeySecret)) {
        //     try {
        //         // $this->razorpayApi = new RazorpayApi($this->razorpayKeyId, $this->razorpayKeySecret);
        //     } catch (\Exception $e) {
        //         error_log("Failed to initialize Razorpay API client: " . $e->getMessage());
        //         $this->razorpayApi = null;
        //     }
        // } else {
        //     error_log("Razorpay Key ID or Secret not configured. Payment processing will be simulated.");
        // }
    }

    /**
     * Get current subscription details for a company.
     */
    public function getSubscriptionByCompanyId(int $companyId): ?Subscription {
        $stmt = $this->db->prepare("SELECT * FROM company_subscription WHERE CompanyID = :companyId");
        $stmt->bindParam(':companyId', $companyId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? Subscription::fromDbRow($row) : null;
    }

    /**
     * Creates a conceptual Razorpay order.
     * In a real app, this interacts with Razorpay SDK.
     * Stores order details temporarily or updates subscription with pending status.
     */
    public function createRazorpayOrder(int $companyId, string $planId, float $amount, string $currency = 'INR'): array {
        // if (!$this->razorpayApi) {
        //     return ['success' => false, 'message' => 'Payment gateway not configured. Please contact support.'];
        // }

        // 1. Fetch plan details from a config or database based on $planId
        //    For now, assume $amount and $currency are passed directly or known.
        //    Example: $planDetails = $this->getPlanDetails($planId); $amount = $planDetails['amount'];

        $receiptId = "RCPT_COMP{$companyId}_" . time() . "_" . rand(1000,9999);
        $orderData = [
            'receipt'         => $receiptId,
            'amount'          => (int)($amount * 100), // Amount in paise/cents
            'currency'        => $currency,
            'payment_capture' => 1 // Auto capture payment
        ];

        try {
            // --- Conceptual Razorpay API call ---
            // $razorpayOrder = $this->razorpayApi->order->create($orderData);
            // Simulate Razorpay response:
            $simulatedRazorpayOrderId = 'order_SIM' . strtoupper(bin2hex(random_bytes(7)));
            // error_log("Simulated Razorpay Order Creation for Company {$companyId}, Plan {$planId}, Amount {$amount} {$currency}. OrderID: {$simulatedRazorpayOrderId}");
            // --- End Conceptual Call ---

            // Store/Update subscription record with pending payment and Razorpay Order ID
            // This might update an existing trial or create a new pending record.
            // For simplicity, let's assume we update the existing company_subscription record.
            $sql = "UPDATE company_subscription
                    SET RazorpayOrderID = :razorpayOrderId, Amount = :amount, Currency = :currency, PaymentStatus = 'pending_razorpay_confirmation'
                    WHERE CompanyID = :companyId";
            // If it's a new subscription (e.g. after trial, no record exists), it would be an INSERT or an UPSERT.
            // This logic needs to be robust based on whether a subscription record already exists.
            // For now, assuming an UPDATE on an existing record (e.g. trial record being upgraded).

            $stmt = $this->db->prepare($sql);
            $stmt->bindParam(':razorpayOrderId', $simulatedRazorpayOrderId);
            $stmt->bindParam(':amount', $amount);
            $stmt->bindParam(':currency', $currency);
            $stmt->bindParam(':companyId', $companyId, PDO::PARAM_INT);
            $stmt->execute();

            // If no record was updated (e.g. first time subscription post-trial period, or trial record was deleted)
            // This part of logic is simplified. A full implementation would handle new vs existing subscription records better.
            if ($stmt->rowCount() == 0) {
                 // Attempt an insert if no update occurred, assuming a trial record might not exist or is being replaced
                $trialDefaults = $this->getPlanDetailsById($planId); // Get defaults for the plan
                $insertSql = "INSERT INTO company_subscription (CompanyID, PackageName, MaxUsers, MaxCalculationsPerDay, RazorpayOrderID, Amount, Currency, PaymentStatus, SubscriptionStartDate)
                              VALUES (:companyId, :packageName, :maxUsers, :maxCalcs, :razorpayOrderId, :amount, :currency, 'pending_razorpay_confirmation', NOW())
                              ON DUPLICATE KEY UPDATE
                                PackageName = VALUES(PackageName), MaxUsers = VALUES(MaxUsers), MaxCalculationsPerDay = VALUES(MaxCalculationsPerDay),
                                RazorpayOrderID = VALUES(RazorpayOrderID), Amount = VALUES(Amount), Currency = VALUES(Currency),
                                PaymentStatus = VALUES(PaymentStatus), UpdatedOn = NOW()";
                $insertStmt = $this->db->prepare($insertSql);
                $insertStmt->execute([
                    ':companyId' => $companyId,
                    ':packageName' => $trialDefaults['name'] ?? $planId,
                    ':maxUsers' => $trialDefaults['maxUsers'] ?? 10,
                    ':maxCalcs' => $trialDefaults['maxCalcs'] ?? 100,
                    ':razorpayOrderId' => $simulatedRazorpayOrderId,
                    ':amount' => $amount,
                    ':currency' => $currency
                ]);
                 error_log("Created/Updated subscription record for company {$companyId} with Razorpay Order ID {$simulatedRazorpayOrderId}");
            }


            return [
                'success' => true,
                'message' => 'Razorpay order created successfully (simulated).',
                'data' => [
                    'razorpayOrderId' => $simulatedRazorpayOrderId,
                    'razorpayKeyId' => $this->razorpayKeyId, // Frontend needs this
                    'amount' => (int)($amount * 100), // Amount in paise for Razorpay checkout
                    'currency' => $currency,
                    'companyName' => getenv('COMPANY_LEGAL_NAME_FOR_PAYMENT') ?: "XactLoad Solutions", // For display on checkout
                    'description' => "Subscription for Plan: {$planId}"
                    // Prefill user details if available and Razorpay supports it
                ]
            ];
        } catch (\PDOException $e) {
            error_log("CreateRazorpayOrder PDOException: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to create payment order due to a database error.'];
        } catch (\Exception $e) { // Catch Razorpay SDK exceptions if it were real
            error_log("CreateRazorpayOrder Exception: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to create payment order: ' . $e->getMessage()];
        }
    }

    /**
     * Verifies a Razorpay payment and updates subscription.
     * In a real app, this uses Razorpay SDK and webhook secrets for verification.
     */
    public function verifyRazorpayPayment(array $razorpayResponseData): array {
        // $razorpayPaymentId = $razorpayResponseData['razorpay_payment_id'] ?? null;
        // $razorpayOrderId = $razorpayResponseData['razorpay_order_id'] ?? null;
        // $razorpaySignature = $razorpayResponseData['razorpay_signature'] ?? null;

        // if (!$razorpayPaymentId || !$razorpayOrderId || !$razorpaySignature || !$this->razorpayApi) {
        //     throw new \InvalidArgumentException("Missing Razorpay payment details or gateway not configured.");
        // }

        try {
            // --- Conceptual Razorpay Signature Verification ---
            // $attributes = [
            //     'razorpay_order_id' => $razorpayOrderId,
            //     'razorpay_payment_id' => $razorpayPaymentId,
            //     'razorpay_signature' => $razorpaySignature
            // ];
            // $this->razorpayApi->utility->verifyPaymentSignature($attributes);
            // This would throw an exception if signature is invalid.
            // --- End Conceptual Verification ---
            $isSignatureValid = true; // Simulate valid signature for now
            error_log("Simulating Razorpay payment verification. Signature assumed valid for OrderID: {$razorpayResponseData['razorpay_order_id']}");


            if ($isSignatureValid) {
                // Fetch companyId and plan details based on $razorpayOrderId
                $stmtSub = $this->db->prepare("SELECT CompanyID, PackageName, Amount FROM company_subscription WHERE RazorpayOrderID = :razorpayOrderId");
                $stmtSub->bindParam(':razorpayOrderId', $razorpayResponseData['razorpay_order_id']);
                $stmtSub->execute();
                $subscriptionData = $stmtSub->fetch(PDO::FETCH_ASSOC);

                if (!$subscriptionData) {
                    return ['success' => false, 'message' => 'Associated subscription for Razorpay Order ID not found.'];
                }
                $companyId = $subscriptionData['CompanyID'];
                // $planId = $subscriptionData['PackageName']; // Or fetch plan details from a plans table/config
                // For now, assume plan details are already on the subscription record or use fixed values

                $planDetails = $this->getPlanDetailsById($subscriptionData['PackageName'] ?? 'basic'); // Get plan specifics


                // Update subscription based on successful payment
                $newStatus = 'active'; // Or 'paid'
                $startDate = new \DateTime('now', new \DateTimeZone('UTC'));
                // Calculate end date based on plan duration (e.g., 1 month, 1 year)
                $planDuration = $planDetails['duration_days'] ?? 30; // e.g., 30 days for basic
                $endDate = (new \DateTime('now', new \DateTimeZone('UTC')))->modify("+{$planDuration} days");

                $sqlUpdate = "UPDATE company_subscription SET
                                PaymentStatus = :paymentStatus,
                                RazorpayPaymentID = :razorpayPaymentId,
                                RazorpaySignature = :razorpaySignature,
                                SubscriptionStartDate = :startDate,
                                SubscriptionEndDate = :endDate,
                                MaxUsers = :maxUsers,
                                MaxCalculationsPerDay = :maxCalcs,
                                PackageName = :packageName,
                                UpdatedOn = CURRENT_TIMESTAMP
                              WHERE RazorpayOrderID = :razorpayOrderId AND CompanyID = :companyId";

                $stmtUpdate = $this->db->prepare($sqlUpdate);
                $stmtUpdate->execute([
                    ':paymentStatus' => $newStatus,
                    ':razorpayPaymentId' => $razorpayResponseData['razorpay_payment_id'],
                    ':razorpaySignature' => $razorpayResponseData['razorpay_signature'],
                    ':startDate' => $startDate->format('Y-m-d H:i:s'),
                    ':endDate' => $endDate->format('Y-m-d H:i:s'),
                    ':maxUsers' => $planDetails['maxUsers'],
                    ':maxCalcs' => $planDetails['maxCalcs'],
                    ':packageName' => $planDetails['name'],
                    ':razorpayOrderId' => $razorpayResponseData['razorpay_order_id'],
                    ':companyId' => $companyId
                ]);

                // Also update auth status for users in the company if they were 'trial_expired' or similar
                $this->updateUserAuthStatusForCompany($companyId, 'active');

                return ['success' => true, 'message' => 'Payment verified and subscription activated.'];
            } else {
                // This part is less likely if Razorpay SDK throws exception on bad signature
                return ['success' => false, 'message' => 'Payment verification failed (invalid signature - simulated).'];
            }
        } catch (\Exception $e) { // Catches Razorpay SignatureVerificationError or PDOExceptions
            error_log("VerifyRazorpayPayment Exception: " . $e->getMessage());
            // Potentially update order status to 'failed_verification' in DB
            return ['success' => false, 'message' => 'Payment verification failed: ' . $e->getMessage()];
        }
    }

    /**
     * Checks if a company's trial has expired and updates status if needed.
     * This could be run by a cron job or on user login.
     */
    public function checkAndUpdateTrialStatus(int $companyId): bool {
        $subscription = $this->getSubscriptionByCompanyId($companyId);
        if ($subscription && $subscription->isTrial && $subscription->isActive && $subscription->subscriptionEndDate) {
            $now = new \DateTime('now', new \DateTimeZone('UTC'));
            if ($now > $subscription->subscriptionEndDate) {
                // Trial has expired
                try {
                    $sql = "UPDATE company_subscription SET PaymentStatus = 'trial_expired'
                            WHERE CompanyID = :companyId AND PaymentStatus = 'active' AND PackageName = 'trial'";
                    $stmt = $this->db->prepare($sql);
                    $stmt->bindParam(':companyId', $companyId, PDO::PARAM_INT);
                    $stmt->execute();

                    if ($stmt->rowCount() > 0) {
                        // Optionally update auth status of users in this company to 'trial_expired' or 'inactive'
                        $this->updateUserAuthStatusForCompany($companyId, 'inactive'); // Or a new 'trial_expired' status
                        error_log("Trial expired for CompanyID: {$companyId}. Subscription status updated.");
                        return true;
                    }
                } catch (\PDOException $e) {
                    error_log("checkAndUpdateTrialStatus PDOException for CompanyID {$companyId}: " . $e->getMessage());
                }
            }
        }
        return false;
    }

    /**
     * Helper to update auth.Status for all users of a company.
     */
    private function updateUserAuthStatusForCompany(int $companyId, string $newAuthStatus): void {
        try {
            $sql = "UPDATE auth SET Status = :newAuthStatus WHERE UID IN (SELECT UID FROM users WHERE CompanyID = :companyId)";
            $stmt = $this->db->prepare($sql);
            $stmt->bindParam(':newAuthStatus', $newAuthStatus);
            $stmt->bindParam(':companyId', $companyId, PDO::PARAM_INT);
            $stmt->execute();
            error_log("Updated auth status to '{$newAuthStatus}' for users in CompanyID {$companyId}. Rows affected: " . $stmt->rowCount());
        } catch (\PDOException $e) {
            error_log("updateUserAuthStatusForCompany PDOException for CompanyID {$companyId}: " . $e->getMessage());
        }
    }

    /**
     * Conceptual: Get plan details (e.g., from a config file or DB table `plans`)
     */
    private function getPlanDetailsById(string $planId): array {
        // In a real app, load this from DB or a plans configuration file.
        $plans = [
            'trial' => ['name' => 'trial', 'amount' => 0, 'currency' => 'INR', 'duration_days' => (int)(getenv('TRIAL_DURATION_DAYS')?:30), 'maxUsers' => (int)(getenv('TRIAL_MAX_USERS')?:1), 'maxCalcs' => (int)(getenv('TRIAL_MAX_CALCS_PER_DAY')?:5)],
            'basic' => ['name' => 'basic', 'amount' => 1000.00, 'currency' => 'INR', 'duration_days' => 30, 'maxUsers' => 5, 'maxCalcs' => 100],
            'premium' => ['name' => 'premium', 'amount' => 5000.00, 'currency' => 'INR', 'duration_days' => 30, 'maxUsers' => 20, 'maxCalcs' => 500],
            // Add 'yearly_basic', 'yearly_premium' etc.
        ];
        return $plans[strtolower($planId)] ?? $plans['trial']; // Default to trial if planId not found
    }

    /**
     * Checks if a company can perform a calculation based on subscription.
     * This is a simplified check. A full check would involve tracking daily usage.
     */
    public function canPerformCalculation(int $companyId): bool {
        $subscription = $this->getSubscriptionByCompanyId($companyId);
        if (!$subscription || !$subscription->isActive) {
            return false; // No active subscription
        }
        // The check against MaxCalculationsPerDay needs a daily counter mechanism.
        // For now, if active subscription exists, assume they can (this is a placeholder).
        // A more complete implementation:
        if (!$subscription || !$subscription->isActive) {
            return ['allowed' => false, 'message' => 'No active subscription found for your company.', 'remaining' => 0];
        }

        $maxCalculations = $subscription->maxCalculationsPerDay;
        if ($maxCalculations === null || $maxCalculations <= 0) { // Unlimited or invalid (treat as unlimited for safety if misconfigured)
            return ['allowed' => true, 'message' => 'Calculation allowed (unlimited).', 'remaining' => -1]; // -1 for unlimited
        }

        // --- Conceptual Daily Calculation Tracking ---
        // This requires a new table e.g., `daily_calculation_logs (LogID, CompanyID, CalculationDate, CalculationCount)`
        // Or, update a counter on `company_subscription` if it resets daily (less robust for history).

        $today = date('Y-m-d');
        $sqlLog = "SELECT SUM(CalculationCount) as TodayCount FROM daily_calculation_logs
                   WHERE CompanyID = :companyId AND CalculationDate = :todayDate";
        $stmtLog = $this->db->prepare($sqlLog);
        $stmtLog->execute([':companyId' => $companyId, ':todayDate' => $today]);
        $logRow = $stmtLog->fetch(PDO::FETCH_ASSOC);
        $calculationsToday = $logRow ? (int)$logRow['TodayCount'] : 0;
        // --- End Conceptual Tracking ---

        if ($calculationsToday >= $maxCalculations) {
            return [
                'allowed' => false,
                'message' => "Calculation limit ({$maxCalculations}/day) reached. Please upgrade or wait until tomorrow.",
                'remaining' => 0
            ];
        }

        return [
            'allowed' => true,
            'message' => 'Calculation allowed.',
            'remaining' => $maxCalculations - $calculationsToday
        ];
    }

    /**
     * Records a calculation attempt for the company for daily limit tracking.
     * To be called by CalculationController AFTER canPerformCalculation returns true and before actual calculation.
     */
    public function recordCalculationAttempt(int $companyId): void {
        // --- Conceptual: Increment daily calculation log ---
        $today = date('Y-m-d');
        // UPSERT logic: Increment if exists for today, else insert with count 1
        $sqlUpsert = "INSERT INTO daily_calculation_logs (CompanyID, CalculationDate, CalculationCount)
                      VALUES (:companyId, :todayDate, 1)
                      ON DUPLICATE KEY UPDATE CalculationCount = CalculationCount + 1";
        try {
            $stmtUpsert = $this->db->prepare($sqlUpsert);
            $stmtUpsert->execute([':companyId' => $companyId, ':todayDate' => $today]);
        } catch (\PDOException $e) {
            error_log("Failed to record calculation attempt for CompanyID {$companyId}: " . $e->getMessage());
            // Non-fatal, but should be monitored. The limit check might become inaccurate if this fails.
        }
        // --- End Conceptual ---
    }
}

```

**Key Features of `SubscriptionService.php`:**

1.  **Constructor:** Initializes DB and conceptually the Razorpay API client (commented out for now) using keys from `.env`.
2.  **`getSubscriptionByCompanyId(int $companyId)`:** Fetches the company's current subscription from the `company_subscription` table and returns a `Subscription` object.
3.  **`createRazorpayOrder(...)` (Conceptual):**
    *   Simulates creating a Razorpay order. In a real app, it would use the Razorpay PHP SDK.
    *   Takes `companyId`, `planId`, `amount`, `currency`.
    *   Updates the `company_subscription` record for the `companyId` with the generated `RazorpayOrderID`, amount, currency, and sets `PaymentStatus` to something like `'pending_razorpay_confirmation'`. It handles both updating an existing record (e.g. trial being upgraded) or inserting a new one if necessary (using `ON DUPLICATE KEY UPDATE` for an UPSERT behavior).
    *   Returns necessary data for the frontend to initialize Razorpay Checkout (simulated Razorpay `order_id`, your `razorpayKeyId`, amount, currency, company display name).
4.  **`verifyRazorpayPayment(array $razorpayResponseData)` (Conceptual):**
    *   Takes Razorpay's response data (`razorpay_payment_id`, `razorpay_order_id`, `razorpay_signature`).
    *   Conceptually verifies the signature (critical security step, actual SDK call needed).
    *   If valid:
        *   Fetches the `company_subscription` record using `razorpay_order_id`.
        *   Updates the record with payment details, sets `PaymentStatus` to 'active' (or 'paid').
        *   Calculates and sets `SubscriptionStartDate` and `SubscriptionEndDate` based on the purchased plan's duration (fetched via a helper `getPlanDetailsById`).
        *   Updates `MaxUsers` and `MaxCalculationsPerDay` according to the plan.
        *   Calls a helper `updateUserAuthStatusForCompany` to potentially re-activate users if they were in a 'trial_expired' state.
5.  **`checkAndUpdateTrialStatus(int $companyId)`:**
    *   A utility method that could be called by a cron job or on user login.
    *   Checks if an active trial subscription has passed its `SubscriptionEndDate`.
    *   If expired, updates `PaymentStatus` to 'trial_expired' and potentially deactivates users in that company via `updateUserAuthStatusForCompany`.
6.  **`updateUserAuthStatusForCompany(int $companyId, string $newAuthStatus)`:** Private helper to change the `auth.Status` for all users of a given company.
7.  **`getPlanDetailsById(string $planId)`:** Conceptual helper to fetch plan specifics (duration, limits, name). In a real app, this would come from a database table or a configuration file.
8.  **`canPerformCalculation(int $companyId)`:** Placeholder method. A real implementation would need to:
    *   Check if the company has an active subscription.
    *   Query a (new) table that logs daily calculations per company.
    *   Compare usage against `MaxCalculationsPerDay`.

**Important Notes:**
*   **Razorpay Integration:** All Razorpay calls are conceptual and marked. Actual integration requires their PHP SDK and secure handling of API keys and webhook secrets.
*   **Plan Details:** The `getPlanDetailsById` is a simple array. This should be a more robust configuration or database table.
*   **Error Handling:** Basic error logging for exceptions.
*   **Transaction Management:** `createRazorpayOrder` and `verifyRazorpayPayment` (if they involved multiple DB writes before actual payment confirmation or after) would benefit from database transactions, though the current structure for `createRazorpayOrder` mostly updates one record.

This service provides the core backend logic for subscriptions. The next step is to create `SubscriptionController.php`.The `SubscriptionService.php` has been conceptually created.

**Key features of `SubscriptionService.php`:**

1.  **Constructor:** Initializes the database connection and conceptually loads Razorpay API keys from environment variables (`RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET`). The actual Razorpay SDK initialization is commented out as a placeholder.
2.  **`getSubscriptionByCompanyId(int $companyId)`:** Retrieves the current subscription record for a given company from the `company_subscription` table and returns it as a `Subscription` model object.
3.  **`createRazorpayOrder(int $companyId, string $planId, float $amount, string $currency)` (Conceptual):**
    *   Simulates the creation of an order with Razorpay.
    *   Updates (or inserts using `ON DUPLICATE KEY UPDATE` for an upsert-like behavior) the `company_subscription` record with the `RazorpayOrderID`, amount, currency, and sets `PaymentStatus` to `'pending_razorpay_confirmation'`.
    *   It also updates/sets `PackageName`, `MaxUsers`, and `MaxCalculationsPerDay` based on the chosen `$planId` (using a helper `getPlanDetailsById`).
    *   Returns data needed by the frontend to initialize Razorpay Checkout (e.g., `razorpayOrderId`, `razorpayKeyId`, amount).
4.  **`verifyRazorpayPayment(array $razorpayResponseData)` (Conceptual):**
    *   Simulates verification of the Razorpay payment signature.
    *   If the (simulated) signature is valid, it fetches the corresponding `company_subscription` record by `RazorpayOrderID`.
    *   Updates the subscription record: sets `PaymentStatus` to 'active', stores Razorpay payment details, sets `SubscriptionStartDate` to now, calculates `SubscriptionEndDate` based on plan duration (from `getPlanDetailsById`), and updates `MaxUsers`, `MaxCalculationsPerDay`, and `PackageName` according to the purchased plan.
    *   Calls a helper `updateUserAuthStatusForCompany` to potentially reactivate users if their status was, for example, 'trial_expired'.
5.  **`checkAndUpdateTrialStatus(int $companyId)`:** A utility method (e.g., for a cron job or login check) to see if an active trial has expired. If so, it updates the `PaymentStatus` to 'trial_expired' and can update the `auth.Status` of users in that company.
6.  **`updateUserAuthStatusForCompany(int $companyId, string $newAuthStatus)`:** A private helper to update the `auth.Status` for all users of a specified company.
7.  **`getPlanDetailsById(string $planId)`:** A conceptual private helper to retrieve details of a subscription plan (like duration, limits, name). In a real application, this would query a `plans` table or read from a configuration file.
8.  **`canPerformCalculation(int $companyId)`:** A placeholder method to check if a company can perform a calculation based on its active subscription and `MaxCalculationsPerDay`. The actual daily usage tracking is a TODO.

This service class provides the necessary business logic for managing subscriptions and handling the conceptual payment flow. The next step is to create `SubscriptionController.php` to expose these functionalities as API endpoints.
