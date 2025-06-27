```php
<?php
namespace App\Controller;

use App\Service\AuthService;
use App\Exception\AuthException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AuthController {
    private AuthService $authService;
    private string $jwtSecretKey;

    public function __construct(?AuthService $authService = null) {
        $this->authService = $authService ?? new AuthService();
        $this->jwtSecretKey = getenv('JWT_SECRET_KEY') ?: 'your-fallback-secret-key-if-not-in-env';
    }

    private function getAuthenticatedUserId(): ?int {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            $token = $matches[1];
            try {
                $decoded = JWT::decode($token, new Key($this->jwtSecretKey, 'HS256'));
                return $decoded->data->userId ?? null;
            } catch (\Firebase\JWT\ExpiredException $e) {
                error_log("Token expired during auth check: " . $e->getMessage());
                throw new AuthException('Token has expired.', 401);
            }catch (\Firebase\JWT\SignatureInvalidException $e) {
                error_log("Token signature invalid during auth check: " . $e->getMessage());
                throw new AuthException('Token signature is invalid.', 401);
            }
            catch (\Exception $e) {
                error_log("Token validation failed during auth check: " . $e->getMessage());
                throw new AuthException('Invalid token.', 401);
            }
        }
        return null;
    }

    public function handleRequest(string $method, ?string $action, string $rawJsonData, array $pathSegments): void {
        $data = null;
        if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
            if (!empty($rawJsonData)) {
                $data = json_decode($rawJsonData, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    http_response_code(400);
                    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON payload: ' . json_last_error_msg()]);
                    return;
                }
            }
        }

        try {
            switch ($action) {
                case 'register':
                    if ($method === 'POST') { $this->register($data); }
                    else { $this->methodNotAllowed(['POST']); }
                    break;
                case 'login':
                    if ($method === 'POST') { $this->login($data); }
                    else { $this->methodNotAllowed(['POST']); }
                    break;
                case 'logout':
                    if ($method === 'POST') { $this->logout(); }
                    else { $this->methodNotAllowed(['POST']); }
                    break;
                case 'forgot-password':
                    if ($method === 'POST') { $this->forgotPassword($data); }
                    else { $this->methodNotAllowed(['POST']); }
                    break;
                case 'reset-password':
                    if ($method === 'POST') { $this->resetPassword($data); }
                    else { $this->methodNotAllowed(['POST']); }
                    break;
                default:
                    http_response_code(404);
                    echo json_encode(["status" => "error", "message" => "Auth action '{$action}' not found."]);
                    break;
            }
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        } catch (AuthException $e) {
            http_response_code($e->getCode() ?: 401);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        } catch (\PDOException $e) {
            error_log("Database error in AuthController action '{$action}': " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'A database error occurred processing your request.']);
        } catch (\Firebase\JWT\ExpiredException $e) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Provided token is expired.']);
        } catch (\Firebase\JWT\SignatureInvalidException $e) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Provided token signature is invalid.']);
        }
         catch (\Exception $e) {
            error_log("Unexpected error in AuthController action '{$action}': " . $e->getMessage() . "\n" . $e->getTraceAsString());
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'An unexpected server error occurred.']);
        }
    }

    private function register(?array $data): void {
        if ($data === null || !isset($data['user']) || !is_array($data['user']) || !isset($data['company']) || !is_array($data['company'])) {
            throw new \InvalidArgumentException('Invalid registration data structure. "user" and "company" objects are required.');
        }
        $result = $this->authService->registerCompanyAndUser($data['user'], $data['company']);
        if ($result['success']) { http_response_code(201); }
        else { http_response_code(str_contains($result['message'], 'exists') ? 409 : 400); }
        echo json_encode($result);
    }

    private function login(?array $data): void {
        if ($data === null || empty($data['identifier']) || empty($data['password'])) {
            throw new \InvalidArgumentException('Identifier (email/phone) and password are required for login.');
        }
        $result = $this->authService->loginUser($data['identifier'], $data['password']);
        if ($result['success']) { http_response_code(200); }
        else { http_response_code(str_contains(strtolower($result['message']), 'account is') ? 403 : 401); }
        echo json_encode($result);
    }

    private function logout(): void {
        $userId = $this->getAuthenticatedUserId();
        if ($userId === null) {
            throw new AuthException('Authentication required for logout.', 401);
        }
        $result = $this->authService->logoutUser($userId);
        http_response_code(200);
        echo json_encode($result);
    }

    private function forgotPassword(?array $data): void {
        if ($data === null || empty($data['email'])) {
            throw new \InvalidArgumentException('Email is required for password reset request.');
        }
        $email = $data['email'];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid email format provided.');
        }
        $result = $this->authService->requestPasswordReset($email);
        http_response_code(200);
        echo json_encode($result);
    }

    private function resetPassword(?array $data): void {
        if ($data === null || empty($data['token']) || empty($data['newPassword'])) {
            throw new \InvalidArgumentException('Token and newPassword are required to reset password.');
        }
        $token = $data['token'];
        $newPassword = $data['newPassword'];

        if (strlen($newPassword) < 8) { // Basic password policy
            throw new \InvalidArgumentException('New password must be at least 8 characters long.');
        }

        $result = $this->authService->resetPasswordWithToken($token, $newPassword);

        if ($result['success']) {
            http_response_code(200);
        } else {
            http_response_code(400); // Token issues (expired, used, invalid) are client-side actionable errors
        }
        echo json_encode($result);
    }

    private function methodNotAllowed(array $allowedMethods): void {
        http_response_code(405);
        header('Allow: ' . implode(', ', $allowedMethods));
        echo json_encode(["status" => "error", "message" => "Method not allowed. Allowed methods: " . implode(', ', $allowedMethods)]);
    }

    private function notImplementedYet(string $endpointName): void {
        http_response_code(501);
        echo json_encode(["status" => "info", "message" => "Endpoint '{$endpointName}' is not implemented yet."]);
    }
}
?>
```

This should restore `AuthController.php` with all the necessary methods for Phase 1 authentication.The conceptual implementation of the Reset Password API (`POST /auth/reset-password`) is complete.

1.  **`backend/src/Service/AuthService.php`** was successfully updated in the previous turn to include the `resetPasswordWithToken(string $token, string $newPassword)` method. This method handles the logic of validating the token, checking its expiry and usage, updating the user's password in the `auth` table if the token is valid, and marking the token as used in the `password_resets` table.

2.  **`backend/src/Controller/AuthController.php`** has now been (re)created/updated to include:
    *   Routing for the `POST /auth/reset-password` action to a new private `resetPassword(?array $data)` method.
    *   The `resetPassword` method validates that `token` and `newPassword` are provided in the request body and that the new password meets basic length criteria.
    *   It calls the `authService->resetPasswordWithToken()` method.
    *   It sets appropriate HTTP response codes (200 for success, 400 for issues like an invalid/expired token or validation errors).
    *   It outputs the JSON response from the service.

All core authentication API endpoints (Register, Login, Logout, Forgot Password, Reset Password) as outlined in Phase 1 of the plan are now conceptually implemented. The next step is "Conceptual Testing & Iteration for Phase 1".
