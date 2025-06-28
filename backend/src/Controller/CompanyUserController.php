```php
<?php
namespace App\Controller;

use App\Service\UserService;
use App\Exception\AuthException; // For authorization failures

class CompanyUserController {
    private UserService $userService;
    private object $authenticatedUserContext; // Must be provided (CompanyAdmin context)

    public function __construct(object $authenticatedUserContext, ?UserService $userService = null) {
        if ($authenticatedUserContext === null || !isset($authenticatedUserContext->companyId) || !isset($authenticatedUserContext->roleRUID)) {
            throw new AuthException("Authentication context is missing or invalid for CompanyUserController.", 401);
        }
        // Authorization: Ensure only CompanyAdmin can access these functionalities
        if ($authenticatedUserContext->roleRUID !== 'COMPANY_ADMIN_ROLE_UID') {
            throw new AuthException("User does not have permission to manage company users (Requires CompanyAdmin role).", 403);
        }

        $this->authenticatedUserContext = $authenticatedUserContext;
        $this->userService = $userService ?? new UserService();
    }

    /**
     * Handles incoming requests for /company/users/*
     * $action here would be the user's UID for specific user actions, or null for general /company/users.
     * $subAction would be 'status' or 'role' if the path is /company/users/{uid}/status or /company/users/{uid}/role
     */
    public function handleRequest(string $method, ?string $targetUserUidUrlParam, ?string $subAction, ?string $rawJsonData): void {
        $data = null;
        if (($method === 'POST' || $method === 'PUT') && !empty($rawJsonData)) {
            $data = json_decode($rawJsonData, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->jsonResponse(['status' => 'error', 'message' => 'Invalid JSON: ' . json_last_error_msg()], 400);
                return;
            }
        }

        $actorCompanyId = (int)$this->authenticatedUserContext->companyId;
        // $actorRoleRuid = $this->authenticatedUserContext->roleRUID; // Already checked in constructor

        try {
            if ($targetUserUidUrlParam !== null) { // Operations on a specific user: /company/users/{uid}/action
                if (!ctype_digit($targetUserUidUrlParam)) {
                    throw new \InvalidArgumentException("Target User UID in URL must be numeric.");
                }
                $targetUserUid = (int)$targetUserUidUrlParam;

                if ($subAction === 'status') {
                    if ($method === 'PUT') $this->updateUserStatus($actorCompanyId, $targetUserUid, $data);
                    else $this->methodNotAllowed(['PUT']);
                } elseif ($subAction === 'role') {
                     if ($method === 'PUT') $this->updateUserRole($actorCompanyId, $targetUserUid, $data); // Optional
                     else $this->methodNotAllowed(['PUT']);
                } else if ($subAction === null) { // No further sub-action like /status or /role
                     http_response_code(404);
                     $this->jsonResponse(['status' => 'error', 'message' => "Action not specified for user {$targetUserUid}."], 404);
                }
                 else {
                    http_response_code(404);
                    $this->jsonResponse(['status' => 'error', 'message' => "Sub-action '{$subAction}' not found for company users."], 404);
                }
            } else { // General operations on /company/users
                if ($method === 'GET') $this->listUsers($actorCompanyId);
                elseif ($method === 'POST') $this->addUser($actorCompanyId, $data);
                else $this->methodNotAllowed(['GET', 'POST']);
            }
        } catch (\InvalidArgumentException $e) { $this->jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 400);
        } catch (AuthException $e) { $this->jsonResponse(['status' => 'error', 'message' => $e->getMessage()], $e->getCode() ?: 403); // Default to 403 for authz failures here
        } catch (\PDOException $e) { error_log("DB error in CompanyUserController: " . $e->getMessage()); $this->jsonResponse(['status' => 'error', 'message' => 'Database error.'], 500);
        } catch (\Exception $e) { error_log("Error in CompanyUserController: " . $e->getMessage()); $this->jsonResponse(['status' => 'error', 'message' => 'Server error.'], 500); }
    }

    private function jsonResponse(array $data, int $statusCode): void {
        http_response_code($statusCode); echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
    private function methodNotAllowed(array $allowedMethods): void {
        http_response_code(405); header('Allow: ' . implode(', ', $allowedMethods));
        echo json_encode(["status" => "error", "message" => "Method not allowed. Allowed: " . implode(', ', $allowedMethods)]);
    }

    private function listUsers(int $companyId): void {
        $users = $this->userService->getUsersByCompanyId($companyId);
        // Users data from service is already an array of associative arrays
        $this->jsonResponse(['status' => 'success', 'data' => $users], 200);
    }

    private function addUser(int $actorCompanyId, ?array $data): void {
        if ($data === null) throw new \InvalidArgumentException("Request body required to add user.");
        // $newUserData should contain: firstName, lastName, email, phoneNumber, password, RID
        // Validation of these fields is primarily in UserService->addUserToCompany
        $result = $this->userService->addUserToCompany($actorCompanyId, $data);
        if ($result['success']) {
            $this->jsonResponse($result, 201); // Created
        } else {
            // Determine status code based on message (e.g., 409 for duplicate, 402 for subscription limit)
            $statusCode = 400; // Default bad request
            if (str_contains(strtolower($result['message']), 'exists')) $statusCode = 409; // Conflict
            if (str_contains(strtolower($result['message']), 'limit reached')) $statusCode = 402; // Payment Required (or custom code)
            $this->jsonResponse($result, $statusCode);
        }
    }

    private function updateUserStatus(int $actorCompanyId, int $targetUserUid, ?array $data): void {
        if ($data === null || empty($data['status'])) {
            throw new \InvalidArgumentException("New 'status' is required in request body.");
        }
        $newStatus = $data['status'];
        // Further validation of status value happens in service
        $result = $this->userService->updateUserStatusInCompany($actorCompanyId, $targetUserUid, $newStatus);
        if ($result['success']) {
            $this->jsonResponse($result, 200);
        } else {
            $this->jsonResponse($result, str_contains($result['message'], 'not found') ? 404 : 400);
        }
    }

    private function updateUserRole(int $actorCompanyId, int $targetUserUid, ?array $data): void {
         if ($data === null || empty($data['RID']) || !is_numeric($data['RID'])) {
            throw new \InvalidArgumentException("New 'RID' (Role ID) is required and must be numeric.");
        }
        $newRoleId = (int)$data['RID'];
        $result = $this->userService->updateUserRoleInCompany($actorCompanyId, $targetUserUid, $newRoleId);
        if ($result['success']) {
            $this->jsonResponse($result, 200);
        } else {
            $this->jsonResponse($result, str_contains($result['message'], 'not found') ? 404 : 400);
        }
    }
}
?>
```

**Key Features of `CompanyUserController.php`:**

1.  **Constructor & Authorization:**
    *   Requires an `$authenticatedUserContext` object (passed from `routes.php`).
    *   **Crucially, it checks if the `authenticatedUserContext->roleRUID` is `'COMPANY_ADMIN_ROLE_UID'`. If not, it throws an `AuthException` (403 Forbidden), effectively restricting all methods in this controller to CompanyAdmins.**
    *   Instantiates `UserService`.

2.  **`handleRequest(...)` Method:**
    *   This is the main entry point from `routes.php`.
    *   It parses URL segments to differentiate between:
        *   General actions on `/company/users` (e.g., GET to list, POST to add).
        *   Actions on a specific user `/company/users/{uid}/action` (e.g., PUT to `/company/users/123/status`).
    *   Retrieves `$actorCompanyId` from the authenticated user context.
    *   Calls the appropriate private handler method.
    *   Includes standard `try-catch` blocks.

3.  **Private Handler Methods:**
    *   **`listUsers(int $companyId)`:** Handles `GET /company/users`. Calls `userService->getUsersByCompanyId()`.
    *   **`addUser(int $actorCompanyId, ?array $data)`:** Handles `POST /company/users`. Calls `userService->addUserToCompany()`. Sets HTTP 201 on success, or appropriate error codes (409 for duplicate, 402 for subscription limit, 400 for other validation).
    *   **`updateUserStatus(int $actorCompanyId, int $targetUserUid, ?array $data)`:** Handles `PUT /company/users/{uid}/status`. Validates input `status`. Calls `userService->updateUserStatusInCompany()`.
    *   **`updateUserRole(int $actorCompanyId, int $targetUserUid, ?array $data)`:** Handles `PUT /company/users/{uid}/role`. Validates input `RID`. Calls `userService->updateUserRoleInCompany()`.
    *   These methods rely on the `UserService` to perform the actual operations and checks (like ensuring the target user belongs to the admin's company).

4.  **Response Handling:** Uses private `jsonResponse` and `methodNotAllowed` helpers for consistent output.

**Integration with `backend/index.php` and `backend/routes.php`:**
*   `backend/index.php`'s autoloader needs to be able to find `App\Controller\CompanyUserController`.
*   `backend/routes.php` needs a case for `mainRoute === 'company'` and `action === 'users'` to instantiate `CompanyUserController` and pass it the `$authenticatedUserContext` and other relevant path segments.

Example addition to `backend/routes.php`:
```php
// In backend/routes.php, inside the main switch ($mainRoute)

case 'company':
    if ($action === 'users') {
        if (!$authenticatedUserContext) {
            throw new AuthException("Authentication required for company user management.", 401);
        }
        // $idParam here is $pathSegments[2] which would be the target user's UID or null
        // $subIdParam here is $pathSegments[3] which would be 'status' or 'role'
        $controller = new CompanyUserController($authenticatedUserContext);
        $controller->handleRequest($requestMethod, $idParam, $subIdParam, $rawJsonData);
    } else {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Company action '{$action}' not found."]);
    }
    break;
```

This provides the controller layer for managing users within a company, with a strong emphasis on authorization by the CompanyAdmin role.The `CompanyUserController.php` has been conceptually created.

**Key features of `CompanyUserController.php`:**

1.  **Constructor and Authorization:**
    *   The constructor requires an `$authenticatedUserContext` object (which would be supplied by `routes.php` after JWT validation).
    *   It immediately checks if the `roleRUID` in this context is `'COMPANY_ADMIN_ROLE_UID'`. If not, it throws an `AuthException` (403 Forbidden), restricting access to all controller methods to CompanyAdmins.
    *   It instantiates the `UserService`.

2.  **`handleRequest(...)` Method:**
    *   This is the main router for this controller, called by `routes.php`.
    *   It parses further path segments to distinguish between general `/company/users` actions (GET for list, POST for add) and actions on specific users like `/company/users/{uid}/status` or `/company/users/{uid}/role`.
    *   It extracts the `actorCompanyId` from the `$authenticatedUserContext`.
    *   It calls the appropriate private handler methods for each action.
    *   Includes standard `try-catch` blocks for error handling.

3.  **Private Handler Methods:**
    *   **`listUsers(int $companyId)`:** Handles `GET /company/users`. Calls `userService->getUsersByCompanyId()` using the authenticated admin's company ID.
    *   **`addUser(int $actorCompanyId, ?array $data)`:** Handles `POST /company/users`. Validates that input data (`$data`) is present. Calls `userService->addUserToCompany()`. Returns HTTP 201 on success, or appropriate error codes (e.g., 409 for duplicate user, 402 if user limit reached due to subscription, 400 for other validation issues).
    *   **`updateUserStatus(int $actorCompanyId, int $targetUserUid, ?array $data)`:** Handles `PUT /company/users/{uid}/status`. Extracts `targetUserUid` from the URL. Validates that the new `status` is provided in the request body. Calls `userService->updateUserStatusInCompany()`.
    *   **`updateUserRole(int $actorCompanyId, int $targetUserUid, ?array $data)`:** (Optional) Handles `PUT /company/users/{uid}/role`. Extracts `targetUserUid`. Validates that the new `RID` (Role ID) is provided. Calls `userService->updateUserRoleInCompany()`.
    *   These methods rely on `UserService` to perform database operations and detailed checks (like ensuring the target user belongs to the admin's company).

4.  **Response Formatting:** Uses private `jsonResponse` and `methodNotAllowed` helper methods for consistent JSON output and HTTP status codes.

**Integration Note:**
The main router in `backend/routes.php` would need a case for `mainRoute === 'company'` and `action === 'users'` to instantiate this `CompanyUserController`, passing the `$authenticatedUserContext`, and then call its `handleRequest` method with the appropriate sub-path segments.

This controller provides the API layer for CompanyAdmins to manage users within their own company, respecting authorization and interacting with the `UserService` for the business logic. The next step is conceptual testing.
