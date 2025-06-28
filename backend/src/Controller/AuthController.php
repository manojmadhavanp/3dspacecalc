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
        // It's better to fetch ENV variables once, e.g. in constructor or dedicated config class
        $this->jwtSecretKey = getenv('JWT_SECRET_KEY') ?: 'fallback_secret_6789012345_abcde_1234567890_xyz_0987654321_top_secret';
         if (empty($this->jwtSecretKey) || strlen($this->jwtSecretKey) < 32) {
            error_log("CRITICAL: JWT_SECRET_KEY is not set or is too short. Please define a strong secret in your .env file.");
            // Potentially throw an exception here in a real application to halt if misconfigured
        }
    }

    private function getAuthenticatedUserIdFromToken(): ?int {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_AUTHORIZATION'] ?? '';
        if (empty($authHeader) || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            throw new AuthException('Authorization header with Bearer token is missing or malformed.', 401);
        }
        $token = $matches[1];
        try {
            JWT::$leeway = 60; // Allow 60 seconds clock skew
            $decoded = JWT::decode($token, new Key($this->jwtSecretKey, 'HS256'));
            if (!isset($decoded->data->userId)) {
                 throw new AuthException('Token does not contain required user data.', 401);
            }
            return (int)$decoded->data->userId;
        } catch (\Firebase\JWT\ExpiredException $e) {
            error_log("Auth token expired: " . $e->getMessage());
            throw new AuthException('Provided token has expired.', 401);
        } catch (\Firebase\JWT\SignatureInvalidException $e) {
            error_log("Auth token signature invalid: " . $e->getMessage());
            throw new AuthException('Provided token signature is invalid.', 401);
        } catch (\Exception $e) { // Other JWT or general errors
            error_log("Auth token validation failed: " . $e->getMessage());
            throw new AuthException('Invalid authentication token.', 401);
        }
    }

    public function handleRequest(string $method, ?string $action, ?string $rawJsonData, array $pathSegments): void {
        $data = null;
        if (($method === 'POST' || $method === 'PUT' || $method === 'PATCH') && !empty($rawJsonData)) {
            $data = json_decode($rawJsonData, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->jsonResponse(['status' => 'error', 'message' => 'Invalid JSON payload: ' . json_last_error_msg()], 400);
                return;
            }
        }

        try {
            switch ($action) {
                case 'register':      this->route($method, ['POST'], fn() => $this->register($data)); break;
                case 'login':         this->route($method, ['POST'], fn() => $this->login($data)); break;
                case 'logout':        this->route($method, ['POST'], fn() => $this->logout()); break; // Requires auth
                case 'forgot-password': this->route($method, ['POST'], fn() => $this->forgotPassword($data)); break;
                case 'reset-password':  this->route($method, ['POST'], fn() => $this->resetPassword($data)); break;
                default:
                    $this->jsonResponse(["status" => "error", "message" => "Auth action '{$action}' not found."], 404);
                    break;
            }
        } catch (\InvalidArgumentException $e) { $this->jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 400);
        } catch (AuthException $e) { $this->jsonResponse(['status' => 'error', 'message' => $e->getMessage()], $e->getCode() ?: 401);
        } catch (\PDOException $e) { $this->handleDbError($e, $action);
        } catch (\Exception $e) { $this->handleGenericError($e, $action); }
    }

    private function route(string $currentMethod, array $allowedMethods, callable $callback): void {
        if (!in_array($currentMethod, $allowedMethods)) {
            $this->methodNotAllowed($allowedMethods);
            return;
        }
        $callback();
    }

    private function jsonResponse(array $data, int $statusCode): void {
        http_response_code($statusCode);
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function handleDbError(\PDOException $e, ?string $action): void {
        error_log("Database error in AuthController action '{$action}': " . $e->getMessage());
        $this->jsonResponse(['status' => 'error', 'message' => 'A database error occurred.'], 500);
    }
    private function handleGenericError(\Exception $e, ?string $action): void {
        error_log("Error in AuthController action '{$action}': " . $e->getMessage() . "\n" . $e->getTraceAsString());
        $this->jsonResponse(['status' => 'error', 'message' => 'An unexpected server error occurred.'], 500);
    }


    private function register(?array $data): void {
        if ($data === null || !isset($data['user']) || !is_array($data['user']) || !isset($data['company']) || !is_array($data['company'])) {
            throw new \InvalidArgumentException('Invalid registration data structure. "user" and "company" objects are required.');
        }
        $result = $this->authService->registerCompanyAndUser($data['user'], $data['company']);
        $this->jsonResponse($result, $result['success'] ? 201 : (str_contains($result['message'], 'exists') ? 409 : 400));
    }

    private function login(?array $data): void {
        if ($data === null || empty($data['identifier']) || empty($data['password'])) {
            throw new \InvalidArgumentException('Identifier (email/phone) and password are required.');
        }
        $result = $this->authService->loginUser($data['identifier'], $data['password']);
        $statusCode = $result['success'] ? 200 : (str_contains(strtolower($result['message']), 'account is') ? 403 : 401);
        $this->jsonResponse($result, $statusCode);
    }

    private function logout(): void {
        $userId = $this->getAuthenticatedUserIdFromToken(); // This will throw AuthException if token invalid/missing
        $result = $this->authService->logoutUser($userId);
        $this->jsonResponse($result, $result['success'] ? 200 : 500);
    }

    private function forgotPassword(?array $data): void {
        if ($data === null || empty($data['email'])) throw new \InvalidArgumentException('Email is required.');
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Invalid email format.');
        $result = $this->authService->requestPasswordReset($data['email']);
        $this->jsonResponse($result, 200); // Always 200 for forgot password for security
    }

    private function resetPassword(?array $data): void {
        if ($data === null || empty($data['token']) || empty($data['newPassword'])) {
            throw new \InvalidArgumentException('Token and newPassword are required.');
        }
        if (strlen($data['newPassword']) < 8) throw new \InvalidArgumentException('New password must be at least 8 characters long.');
        $result = $this->authService->resetPasswordWithToken($data['token'], $data['newPassword']);
        $this->jsonResponse($result, $result['success'] ? 200 : 400);
    }

    private function methodNotAllowed(array $allowedMethods): void {
        http_response_code(405); header('Allow: ' . implode(', ', $allowedMethods));
        echo json_encode(["status" => "error", "message" => "Method not allowed. Allowed: " . implode(', ', $allowedMethods)]);
    }
}
?>
```
