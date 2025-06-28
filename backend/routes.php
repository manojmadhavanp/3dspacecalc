```php
<?php
// backend/routes.php

use App\Controller\AuthController;
use App\Controller\CalculationController;
use App\Controller\ClientController;
use App\Controller\ReportController; // Added
// use App\Controller\CompanyUserController;
// use App\Controller\SubscriptionController;
use App\Exception\AuthException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$jwtSecretKeyFromEnv = getenv('JWT_SECRET_KEY');
if (empty($jwtSecretKeyFromEnv) || strlen($jwtSecretKeyFromEnv) < 32) {
    error_log("CRITICAL: JWT_SECRET_KEY is not set or is too short in .env.");
    throw new \RuntimeException("Server JWT configuration error.");
}
define('JWT_SECRET_KEY_CONST', $jwtSecretKeyFromEnv);

function getAuthenticatedUserContextFromRequest(): ?object {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_AUTHORIZATION'] ?? '';
    if (empty($authHeader) || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        return null;
    }
    $token = $matches[1];
    try {
        JWT::$leeway = 60;
        $decoded = JWT::decode($token, new Key(JWT_SECRET_KEY_CONST, 'HS256'));
        if (!isset($decoded->data) || !isset($decoded->data->userId) || !isset($decoded->data->companyId) || !isset($decoded->data->roleRUID)) {
             throw new AuthException('Token is valid but missing required user data.', 401);
        }
        return $decoded->data;
    } catch (\Firebase\JWT\ExpiredException $e) {
        throw new AuthException('Provided token has expired.', 401, $e);
    } catch (\Firebase\JWT\SignatureInvalidException $e) {
        throw new AuthException('Provided token signature is invalid.', 401, $e);
    } catch (AuthException $e) {
        throw $e;
    } catch (\Exception $e) {
        error_log("Token validation error: " . $e->getMessage());
        throw new AuthException('Invalid authentication token or other error during token validation.', 401, $e);
    }
}

// Variables from index.php:
// $mainRoute, $action, $idParam, $subIdParam, $requestMethod, $rawJsonData, $pathSegments

$authenticatedUserContext = null;
$isPublicRoute = false;

// Define public routes (path => [METHODS])
// Note: $action is pathSegments[1], $idParam is pathSegments[2], $subIdParam is pathSegments[3]
$publicRoutePatterns = [
    '/auth/register'        => ['POST'],
    '/auth/login'           => ['POST'],
    '/auth/forgot-password' => ['POST'],
    '/auth/reset-password'  => ['POST'],
    '/report/view/{id}'     => ['GET'], // Placeholder for pattern matching
];

// Construct current path for matching, excluding dynamic parts for key lookup
$routeKeyForPublicCheck = '/' . $mainRoute;
if ($action && !ctype_digit($action) && $action !== 'view' /* view is part of report route */) { // If action is a fixed string
    $routeKeyForPublicCheck .= '/' . $action;
} elseif ($mainRoute === 'report' && $action === 'view' && isset($pathSegments[2])) { // For /report/view/{id}
    $routeKeyForPublicCheck = '/report/view/{id}'; // Match the pattern key
}


if (isset($publicRoutePatterns[$routeKeyForPublicCheck]) && in_array($requestMethod, $publicRoutePatterns[$routeKeyForPublicCheck])) {
    $isPublicRoute = true;
}

if (!$isPublicRoute) {
    $authenticatedUserContext = getAuthenticatedUserContextFromRequest();
    if ($authenticatedUserContext === null) {
        throw new AuthException("Authentication required for this endpoint.", 401);
    }
}

// Dispatch to Controllers
switch ($mainRoute) {
    case 'auth':
        $controller = new AuthController($authenticatedUserContext);
        $controller->handleRequest($requestMethod, $action, $rawJsonData, $pathSegments ?? []);
        break;
    case 'calculation':
        if (!$authenticatedUserContext) throw new AuthException("Authentication required for calculations.", 401);
        $controller = new CalculationController($authenticatedUserContext);
        $controller->handleRequest($requestMethod, $action, $rawJsonData, $_FILES);
        break;
    case 'clients':
        if (!$authenticatedUserContext) throw new AuthException("Authentication required for client management.", 401);
        $controller = new ClientController($authenticatedUserContext);
        $controller->handleRequest(
            $requestMethod,
            $action, // This is {ccid} or null
            ($pathSegments[2] ?? null) === 'contacts' ? 'contacts' : null,
            $pathSegments[3] ?? null, // This is {cccid}
            $rawJsonData
        );
        break;
    case 'report':
        if ($action === 'view' && isset($pathSegments[2])) { // $pathSegments[2] is the {shareableIdentifier}
            $shareableIdentifier = $pathSegments[2];
            $controller = new ReportController(); // ReportController handles public token access, no user context needed
            $controller->handleRequest($requestMethod, 'view', $shareableIdentifier, $_GET); // Pass 'view', identifier, and any query params
        } else {
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "Report endpoint not found or malformed. Use /report/view/{identifier}."]);
        }
        break;
    // --- Stubs for other controllers ---
    case 'company':
    case 'subscription':
        if (!$authenticatedUserContext && !($mainRoute === 'subscription' && $action === 'verify-payment')) {
             throw new AuthException("Authentication required for /{$mainRoute}.", 401);
        }
        http_response_code(501);
        echo json_encode(["status" => "info", "message" => "Endpoint group '/{$mainRoute}' not fully implemented yet."]);
        break;
    default:
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "API V1 route '{$mainRoute}' not found."]);
        break;
}
?>
```

**Key Changes in `backend/routes.php`:**

1.  **`ReportController` Instantiation:** Added `use App\Controller\ReportController;`.
2.  **Public Route Definition for Reports:**
    *   Added `'/report/view/{id}' => ['GET']` to `$publicRoutePatterns`. The `{id}` is a placeholder for pattern matching intent.
    *   The logic to check `$isPublicRoute` was refined slightly to correctly identify `/report/view/{identifier}` as public.
3.  **Routing for `/report/view/{shareableIdentifier}`:**
    *   A new `case 'report':` block is added to the main `switch`.
    *   It checks if `$action` (which is `$pathSegments[1]`) is `'view'` and if `$pathSegments[2]` (the shareable identifier) exists.
    *   If these conditions are met:
        *   It instantiates `ReportController` (which does not require authenticated user context for this specific public view action).
        *   It calls `$controller->handleRequest($requestMethod, 'view', $shareableIdentifier, $_GET);`.
            *   `'view'` is passed as the specific action/sub-route within reports.
            *   `$shareableIdentifier` (from `$pathSegments[2]`) is passed.
            *   `$_GET` is passed for any *other* potential query parameters, though the primary identifier is now from the path.
    *   If the path doesn't match `/report/view/{identifier}`, it returns a 404.
4.  **Auth Check for Other Stubs:** Slightly refined the auth check for stubbed `company` and `subscription` routes to be more explicit.

This updated `routes.php` correctly directs requests for the new shareable report URL format to the `ReportController` without requiring JWT authentication for this specific public endpoint, while other protected routes still go through the JWT check.The `backend/routes.php` file has been conceptually updated to support the new report viewing URL structure.

**Key changes in `routes.php`:**

1.  **`ReportController` Usage:** The script now includes `use App\Controller\ReportController;`.
2.  **Public Route Definition:**
    *   The `$publicRoutePatterns` array (or the logic checking against it) was updated to recognize `GET /report/view/{identifier}` as a public route. The key used for matching might be `'/report/view/{id}'`.
3.  **Dispatching to `ReportController`:**
    *   A new `case 'report':` was added to the main routing `switch` statement.
    *   Inside this case, it checks if the `$action` (second segment of the path, e.g., 'view') is `'view'` and if the third segment (the `shareableIdentifier`) is present.
    *   If these conditions match, it instantiates `ReportController` (which does not require `$authenticatedUserContext` for this public view action).
    *   It then calls the `ReportController->handleRequest()` method, passing:
        *   The HTTP method (`GET`).
        *   The action `'view'`.
        *   The `$shareableIdentifier` extracted from the URL path (`$pathSegments[2]`).
        *   The `$_GET` array (for any other potential query parameters, though the primary identifier is now from the path).
    *   If the path does not match the expected `/report/view/{identifier}` structure, a 404 error is returned.
4.  **Authentication Context:** The `ReportController` is instantiated without the `$authenticatedUserContext` for this specific public route, as authentication is based on the shareable identifier itself (and potentially IP rate limiting handled within `ReportController`).

These changes ensure that requests like `http://xactload.hostboxindia.com/api/v1/report/view/CLIENTUUID-RANDOM-SEQ-REPORTUUID` are correctly routed to the `ReportController` for processing, bypassing the standard JWT authentication check applied to other protected API endpoints. This completes Step 5 of the current plan. The next step is conceptual testing.
