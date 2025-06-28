```php
<?php
namespace App\Controller;

use App\Service\SubscriptionService;
use App\Exception\AuthException; // For authorization failures

class SubscriptionController {
    private SubscriptionService $subscriptionService;
    private ?object $authenticatedUserContext; // From JWT via routes.php

    public function __construct(?object $authenticatedUserContext, ?SubscriptionService $subscriptionService = null) {
        // Some actions might be public (like Razorpay webhook), others require auth.
        // The check for $authenticatedUserContext will happen per method if needed.
        $this->authenticatedUserContext = $authenticatedUserContext;
        $this->subscriptionService = $subscriptionService ?? new SubscriptionService();
    }

    /**
     * Handles incoming requests for /subscription/*
     * $action: 'create-order', 'verify-payment', 'status'
     * $rawJsonData: For POST/PUT with JSON body
     * $postData: For form-data (like Razorpay callback)
     */
    public function handleRequest(string $method, ?string $action, ?string $rawJsonData, array $postDataFromPhpInput = []): void {
        $data = null;
        if (($method === 'POST' || $method === 'PUT') && !empty($rawJsonData)) {
            $data = json_decode($rawJsonData, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->jsonResponse(['status' => 'error', 'message' => 'Invalid JSON: ' . json_last_error_msg()], 400);
                return;
            }
        }
        // If it's form-data (e.g. Razorpay callback), $postDataFromPhpInput will be $_POST
        if ($method === 'POST' && empty($data) && !empty($postDataFromPhpInput)) {
            $data = $postDataFromPhpInput;
        }


        try {
            switch ($action) {
                case 'create-order':
                    $this->route($method, ['POST'], fn() => $this->createOrder($data));
                    break;
                case 'verify-payment': // This could be a webhook or server-to-server call
                    $this->route($method, ['POST'], fn() => $this->verifyPayment($data)); // $data is $_POST from Razorpay
                    break;
                case 'status':
                    $this->route($method, ['GET'], fn() => $this->getSubscriptionStatus());
                    break;
                default:
                    $this->jsonResponse(["status" => "error", "message" => "Subscription action '{$action}' not found."], 404);
                    break;
            }
        } catch (\InvalidArgumentException $e) { $this->jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 400);
        } catch (AuthException $e) { $this->jsonResponse(['status' => 'error', 'message' => $e->getMessage()], $e->getCode() ?: 401);
        } catch (\PDOException $e) { $this->handleDbError($e, $action);
        } catch (\Exception $e) { $this->handleGenericError($e, $action); }
    }

    private function route(string $currentMethod, array $allowedMethods, callable $callback): void {
        if (!in_array($currentMethod, $allowedMethods)) {
            $this->methodNotAllowed($allowedMethods); return;
        }
        // Authentication check for relevant routes
        if (in_array($this->getCurrentAction(), ['create-order', 'status'])) { // verify-payment might be webhook
            if ($this->authenticatedUserContext === null || !isset($this->authenticatedUserContext->companyId)) {
                throw new AuthException("Authentication required for this subscription action.", 401);
            }
        }
        $callback();
    }

    // Helper to get current action, used in route() for auth check
    private function getCurrentAction(): ?string {
        $uriSegments = explode('/', trim(strtok($_SERVER['REQUEST_URI'], '?'), '/'));
        // Assuming /api/v1/subscription/{action}
        return $uriSegments[3] ?? null;
    }


    private function jsonResponse(array $data, int $statusCode): void { /* ... as in AuthController ... */ http_response_code($statusCode); echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); }
    private function methodNotAllowed(array $allowedMethods): void { /* ... as in AuthController ... */ http_response_code(405); header('Allow: ' . implode(', ', $allowedMethods)); echo json_encode(["status" => "error", "message" => "Method not allowed. Allowed: " . implode(', ', $allowedMethods)]);}
    private function handleDbError(\PDOException $e, ?string $action): void { error_log("DB error in SubscriptionController action '{$action}': " . $e->getMessage()); $this->jsonResponse(['status' => 'error', 'message' => 'Database error.'], 500); }
    private function handleGenericError(\Exception $e, ?string $action): void { error_log("Error in SubscriptionController action '{$action}': " . $e->getMessage()); $this->jsonResponse(['status' => 'error', 'message' => 'Server error.'], 500); }


    private function createOrder(?array $data): void {
        // Authenticated user context is checked by the route() helper
        $companyId = (int)$this->authenticatedUserContext->companyId;

        if ($data === null || empty($data['planId']) || empty($data['amount']) || !is_numeric($data['amount'])) {
            throw new \InvalidArgumentException("planId and numeric amount are required to create order.");
        }
        $planId = (string)$data['planId'];
        $amount = (float)$data['amount'];
        $currency = (string)($data['currency'] ?? 'INR');

        $result = $this->subscriptionService->createRazorpayOrder($companyId, $planId, $amount, $currency);
        $this->jsonResponse($result, $result['success'] ? 200 : 400); // 200 as it returns data for next step
    }

    private function verifyPayment(?array $data): void {
        // This endpoint is typically a webhook from Razorpay, or called server-side after client redirect.
        // If webhook, it needs its own security (e.g., verifying Razorpay signature with a webhook secret).
        // If called by our server after client redirect, it might already have some session context or expect specific params.
        // For now, assume $data contains razorpay_payment_id, razorpay_order_id, razorpay_signature.

        if ($data === null || empty($data['razorpay_payment_id']) || empty($data['razorpay_order_id']) || empty($data['razorpay_signature'])) {
            throw new \InvalidArgumentException("Razorpay payment details (payment_id, order_id, signature) are required for verification.");
        }

        // Conceptual: Webhook security check
        // $webhookSecret = getenv('RAZORPAY_WEBHOOK_SECRET');
        // $receivedSignature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? null;
        // if ($webhookSecret && $receivedSignature) {
        //     if (!$this->subscriptionService->verifyWebhookSignature(json_encode($data), $receivedSignature, $webhookSecret)) { // Service needs this method
        //         throw new AuthException("Invalid Razorpay webhook signature.", 403);
        //     }
        // } else if (!$webhookSecret && !$receivedSignature && some_other_verification_like_session_based_redirect) {
        //     // This branch is for when client POSTs data after its own redirect from Razorpay
        // } else {
        //      throw new AuthException("Payment verification security check failed.", 403);
        // }


        $result = $this->subscriptionService->verifyRazorpayPayment($data);

        if ($result['success']) {
            // If this was a webhook, respond 200 OK to Razorpay.
            // If this was a server call after client redirect, could return JSON.
            $this->jsonResponse($result, 200);
        } else {
            $this->jsonResponse($result, 400); // Or 500 if it was a server error during verification
        }
    }

    private function getSubscriptionStatus(): void {
        // Authenticated user context is checked by the route() helper
        $companyId = (int)$this->authenticatedUserContext->companyId;
        $subscription = $this->subscriptionService->getSubscriptionByCompanyId($companyId);

        if ($subscription) {
            $this->jsonResponse(['status' => 'success', 'data' => $subscription->toArray()], 200);
        } else {
            $this->jsonResponse(['status' => 'error', 'message' => 'Subscription details not found for your company.'], 404);
        }
    }
}
?>
```

**Key Features of `SubscriptionController.php`:**

1.  **Constructor:** Accepts the authenticated user context (passed from `routes.php`). Instantiates `SubscriptionService`.
2.  **`handleRequest(...)`:** Main router for `/subscription/*` actions.
    *   Decodes JSON for POST/PUT or uses `$_POST` (passed as `$postDataFromPhpInput`) for `verify-payment` if it's form data.
    *   Uses a `route()` helper for common method check and pre-authentication for specific actions.
3.  **`route()` helper:**
    *   Checks allowed HTTP methods.
    *   **Performs Authentication Check:** For actions like `create-order` and `status`, it ensures `$this->authenticatedUserContext` (and thus `companyId`) is available. `verify-payment` might be a public webhook, so auth check is conditional or handled by signature verification.
4.  **Private Handler Methods:**
    *   **`createOrder(?array $data)`:**
        *   Requires `planId` and `amount`.
        *   Calls `subscriptionService->createRazorpayOrder()`.
        *   Returns JSON response (200 with data for Razorpay checkout, or 400 on error).
    *   **`verifyPayment(?array $data)`:**
        *   Expects `razorpay_payment_id`, `razorpay_order_id`, `razorpay_signature`.
        *   **Conceptual Webhook Security:** Includes comments on where Razorpay webhook signature verification would occur (this requires a method in `SubscriptionService` and a `RAZORPAY_WEBHOOK_SECRET` in `.env`). For now, it proceeds assuming data is from a trusted source or simulation.
        *   Calls `subscriptionService->verifyRazorpayPayment()`.
        *   Returns JSON response (200 on success, 400/500 on error).
    *   **`getSubscriptionStatus()`:**
        *   Uses authenticated user's `companyId`.
        *   Calls `subscriptionService->getSubscriptionByCompanyId()`.
        *   Returns subscription details or 404.
5.  **Response/Error Handling:** Uses standardized `jsonResponse` and error logging helpers.

**Integration with `backend/routes.php`:**
The `routes.php` script would need a case for `mainRoute === 'subscription'`:
```php
// In backend/routes.php
case 'subscription':
    // $action is 'create-order', 'verify-payment', or 'status'
    // $authenticatedUserContext is already determined (or null if public parts of verify-payment)
    $controller = new App\Controller\SubscriptionController($authenticatedUserContext);
    // Pass $_POST as the fourth argument if it's expected for verify-payment form data
    $postData = ($action === 'verify-payment' && $requestMethod === 'POST' && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false) ? $_POST : [];
    $controller->handleRequest($requestMethod, $action, $rawJsonData, $postData);
    break;
```

This controller provides the API layer for subscription management and conceptual payment integration. The next step would be to integrate actual subscription checks into other services like `CalculationController` and `UserService`.The `SubscriptionController.php` has been conceptually created.

**Key features of `SubscriptionController.php`:**

1.  **Constructor:** Accepts the `$authenticatedUserContext` (passed from `routes.php` after JWT validation) and instantiates `SubscriptionService`.
2.  **`handleRequest(...)` Method:**
    *   Serves as the main router for actions under `/subscription/*` (e.g., `create-order`, `verify-payment`, `status`).
    *   It decodes JSON input for typical POST/PUT requests and can also handle `$_POST` data (passed as `$postDataFromPhpInput`) specifically for the `verify-payment` endpoint, which might receive form data from Razorpay's callback.
    *   Uses a private `route()` helper method to check allowed HTTP methods and to perform authentication checks for actions that require it (e.g., `create-order`, `status`). The `verify-payment` action might be exempt from JWT auth if it's a direct webhook from Razorpay (relying on signature/secret verification instead).
3.  **Private Handler Methods:**
    *   **`createOrder(?array $data)`:**
        *   Handles `POST /subscription/create-order`.
        *   Ensures user is authenticated and gets `companyId` from context.
        *   Validates required input (`planId`, `amount`).
        *   Calls `subscriptionService->createRazorpayOrder()`.
        *   Returns a JSON response containing data needed for frontend Razorpay checkout (like `razorpayOrderId`, `razorpayKeyId`, amount) or an error.
    *   **`verifyPayment(?array $data)`:**
        *   Handles `POST /subscription/verify-payment`.
        *   Validates presence of Razorpay payment details (`razorpay_payment_id`, `razorpay_order_id`, `razorpay_signature`).
        *   Includes conceptual comments on where webhook signature verification (using `RAZORPAY_WEBHOOK_SECRET`) would occur.
        *   Calls `subscriptionService->verifyRazorpayPayment()`.
        *   Returns a success or failure JSON response.
    *   **`getSubscriptionStatus()`:**
        *   Handles `GET /subscription/status`.
        *   Ensures user is authenticated and gets `companyId`.
        *   Calls `subscriptionService->getSubscriptionByCompanyId()`.
        *   Returns the company's subscription details or a 404 if not found.
4.  **Standardized Responses:** Uses helper methods (`jsonResponse`, `methodNotAllowed`, `handleDbError`, `handleGenericError`) for consistent JSON output and HTTP status codes, including comprehensive error handling.

**Integration Note:**
The main router in `backend/routes.php` will need a case for `mainRoute === 'subscription'` to instantiate `SubscriptionController`, pass the `$authenticatedUserContext`, and call its `handleRequest` method, correctly passing `$_POST` if the content type suggests form data (for `verify-payment`).

This controller provides the API endpoints for managing subscriptions and interacting with the conceptual payment flow. The next step in the plan is "Integrate Subscription Checks" into other relevant services.
