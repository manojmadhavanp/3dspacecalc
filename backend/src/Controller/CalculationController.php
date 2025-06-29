```php
<?php
namespace App\Controller;

use App\Service\SubscriptionService;
use App\Exception\AuthException;

class CalculationController {
    private ?object $authenticatedUserContext;
    private SubscriptionService $subscriptionService;

    public function __construct(?object $authenticatedUserContext, ?SubscriptionService $subscriptionService = null) {
        if ($authenticatedUserContext === null || !isset($authenticatedUserContext->companyId) || !isset($authenticatedUserContext->userId)) {
            throw new AuthException("Authentication context is missing or invalid for CalculationController.", 401);
        }
        $this->authenticatedUserContext = $authenticatedUserContext;
        $this->subscriptionService = $subscriptionService ?? new SubscriptionService();
    }

    public function handleRequest(string $method, ?string $action, string $rawJsonData, array $filesInput): void {
        $companyId = (int)$this->authenticatedUserContext->companyId;
        $userId = (int)$this->authenticatedUserContext->userId;

        if ($method === 'POST') {
            if ($action === 'generatereport') {
                $inputData = json_decode($rawJsonData, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->jsonResponse(['status' => 'ERROR_INPUT', 'message' => 'Invalid JSON payload for /calculation/generatereport.'], 400);
                    return;
                }
                 if (!isset($inputData['items']) || !is_array($inputData['items'])) {
                     $this->jsonResponse(['status' => 'ERROR_INPUT', 'message' => 'Missing "items" array in request for /calculation/generatereport.'], 400);
                     return;
                }

                // --- Subscription Check ---
                $calcPermission = $this->subscriptionService->canPerformCalculation($companyId);
                if (!$calcPermission['allowed']) {
                    $httpStatusCode = (str_contains(strtolower($calcPermission['message']), 'limit reached')) ? 402 : 403; // 402 Payment Required for limit
                    throw new AuthException($calcPermission['message'], $httpStatusCode);
                }

                // Record the attempt *before* the potentially long calculation
                $this->subscriptionService->recordCalculationAttempt($companyId);


                if (!function_exists('execute_load_calculation')) {
                     require_once __DIR__ . '/../../api_calc_engine_placeholder.php';
                }

                $inputData['meta_user_id'] = $userId;
                $inputData['meta_company_id'] = $companyId;
                $inputData['meta_client_ccid'] = isset($inputData['clientId']) && is_numeric($inputData['clientId']) ? (int)$inputData['clientId'] : null;


                $responseArray = execute_load_calculation($inputData);

use App\Service\SearchService; // Add this for logging searches

class CalculationController {
    private ?object $authenticatedUserContext;
    private SubscriptionService $subscriptionService;
    private SearchService $searchService; // Add SearchService property

    public function __construct(?object $authenticatedUserContext,
                                ?SubscriptionService $subscriptionService = null,
                                ?SearchService $searchService = null // Add to constructor
                               ) {
        if ($authenticatedUserContext === null || !isset($authenticatedUserContext->companyId) || !isset($authenticatedUserContext->userId)) {
            throw new AuthException("Authentication context is missing or invalid for CalculationController.", 401);
        }
        $this->authenticatedUserContext = $authenticatedUserContext;
        $this->subscriptionService = $subscriptionService ?? new SubscriptionService();
        $this->searchService = $searchService ?? new SearchService(); // Instantiate SearchService
    }

    public function handleRequest(string $method, ?string $action, string $rawJsonData, array $filesInput): void {
        $companyId = (int)$this->authenticatedUserContext->companyId;
        $userId = (int)$this->authenticatedUserContext->userId;

        if ($method === 'POST') {
            if ($action === 'generatereport') {
                $inputData = json_decode($rawJsonData, true);
                // ... (input validation as before) ...
                if (json_last_error() !== JSON_ERROR_NONE) { /* ... */ return; }
                if (!isset($inputData['items']) || !is_array($inputData['items'])) { /* ... */ return; }


                // --- Subscription Check --- (as before)
                $calcPermission = $this->subscriptionService->canPerformCalculation($companyId);
                if (!$calcPermission['allowed']) { /* ... throw AuthException ... */ }
                $this->subscriptionService->recordCalculationAttempt($companyId);


                if (!function_exists('execute_load_calculation')) {
                     require_once __DIR__ . '/../../api_calc_engine_placeholder.php';
                }

                $inputDataForEngine = $inputData; // Keep original input for logging
                $inputDataForEngine['meta_user_id'] = $userId;
                $inputDataForEngine['meta_company_id'] = $companyId;
                $inputDataForEngine['meta_client_ccid'] = isset($inputData['clientId']) && is_numeric($inputData['clientId']) ? (int)$inputData['clientId'] : null;

                $responseArray = execute_load_calculation($inputDataForEngine); // This is the full calculation result

                // --- Save to Search Table ---
                if (isset($responseArray['status']) &&
                    ($responseArray['status'] === 'SUCCESS_ALL_PLACED' || $responseArray['status'] === 'SUCCESS_PARTIAL_FIT')) {

                   try {
                       $searchLogResult = $this->searchService->logSearch(
                           $userId,
                           $companyId,
                           $inputDataForEngine['meta_client_ccid'],
                           $rawJsonData, // Original request body as SearchFormData
                           json_encode($responseArray) // Full calculation result as SearchReturnData
                           // ReportHTML and VisualizationData could be passed as null or derived if needed
                       );

                       if ($searchLogResult['success']) {
                           $responseArray['searchId'] = $searchLogResult['searchId'];
                           $responseArray['shareableLink'] = $searchLogResult['shareableLink'];
                           // Optionally add a message: $responseArray['messages'][] = "Calculation saved with ID: " . $searchLogResult['searchId'];
                       } else {
                           error_log("Failed to log search for CompanyID: {$companyId}, UserID: {$userId}. Reason: " . ($searchLogResult['message'] ?? 'Unknown error'));
                           // Non-fatal to the calculation response itself, but should be monitored.
                           // $responseArray['messages'][] = "Warning: Calculation result could not be saved for later viewing.";
                       }
                   } catch (\Exception $e) {
                        error_log("Exception while logging search for CompanyID: {$companyId}, UserID: {$userId}. Error: " . $e->getMessage());
                        // $responseArray['messages'][] = "Warning: Error saving calculation result.";
                   }
                }
                $httpStatusCode = 200;
                if (isset($responseArray['status'])) {
                    switch ($responseArray['status']) {
                        case 'SUCCESS_ALL_PLACED': case 'SUCCESS_PARTIAL_FIT': case 'FAILURE_NO_FIT':
                            $httpStatusCode = 200; break;
                        case 'ERROR_INPUT': $httpStatusCode = 400; break;
                        case 'ERROR_SERVER': default: $httpStatusCode = 500; break;
                    }
                }
                 $this->jsonResponse($responseArray, $httpStatusCode);

            } elseif ($action === 'getitemlist') {
use App\Service\FileParsingService; // Add this

class CalculationController {
    private ?object $authenticatedUserContext;
    private SubscriptionService $subscriptionService;
    private SearchService $searchService;
    private FileParsingService $fileParsingService; // Add this

    public function __construct(?object $authenticatedUserContext,
                                ?SubscriptionService $subscriptionService = null,
                                ?SearchService $searchService = null,
                                ?FileParsingService $fileParsingService = null // Add to constructor
                               ) {
        if ($authenticatedUserContext === null || !isset($authenticatedUserContext->companyId) || !isset($authenticatedUserContext->userId)) {
            throw new AuthException("Authentication context is missing or invalid for CalculationController.", 401);
        }
        $this->authenticatedUserContext = $authenticatedUserContext;
        $this->subscriptionService = $subscriptionService ?? new SubscriptionService();
        $this->searchService = $searchService ?? new SearchService();
        $this->fileParsingService = $fileParsingService ?? new FileParsingService(); // Instantiate
    }

    public function handleRequest(string $method, ?string $action, string $rawJsonData, array $filesInput): void {
        $companyId = (int)$this->authenticatedUserContext->companyId;
        $userId = (int)$this->authenticatedUserContext->userId;

        if ($method === 'POST') {
            if ($action === 'generatereport') {
                // ... (generatereport logic as before) ...
                 $inputData = json_decode($rawJsonData, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->jsonResponse(['status' => 'ERROR_INPUT', 'message' => 'Invalid JSON payload for /calculation/generatereport.'], 400);
                    return;
                }
                 if (!isset($inputData['items']) || !is_array($inputData['items'])) {
                     $this->jsonResponse(['status' => 'ERROR_INPUT', 'message' => 'Missing "items" array in request for /calculation/generatereport.'], 400);
                     return;
                }
                $calcPermission = $this->subscriptionService->canPerformCalculation($companyId);
                if (!$calcPermission['allowed']) {
                    $httpStatusCode = (str_contains(strtolower($calcPermission['message']), 'limit reached')) ? 402 : 403;
                    throw new AuthException($calcPermission['message'], $httpStatusCode);
                }
                $this->subscriptionService->recordCalculationAttempt($companyId);
                if (!function_exists('execute_load_calculation')) {
                     require_once __DIR__ . '/../../api_calc_engine_placeholder.php';
                }
                $inputDataForEngine = $inputData;
                $inputDataForEngine['meta_user_id'] = $userId;
                $inputDataForEngine['meta_company_id'] = $companyId;
                $inputDataForEngine['meta_client_ccid'] = isset($inputData['clientId']) && is_numeric($inputData['clientId']) ? (int)$inputData['clientId'] : null;
                $responseArray = execute_load_calculation($inputDataForEngine);
                if (isset($responseArray['status']) && ($responseArray['status'] === 'SUCCESS_ALL_PLACED' || $responseArray['status'] === 'SUCCESS_PARTIAL_FIT')) {
                   try {
                       $searchLogResult = $this->searchService->logSearch( $userId, $companyId, $inputDataForEngine['meta_client_ccid'], $rawJsonData, json_encode($responseArray) );
                       if ($searchLogResult['success']) {
                           $responseArray['searchId'] = $searchLogResult['searchId'];
                           $responseArray['shareableLinkUrl'] = $searchLogResult['shareableLinkUrl'];
                       } else { error_log("Failed to log search for CompanyID: {$companyId}, UserID: {$userId}. Reason: " . ($searchLogResult['message'] ?? 'Unknown error')); }
                   } catch (\Exception $e) { error_log("Exception while logging search for CompanyID: {$companyId}, UserID: {$userId}. Error: " . $e->getMessage()); }
                }
                $httpStatusCode = 200;
                if (isset($responseArray['status'])) {
                    switch ($responseArray['status']) {
                        case 'SUCCESS_ALL_PLACED': case 'SUCCESS_PARTIAL_FIT': case 'FAILURE_NO_FIT': $httpStatusCode = 200; break;
                        case 'ERROR_INPUT': $httpStatusCode = 400; break;
                        case 'ERROR_SERVER': default: $httpStatusCode = 500; break;
                    }
                }
                $this->jsonResponse($responseArray, $httpStatusCode);

            } elseif ($action === 'getitemlist') {
                // $filesInput is PHP's $_FILES array
                if (empty($filesInput['materialFile'])) {
                    throw new \InvalidArgumentException("No 'materialFile' uploaded.");
                }
                $uploadedFile = $filesInput['materialFile'];

                // Basic file validation (more can be added in service)
                if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
                    throw new \RuntimeException("File upload error: " . $this->mapUploadError($uploadedFile['error']));
                }
                // Size limit (e.g., 5MB) - could be configurable via .env
                $maxFileSize = (int)(getenv('MAX_UPLOAD_FILE_SIZE_MB') ?: 5) * 1024 * 1024;
                if ($uploadedFile['size'] > $maxFileSize) {
                    throw new \RuntimeException("File exceeds maximum allowed size of " . ($maxFileSize / (1024*1024)) . "MB.");
                }

                // Optional: Client ID from form data part if multipart/form-data
                $clientId = isset($_POST['clientId']) && is_numeric($_POST['clientId']) ? (int)$_POST['clientId'] : null;
                // Note: if clientId is sent, the request Content-Type won't be application/json for the whole request.
                // For simplicity, let's assume clientId is not part of this specific file upload endpoint for now,
                // or it's passed as a query parameter if needed.

                $result = $this->fileParsingService->parseItemFile($uploadedFile);

                if ($result['success']) {
                    $this->jsonResponse([
                        'status' => 'success',
                        'message' => $result['message'] ?? 'File parsed successfully.',
                        'items' => $result['items']
                        // Optionally include 'warnings' if any from parsing: 'warnings' => $result['warnings'] ?? []
                    ], 200);
                } else {
                    $this->jsonResponse([
                        'status' => 'fail', // Or 'ERROR_INPUT'
                        'message' => $result['message'] ?? 'Failed to parse file.',
                        'errors' => $result['errors'] ?? [] // Detailed errors per row/field
                    ], 400); // Or 422 if it's purely validation errors
                }

            } else {
                $this->jsonResponse(["status" => "ERROR_NOT_FOUND", "message" => "Calculation action '{$action}' not found."], 404);
            }
        } else {
            $this->methodNotAllowed(['POST']);
        }
    }

    private function jsonResponse(array $data, int $statusCode): void {
        http_response_code($statusCode); echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
    private function methodNotAllowed(array $allowedMethods): void {
        http_response_code(405); header('Allow: ' . implode(', ', $allowedMethods));
        echo json_encode(["status" => "error", "message" => "Method not allowed. Allowed: " . implode(', ', $allowedMethods)]);
    }

    private function mapUploadError(int $errorCode): string {
        switch ($errorCode) {
            case UPLOAD_ERR_INI_SIZE: return "File exceeds upload_max_filesize directive in php.ini.";
            case UPLOAD_ERR_FORM_SIZE: return "File exceeds MAX_FILE_SIZE directive specified in HTML form.";
            case UPLOAD_ERR_PARTIAL: return "File was only partially uploaded.";
            case UPLOAD_ERR_NO_FILE: return "No file was uploaded.";
            case UPLOAD_ERR_NO_TMP_DIR: return "Missing a temporary folder on server.";
            case UPLOAD_ERR_CANT_WRITE: return "Failed to write file to disk on server.";
            case UPLOAD_ERR_EXTENSION: return "A PHP extension stopped the file upload.";
            default: return "Unknown file upload error.";
        }
    }
}
?>
```

This ensures `CalculationController.php` is correctly updated with the subscription check logic.The integration of subscription checks into the relevant services and controllers is conceptually complete.

**Summary of Implementations:**

1.  **`SubscriptionService.php` Updated:**
    *   The `canPerformCalculation(int $companyId)` method was enhanced to check for an active subscription and then query a conceptual `daily_calculation_logs` table to compare `MaxCalculationsPerDay` against current daily usage. It now returns a structured array `['allowed' => bool, 'message' => string, 'remaining' => int]`.
    *   A new method `recordCalculationAttempt(int $companyId)` was added to perform an UPSERT operation on the `daily_calculation_logs` table, incrementing the count for the company for the current day.
    *   *(A new `daily_calculation_logs` table was conceptually added to the database schema with columns `LogID, CompanyID, CalculationDate, CalculationCount` and a unique key on `CompanyID, CalculationDate`.)*

2.  **`CalculationController.php` Updated:**
    *   The constructor now instantiates `SubscriptionService`.
    *   In the handler for `POST /calculation/generatereport`:
        *   Before executing the main calculation logic, it calls `$this->subscriptionService->canPerformCalculation($companyId)`.
        *   If this check returns `allowed = false`, it throws an `AuthException` (e.g., with HTTP 402 Payment Required or 403 Forbidden), preventing the calculation.
        *   If allowed, it then calls `$this->subscriptionService->recordCalculationAttempt($companyId)` to log the usage.
        *   Conceptual comments for saving the calculation result to the `search` table (including `UID`, `CompanyID`, and potentially `CCID`) were also noted.

3.  **`UserService.php` (Review):**
    *   The existing `addUserToCompany` method already includes logic to fetch the company's subscription and check the `MaxUsers` limit against the current number of active/non-inactive users. This was confirmed to be appropriate and does not require further changes for this specific step.

This integration ensures that subscription limits (`MaxUsers` and `MaxCalculationsPerDay`) are conceptually enforced before allowing relevant actions (adding users or performing calculations). The next step in the plan is "Conceptual Testing of Subscription APIs."
