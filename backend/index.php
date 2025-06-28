```php
<?php
// backend/index.php

ini_set('display_errors', 1);
error_reporting(E_ALL);

// Set a default timezone if not set in php.ini
if(!date_default_timezone_get()){ date_default_timezone_set('UTC'); }

header('Content-Type: application/json');
// More specific origin for production is highly recommended
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

// --- Simple Autoloader ---
spl_autoload_register(function ($className) {
    $prefix = 'App\\';
    $base_dir = __DIR__ . '/src/'; // Assuming App namespace maps to src directory
    $len = strlen($prefix);
    if (strncmp($prefix, $className, $len) !== 0) {
        return;
    }
    $relative_class = substr($className, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) {
        require_once $file; // Use require_once for classes
    } else {
        // Fallback for files that might be directly in src (like api_calc_engine_placeholder.php, though it should be refactored into a class)
        $fallbackFile = __DIR__ . '/src/' . str_replace('\\', '/', $className) . '.php';
         if (file_exists($fallbackFile)) {
             require_once $fallbackFile;
         } else {
             // Try one level up for api_calc_engine_placeholder.php as per previous structure
            $calcEngineFile = __DIR__ . '/' . basename(str_replace('\\', '/', $className)) . '.php';
            if (file_exists($calcEngineFile) && basename($calcEngineFile) === 'api_calc_engine_placeholder.php') {
                 require_once $calcEngineFile;
            } else {
                error_log("Autoloader: Failed to load class/file {$className}. Tried: {$file}, {$fallbackFile}, {$calcEngineFile}");
            }
         }
    }
});

// --- Environment Variable Loading ---
if (!function_exists('loadEnv')) {
    function loadEnv($filePath) {
        if (!file_exists($filePath)) {
            error_log(".env file not found at $filePath. Using defaults if available.");
            return [];
        }
        $env = []; $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key); $value = trim(trim($value), "\"'");
            $_ENV[$key] = $value; putenv("$key=$value"); $env[$key] = $value;
        }
        return $env;
    }
}
// Assumes .env is in the project root, i.e., parent directory of 'backend'
loadEnv(__DIR__ . '/../.env');


// --- Global Configuration Loading ---
try {
    App\Config\ItemTypeConfig::loadConfig();
    App\Config\ContainerLoader::getAllContainers(); // Pre-caches
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Server configuration error (configs): " . $e->getMessage()]);
    exit;
}

// --- Routing ---
$basePath = getenv('API_BASE_PATH') ?: '/api/v1';
$requestUri = $_SERVER['REQUEST_URI'];
$requestMethod = $_SERVER['REQUEST_METHOD'];
$rawJsonData = file_get_contents('php://input');

$endpointPath = '';
if (strpos($requestUri, $basePath) === 0) {
    $endpointPath = substr($requestUri, strlen($basePath));
}
$endpointPath = strtok($endpointPath, '?');

$pathSegments = explode('/', trim($endpointPath, '/'));
$mainRoute = $pathSegments[0] ?? '';
$action = $pathSegments[1] ?? null; // e.g. 'register', 'login', or an ID like {ccid}
$idParam = $pathSegments[2] ?? null; // e.g. {uid} or {cccid}
$subIdParam = $pathSegments[3] ?? null;


// --- Route Handling ---
try {
    switch ($mainRoute) {
        case 'auth':
            $authController = new App\Controller\AuthController();
            // Pass $action (e.g. 'register', 'login') to controller's handleRequest
            $authController->handleRequest($requestMethod, $action, $rawJsonData, $pathSegments);
            break;
        case 'calculation':
            // Ensure Auth middleware/check would be here for protected routes
            $calcController = new App\Controller\CalculationController();
            $calcController->handleRequest($requestMethod, $action, $rawJsonData, $_FILES);
            break;

        // Placeholder for other controllers - they would need to be created
        case 'clients':
            // $clientController = new App\Controller\ClientController();
            // $clientController->handleRequest($requestMethod, $action, $rawJsonData, $idParam, $subIdParam); // $action is {ccid}, $idParam is 'contacts', $subIdParam is {cccid}
            http_response_code(501); echo json_encode(["status" => "info", "message" => "/clients endpoints not fully implemented."]);
            break;
        case 'company':
             if ($action === 'users') {
                // $compUserController = new App\Controller\CompanyUserController();
                // $compUserController->handleRequest($requestMethod, $idParam, $rawJsonData, $pathSegments); // $idParam is {uid} or null
                http_response_code(501); echo json_encode(["status" => "info", "message" => "/company/users not fully implemented."]);
             } else {
                http_response_code(404); echo json_encode(["status" => "error", "message" => "Company action '{$action}' not found."]);
             }
            break;
        case 'subscription':
            // $subController = new App\Controller\SubscriptionController();
            // $subController->handleRequest($requestMethod, $action, $rawJsonData, $_POST);
            http_response_code(501); echo json_encode(["status" => "info", "message" => "/subscription not fully implemented."]);
            break;
        case 'report':
            // $reportController = new App\Controller\ReportController();
            // $reportController->handleRequest($requestMethod, $action, $_GET); // $action is {searchId}
            http_response_code(501); echo json_encode(["status" => "info", "message" => "/report not fully implemented."]);
            break;
        default:
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "API route '{$mainRoute}' not found."]);
            break;
    }
} catch (\App\Exception\AuthException $e) {
    http_response_code($e->getCode() ?: 401);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid input: " . $e->getMessage()]);
} catch (\PDOException $e) {
    error_log("API Database Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "A database error occurred."]);
} catch (\Exception $e) {
    error_log("API General Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "An unexpected server error occurred."]);
}

?>
```
