<?php
// This auth_check.php will set header to application/json and perform session auth.
// It also provides $api_user_uid and $api_user_uuid.
require_once 'auth_check.php';
require_once __DIR__ . '/../db_connect.php'; // For $conn
require_once __DIR__ . '/../config.php'; // For APP_NAME or other configs if needed

// This API endpoint is expected to handle file uploads.
// For now, due to the complexity of robust file parsing (CSV, XLS) without dedicated libraries
// and the previous simulation on the frontend, this API will return a mock list of items.
// In a real application, this endpoint would:
// 1. Validate the uploaded file (type, size, content).
// 2. Parse the file (e.g., using libraries like PhpSpreadsheet for XLS/XLSX or str_getcsv for CSV).
// 3. Extract item data based on expected columns.
// 4. Potentially perform some initial validation or lookup on the extracted items.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'error' => 'Only POST method is allowed.']);
    exit;
}

// --- Simulate file processing ---
$fileName = $_FILES['materialFile']['name'] ?? 'unknown_file';
$fileSize = $_FILES['materialFile']['size'] ?? 0;
$fileType = $_FILES['materialFile']['type'] ?? 'unknown_type';

// Basic check for file upload (very simplified)
if (empty($_FILES['materialFile']) || $_FILES['materialFile']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400); // Bad Request
    $upload_error_message = 'No file uploaded or an error occurred during upload.';
    if(isset($_FILES['materialFile']['error'])) {
        // Provide more specific common error messages
        switch ($_FILES['materialFile']['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $upload_error_message = "File is too large.";
                break;
            case UPLOAD_ERR_NO_FILE:
                $upload_error_message = "No file was sent.";
                break;
            default:
                $upload_error_message = "Unknown upload error.";
        }
    }
    echo json_encode(['success' => false, 'error' => $upload_error_message, 'details' => $_FILES['materialFile']['error'] ?? null]);
    exit;
}


// Simulate item extraction based on filename or type for variety
$mock_items = [];
if (stripos($fileName, 'complex') !== false || $fileSize > 5000) { // Simulate a larger/complex file
    $mock_items = [
        ['id' => 'item-A1', 'sku' => 'SKU-COMPLEX-001', 'name' => 'Heavy Machinery Part', 'quantity' => 2, 'length_cm' => 120, 'width_cm' => 80, 'height_cm' => 60, 'weight_kg' => 250, 'stackable' => false, 'fragile' => false],
        ['id' => 'item-A2', 'sku' => 'SKU-COMPLEX-002', 'name' => 'Industrial Pipes Pack', 'quantity' => 5, 'length_cm' => 300, 'diameter_cm' => 15, 'weight_kg' => 75, 'stackable' => true, 'fragile' => false],
        ['id' => 'item-A3', 'sku' => 'SKU-COMPLEX-003', 'name' => 'Sensitive Electronics Crate', 'quantity' => 1, 'length_cm' => 50, 'width_cm' => 50, 'height_cm' => 40, 'weight_kg' => 22, 'stackable' => false, 'fragile' => true, 'orientation_required' => 'upright'],
    ];
} elseif (stripos($fileType, 'csv') !== false || stripos($fileName, '.csv') !== false) {
     $mock_items = [
        ['id' => 'csv-item-1', 'sku' => 'CSV001', 'description' => 'Standard Boxes', 'qty' => 20, 'l' => 30, 'w' => 20, 'h' => 15, 'unit_weight' => 2],
        ['id' => 'csv-item-2', 'sku' => 'CSV002', 'description' => 'Long Tubes', 'qty' => 10, 'l' => 150, 'w' => 10, 'h' => 10, 'unit_weight' => 3],
        ['id' => 'csv-item-3', 'sku' => 'CSV003', 'description' => 'Flat Panels', 'qty' => 15, 'l' => 100, 'w' => 80, 'h' => 5, 'unit_weight' => 5],
    ];
    // The frontend mapping table expects specific keys like 'name', 'quantity', 'length', 'width', 'height', 'weight'.
    // This mock data for CSV has different keys ('description', 'qty', 'l', 'w', 'h', 'unit_weight').
    // This is intentional to show that the frontend mapping step would be necessary if the API returns data like this.
    // For a smoother direct use without mapping, the API should return the keys the frontend expects.
} else { // Default mock items
    $mock_items = [
        ['id' => 'item-S1', 'sku' => 'SKU-STD-001', 'name' => 'Standard Product A', 'quantity' => 10, 'length' => 40, 'width' => 30, 'height' => 20, 'weight' => 5.5],
        ['id' => 'item-S2', 'sku' => 'SKU-STD-002', 'name' => 'Standard Product B', 'quantity' => 15, 'length' => 25, 'width' => 20, 'height' => 15, 'weight' => 2.1],
        ['id' => 'item-S3', 'sku' => 'SKU-STD-003', 'name' => 'Small Component Pack', 'quantity' => 50, 'length' => 10, 'width' => 8, 'height' => 5, 'weight' => 0.5],
    ];
}

// Simulate a short processing delay
usleep(500000); // 0.5 seconds

if (empty($mock_items)) {
     echo json_encode(['success' => false, 'error' => 'No items could be extracted from the uploaded file (simulated).', 'file_info' => ['name' => $fileName, 'size' => $fileSize, 'type' => $fileType]]);
} else {
    echo json_encode(['success' => true, 'items' => $mock_items, 'message' => "File '{$fileName}' processed (simulated). Found " . count($mock_items) . " item types.", 'file_info' => ['name' => $fileName, 'size' => $fileSize, 'type' => $fileType]]);
}

$conn->close();
exit;
?>
