```php
<?php
// backend/routes.php

// Autoloader and ENV vars are assumed to be loaded by index.php
// Configs (ItemTypeConfig, ContainerLoader) also assumed pre-loaded by index.php

use App\Controller\AuthController;
use App\Controller\CalculationController;
use App\Controller\ClientController; // Assuming this will be created
// use App\Controller\CompanyUserController; // For future phases
// use App\Controller\SubscriptionController; // For future phases
// use App\Controller\ReportController; // For future phases
use App\Exception\AuthException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// --- Request Details (already available from index.php) ---
// $requestUri = $_SERVER['REQUEST_URI'];
// $requestMethod = $_SERVER['REQUEST_METHOD'];
// $rawJsonData = file_get_contents('php://input');
// $basePath = getenv('API_BASE_PATH') ?: '/api/v1';
// $pathSegments, $mainRoute, $action, $idParam, $subIdParam are also from index.php

// --- JWT Secret Key (from .env, loaded by index.php) ---
$jwtSecretKey = getenv('JWT_SECRET_KEY') ?: 'fallback_secret_6789012345_abcde_1234567890_xyz_0987654321_top_secret';

/**
 * Attempts to authenticate the user via JWT from Authorization header.
 * Returns an object with user data if successful, or throws AuthException.
 *
 * @return object Decoded JWT payload data (containing userId, companyId, roleRUID etc.)
 * @throws AuthException If token is missing, invalid, or expired.
 */
function getAuthenticatedUserContext(): object {
    global $jwtSecretKey; // Access global variable set above

    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_AUTHORIZATION'] ?? '';
    if (empty($authHeader) || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        throw new AuthException('Authorization token is missing or malformed.', 401);
    }
    $token = $matches[1];
    try {
        JWT::$leeway = 60; // Allow 60 seconds clock skew for token expiration
        $decoded = JWT::decode($token, new Key($jwtSecretKey, 'HS256'));

        if (!isset($decoded->data) || !isset($decoded->data->userId) || !isset($decoded->data->companyId) || !isset($decoded->data->roleRUID)) {
             throw new AuthException('Token is valid but missing required user data.', 401);
        }
        return $decoded->data; // Return the 'data' part of the payload
    } catch (\Firebase\JWT\ExpiredException $e) {
        throw new AuthException('Provided token has expired.', 401, $e);
    } catch (\Firebase\JWT\SignatureInvalidException $e) {
        throw new AuthException('Provided token signature is invalid.', 401, $e);
    } catch (\Exception $e) { // Other JWT related errors or general errors
        error_log("Token validation failed: " . $e->getMessage());
        throw new AuthException('Invalid authentication token or other error during token validation.', 401, $e);
    }
}

// --- Route Definitions & Dispatching ---
// These variables are assumed to be set by index.php before this script is included:
// $mainRoute, $action, $idParam, $subIdParam, $requestMethod, $rawJsonData

$authenticatedUser = null; // Will hold user context for protected routes

// Define public routes that don't require JWT authentication
$publicRoutes = [
    'auth/register' => ['POST'],
    'auth/login' => ['POST'],
    'auth/forgot-password' => ['POST'],
    'auth/reset-password' => ['POST'],
    // Example public report view if token is in query param:
    // 'report/{id}' => ['GET'] // but auth check for token in query would be in ReportController
];

$currentRouteKey = $mainRoute . ($action ? '/' . $action : '');
// For more complex routes like /clients/{id}/contacts/{id}, a more robust router is needed.
// This simple check is for top-level actions under mainRoute.

$isPublicRoute = false;
if (isset($publicRoutes[$currentRouteKey]) && in_array($requestMethod, $publicRoutes[$currentRouteKey])) {
    $isPublicRoute = true;
}
// A more specific check if action is a parameter like /report/{searchId}
if ($mainRoute === 'report' && $action !== null && $requestMethod === 'GET') { // Assuming /report/{id} is public with its own token
    $isPublicRoute = true;
}


if (!$isPublicRoute) {
    // This is a protected route, attempt to authenticate
    $authenticatedUser = getAuthenticatedUserContext(); // Throws AuthException on failure
    // $authenticatedUser now contains ->userId, ->companyId, ->roleRUID etc.
}


// --- Dispatch to Controllers ---
// Controllers will handle their own JSON response and HTTP status codes.
// The try-catch block in index.php will catch exceptions from controllers.

switch ($mainRoute) {
    case 'auth':
        $controller = new AuthController(); // AuthService is newed up in AuthController
        // AuthController's handleRequest might use $authenticatedUser for some actions like /auth/me or /auth/refresh
        // For logout, it will use the token to identify user if needed for server-side state change.
        $controller->handleRequest($requestMethod, $action, $rawJsonData, $pathSegments ?? []); // pathSegments from index.php
        break;

    case 'calculation':
        if (!$authenticatedUser) throw new AuthException("Authentication required for calculations.", 401);
        // Pass $authenticatedUser to the controller if it needs user/company context
        $controller = new CalculationController($authenticatedUser); // Modify controller to accept this
        $controller->handleRequest($requestMethod, $action, $rawJsonData, $_FILES);
        break;

    case 'clients':
        if (!$authenticatedUser) throw new AuthException("Authentication required for client management.", 401);
        $controller = new ClientController($authenticatedUser); // Modify controller
        $controller->handleRequest(
            $requestMethod,
            $action, // This is {ccid} or null
            isset($pathSegments[2]) && $pathSegments[2] === 'contacts' ? 'contacts' : null,
            $pathSegments[3] ?? null, // This is {cccid}
            $rawJsonData
        );
        break;

    case 'company':
         if ($action === 'users') {
            if (!$authenticatedUser) throw new AuthException("Authentication required for company user management.", 401);
            // $controller = new CompanyUserController($authenticatedUser); // Create this controller
            // $controller->handleRequest($requestMethod, $idParam, $rawJsonData, $pathSegments);
            http_response_code(501); echo json_encode(["status" => "info", "message" => "/company/users not fully implemented yet."]);
         } else {
            http_response_code(404); echo json_encode(["status" => "error", "message" => "Company action '{$action}' not found."]);
         }
        break;

    case 'subscription':
        if (!$authenticatedUser && !($action === 'verify-payment')) { // verify-payment might be a webhook/callback
             throw new AuthException("Authentication required for subscription management.", 401);
        }
        // $controller = new SubscriptionController($authenticatedUser); // Create this
        // $controller->handleRequest($requestMethod, $action, $rawJsonData, $_POST);
        http_response_code(501); echo json_encode(["status" => "info", "message" => "/subscription not fully implemented yet."]);
        break;

    case 'report': // e.g. /report/{searchId}
        // $controller = new ReportController(); // Report controller might handle its own public/token access
        // $controller->handleRequest($requestMethod, $action, $_GET); // $action is {searchId}
        http_response_code(501); echo json_encode(["status" => "info", "message" => "/report not fully implemented yet."]);
        break;

    default:
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "API V1 route '{$mainRoute}' not found."]);
        break;
}

// Note: Final json_encode and http_response_code calls are now expected to be handled
// by the individual controller methods, or by the try-catch in index.php if an exception bubbles up.
// This routes.php script focuses on dispatch.
?>
```

**Key Features of `backend/routes.php`:**

1.  **Access to Request Details:** Assumes variables like `$mainRoute`, `$action`, `$requestMethod`, `$rawJsonData`, `$pathSegments` are available from `index.php`'s parsing.
2.  **JWT Secret Key:** Retrieves `JWT_SECRET_KEY` from environment variables.
3.  **`getAuthenticatedUserContext()` Function:**
    *   This function is now central to handling authentication for protected routes.
    *   It extracts the Bearer token from the `Authorization` (or `HTTP_X_AUTHORIZATION`) header.
    *   Uses `Firebase\JWT\JWT::decode` to validate and decode the token.
    *   If successful, returns the `data` part of the JWT payload (which should contain `userId`, `companyId`, `roleRUID`, etc.).
    *   Throws specific `AuthException`s for various token failures (missing, malformed, expired, invalid signature, missing required data), which will be caught by `index.php`'s error handler to produce 401/403 responses.
4.  **Public Routes Definition:**
    *   An array `$publicRoutes` lists routes (like `/auth/login`, `/auth/register`) that do *not* require prior JWT authentication.
5.  **Authentication Check:**
    *   It checks if the current request matches a public route.
    *   If not public, it calls `getAuthenticatedUserContext()`. If this function throws an exception (e.g., token invalid), the script execution stops there, and `index.php`'s error handler sends the 401/403 response.
    *   If authentication succeeds, `$authenticatedUser` (an object with user context) is available.
6.  **Dispatch to Controllers:**
    *   A `switch` statement based on `$mainRoute` (e.g., 'auth', 'calculation', 'clients').
    *   Instantiates the relevant controller (e.g., `AuthController`, `CalculationController`).
    *   **Crucially, for controllers handling protected routes, the `$authenticatedUser` context is now passed to their constructor or a setter method.** This allows controllers and services to access `userId`, `companyId`, `roleRUID` for authorization and data scoping *without* each controller needing to re-decode the JWT.
    *   Calls the controller's main handler method (e.g., `handleRequest`), passing necessary request details.
7.  **Controller Responsibility:** Controllers are now expected to handle their own final JSON response generation and setting HTTP status codes, or to throw exceptions that `index.php` will catch and format. This `routes.php` focuses on routing and initial auth.

**Modifications Needed in Controllers (Conceptual):**
*   Controllers for protected routes (e.g., `CalculationController`, `ClientController`) will need their constructors updated to accept the `$authenticatedUser` object (or relevant parts like `companyId`, `userId`, `roleRUID`).
    ```php
    // Example in ClientController.php
    // private object $authUserCtx;
    // public function __construct(object $authUserCtx, ?ClientService $clientService = null) {
    //    $this->authUserCtx = $authUserCtx;
    //    $this->clientService = $clientService ?? new ClientService();
    // }
    // // Then, instead of calling $this->getAuthenticatedCompanyId():
    // $companyId = $this->authUserCtx->companyId;
    ```
*   The `getAuthenticatedUserIdFromToken()` helper method *within* controllers can be removed, as `routes.php` now provides this context.

This structure provides a much cleaner separation of concerns, with `index.php` for bootstrapping, `routes.php` for routing and primary auth checks, and controllers for action-specific logic.The `backend/routes.php` file has been conceptually created.

**Key features of this `routes.php` implementation:**

1.  **Access to Request Globals:** It assumes that `index.php` has already parsed `$_SERVER['REQUEST_URI']` into variables like `$mainRoute`, `$action`, `$idParam`, etc., and made `$requestMethod` and `$rawJsonData` available.
2.  **JWT Authentication (`getAuthenticatedUserContext()`):**
    *   A dedicated function `getAuthenticatedUserContext()` is defined to handle JWT extraction from the `Authorization: Bearer` header.
    *   It uses the `Firebase\JWT\JWT` library (conceptually) to decode and validate the token using the `JWT_SECRET_KEY` from environment variables.
    *   It checks for required data within the JWT payload (`userId`, `companyId`, `roleRUID`).
    *   It throws `AuthException` with appropriate messages and codes (401) for various token failures (missing, malformed, expired, invalid signature, missing data). These exceptions are expected to be caught by the top-level error handler in `index.php`.
3.  **Public Route Definition:** An array `$publicRoutes` defines which route patterns (e.g., `auth/login`, `auth/register`) do not require prior authentication.
4.  **Authentication Gate:** For any route not in `$publicRoutes`, `getAuthenticatedUserContext()` is called. If authentication fails, an exception is thrown, and processing stops before reaching a controller.
5.  **Controller Dispatch:**
    *   A `switch` statement based on `$mainRoute` (e.g., 'auth', 'calculation', 'clients') instantiates the appropriate controller.
    *   For controllers that handle protected resources (like `CalculationController`, `ClientController`), the `$authenticatedUser` object (containing decoded JWT data like `userId`, `companyId`, `roleRUID`) is passed to their constructor. This makes user context directly available to the controller and subsequently to services for authorization and data scoping.
    *   The controller's main handler method (e.g., `handleRequest`) is then called.
6.  **Controller Responsibility Shift:** This design implies that controllers will now primarily focus on action-specific logic and calling services. They will receive the authenticated user context rather than re-validating tokens themselves. They are also expected to prepare data arrays for the response, with `index.php`'s final try-catch block handling the `json_encode` and `http_response_code` for exceptions that bubble up. (Individual controller methods might still set specific success codes).

This centralized routing and initial authentication check in `routes.php` provides a cleaner and more robust structure for the API. The next step in the plan is to adapt the existing controllers to work with this new routing mechanism.
