<?php
// This auth_check.php will set header to application/json and perform session auth.
// It also provides $api_user_uid, $api_user_uuid, and functions getApiUserCompanyID, checkApiUsageLimits
require_once 'auth_check.php';
require_once __DIR__ . '/../db_connect.php'; // For $conn
require_once __DIR__ . '/../config.php';     // For BASE_URL

// --- Get Company ID for the current user ---
$companyID = getApiUserCompanyID($conn, $api_user_uid);
if (!$companyID) {
    http_response_code(403); // Forbidden
    echo json_encode(['success' => false, 'error' => 'Could not associate your user with a company.']);
    exit;
}

// --- Check Usage Limits ---
$usageCheck = checkApiUsageLimits($conn, $companyID);
if (!$usageCheck['allowed']) {
    http_response_code(429); // Too Many Requests (or 403 Forbidden)
    echo json_encode(['success' => false, 'error' => $usageCheck['message']]);
    exit;
}

// --- Process Input ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'error' => 'Only POST method is allowed.']);
    exit;
}

$json_payload = file_get_contents('php://input');
$data = json_decode($json_payload, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    http_response_code(400); // Bad Request
    echo json_encode(['success' => false, 'error' => 'Invalid JSON payload. ' . json_last_error_msg()]);
    exit;
}

$client_ccid = $data['clientId'] ?? null; // This is CCID from `client` table
$container_type = $data['containerType'] ?? '20ft_GP'; // Default container type
$items = $data['items'] ?? [];
// Potentially other global settings: $data['settings'] ...

if (empty($items) || !is_array($items)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Item list is empty or invalid.']);
    exit;
}

// --- Simulate Calculation Logic & Report Generation ---
// In a real application, this is where the core calculation engine would be called.
// It would take $items, $container_type, etc., and perform complex packing algorithms.

usleep(1500000); // Simulate 1.5 seconds of processing

$report_id = "CALC-" . strtoupper(bin2hex(random_bytes(6))); // More unique ID
$shareable_token = bin2hex(random_bytes(16));
$shareable_link = rtrim(BASE_URL, '/') . '/view_report.php?id=' . $report_id . '&token=' . $shareable_token;

// Simulated HTML Report
$simulated_html_report = "<h2>Calculation Report: {$report_id}</h2>";
$simulated_html_report .= "<p><strong>Container Type:</strong> " . htmlspecialchars($container_type) . "</p>";
if ($client_ccid) {
    // Fetch client name for report (optional)
    $clientName = "Client CCID: " . htmlspecialchars($client_ccid); // Fallback
    $stmt_client = $conn->prepare("SELECT CompanyName FROM client WHERE CCID = ? AND AddedByCompanyID = ?");
    if($stmt_client){
        $stmt_client->bind_param("ii", $client_ccid, $companyID);
        $stmt_client->execute();
        $client_res = $stmt_client->get_result();
        if($client_row = $client_res->fetch_assoc()){
            $clientName = htmlspecialchars($client_row['CompanyName']);
        }
        $stmt_client->close();
    }
    $simulated_html_report .= "<p><strong>Client:</strong> " . $clientName . "</p>";
}
$simulated_html_report .= "<h3>Items Processed:</h3><ul>";
$total_quantity = 0;
foreach ($items as $item) {
    $itemName = htmlspecialchars($item['name'] ?? ($item['sku'] ?? 'Unknown Item'));
    $itemQty = (int)($item['quantity'] ?? 0);
    $total_quantity += $itemQty;
    $simulated_html_report .= "<li>" . $itemName . " - Quantity: " . $itemQty . "</li>";
}
$simulated_html_report .= "</ul>";
$simulated_html_report .= "<p><strong>Total quantity of all items:</strong> {$total_quantity}</p>";
$simulated_html_report .= "<p><em>This is a simulated report. Actual report would contain detailed stuffing plans, efficiency metrics, etc.</em></p>";
$simulated_html_report .= "<p>Generated on: " . date('Y-m-d H:i:s') . "</p>";


// Simulated Visualization Data (matching user-provided structure)
$simulated_visualization_data = [
    "requestName" => "Simulated Request - " . $report_id,
    "status" => "success",
    "summary" => [
        "totalItemsToPlace" => count($items), // Simplified
        "totalItemsPlaced" => count($items),  // Simplified
        "totalWeightPlaced" => array_sum(array_column($items, 'weight')) ?: 0, // Simplified
        "totalVolumePlaced" => 0, // Placeholder, real calculation needed
    ],
    "containers" => [
        [
            "containerKey" => $container_type, // e.g., "20ftGPWood"
            "containerName" => $container_type, // e.g., "20ft GP"
            "containerDimensions" => [ // Example dimensions, should match container_type
                "width" => 233.7, "length" => 594.4, "height" => 238.8, // cm
                "usableVolume" => 31513196.9, "usablePayload" => 26790 // cm^3, kg
            ],
            "floorType" => "Wooden", // Example
            "loadSummary" => [
                "itemCount" => count($items),
                "totalWeight" => array_sum(array_column($items, 'weight')) ?: 0,
                "totalVolume" => 0, // Placeholder
                "volumeUtilizationPercent" => 0, // Placeholder
                "payloadUtilizationPercent" => 0, // Placeholder
            ],
            "placedItems" => [], // Will be populated below
            "remainingEmptyBoxAreas" => [] // Placeholder
        ]
    ],
    "unplacedItems" => [] // Placeholder
];

// Populate placedItems with a few items from the input for simulation
$item_count_to_visualize = min(count($items), 5); // Visualize up to 5 items
for ($i = 0; $i < $item_count_to_visualize; $i++) {
    $currentItem = $items[$i];
    $simulated_visualization_data["containers"][0]["placedItems"][] = [
        "itemName" => $currentItem['name'] ?? ($currentItem['sku'] ?? "Item " . ($i+1)),
        "originalQtyIndex" => $i + 1,
        "type" => $currentItem['type'] ?? "box", // Assuming a 'type' field or default to 'box'
        "originalDimensions" => [
            "width" => $currentItem['width'] ?? 0,
            "length" => $currentItem['length'] ?? 0,
            "height" => $currentItem['height'] ?? 0
        ],
        "weight" => $currentItem['weight'] ?? 0,
        "placement" => [ // Simulate some basic placement
            "x" => $i * (($currentItem['width'] ?? 30) + 5), // Simple stacking along X
            "y" => 0,
            "z" => 0,
            "orientedWidth" => $currentItem['width'] ?? 0,    // Assuming no rotation for simulation
            "orientedLength" => $currentItem['length'] ?? 0,
            "orientedHeight" => $currentItem['height'] ?? 0,
            // Simulate layer based on Z. If Z is 0, layer 1. If Z > 0 but less than some threshold, layer 2 etc.
            // This is a very basic layer simulation. A real engine would determine exact layers.
            "layer" => ($currentItem['height'] ?? 0) > 0 ? floor(($i * (($currentItem['height'] ?? 30))) / (($currentItem['height'] ?? 30) * 2)) + 1 : 1
            // Example: if items are roughly same height, this puts 2 items per layer for first few.
            // A more robust simulation: intval(round($placement_z / $typical_item_height)) + 1
        ],
        "color" => sprintf('#%02X%02X%02X', rand(100, 240), rand(100, 240), rand(100, 240)) // Random light-ish color
    ];
    // Accumulate volume for summary (very simplified, assumes cuboids and no rotation)
    $itemVolume = ($currentItem['width'] ?? 0) * ($currentItem['length'] ?? 0) * ($currentItem['height'] ?? 0);
    $simulated_visualization_data["summary"]["totalVolumePlaced"] += $itemVolume * ($currentItem['quantity'] ?? 1);
    $simulated_visualization_data["containers"][0]["loadSummary"]["totalVolume"] += $itemVolume * ($currentItem['quantity'] ?? 1);
}

if ($simulated_visualization_data["containers"][0]["containerDimensions"]["usableVolume"] > 0) {
    $volUtil = ($simulated_visualization_data["containers"][0]["loadSummary"]["totalVolume"] / $simulated_visualization_data["containers"][0]["containerDimensions"]["usableVolume"]) * 100;
    $simulated_visualization_data["containers"][0]["loadSummary"]["volumeUtilizationPercent"] = round($volUtil, 2);
}
if ($simulated_visualization_data["containers"][0]["containerDimensions"]["usablePayload"] > 0 && isset($simulated_visualization_data["containers"][0]["loadSummary"]["totalWeight"])) {
    $payloadUtil = ($simulated_visualization_data["containers"][0]["loadSummary"]["totalWeight"] / $simulated_visualization_data["containers"][0]["containerDimensions"]["usablePayload"]) * 100;
    $simulated_visualization_data["containers"][0]["loadSummary"]["payloadUtilizationPercent"] = round($payloadUtil, 2);
}


// --- Store Search/Calculation Data in Database ---
$search_form_data_json = json_encode($data); // The original input payload
$search_return_data_json = json_encode([ // The data this API is about to return
    'reportId' => $report_id,
    'htmlReport' => $simulated_html_report, // Storing the generated HTML
    'visualizationData' => $simulated_visualization_data, // Storing the viz data
    'shareableLink' => $shareable_link,
    'status' => 'success'
]);

$conn->begin_transaction();
try {
    $stmt_insert_search = $conn->prepare(
        "INSERT INTO search (SearchID, UID, CompanyID, CCID, SearchFormData, SearchReturnData, ReportHTML, VisualizationData, ShareableLink, SearchDateTime)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)"
    );
    if (!$stmt_insert_search) {
        throw new Exception("DB Error (prepare insert search): " . $conn->error);
    }
    // Note: SearchID is $report_id, ShareableLink contains $report_id and $shareable_token
    // The `search` table has `SearchID` as INT AUTO_INCREMENT. We should use that.
    // Let's adjust to use auto-incremented SearchID and build ShareableLink from that.
// The `search` table has `SearchID` as INT AUTO_INCREMENT.
// We will insert the main data, get the last_insert_id, then build the shareable link.
// The $report_id (CALC-xxxx) can be a user-facing reference, stored in SearchReturnData or a new column if needed.
// For now, $shareable_link will use the numeric auto-incremented SearchID.

    $db_client_ccid = $client_ccid ? (int)$client_ccid : null;

    // Null initially for ShareableLink, will be updated after insert.
    // ReportHTML and VisualizationData are part of SearchReturnData, so storing them separately is redundant.
    // Let's simplify the insert.
    $stmt_insert_search = $conn->prepare(
        "INSERT INTO search (UID, CompanyID, CCID, SearchFormData, SearchReturnData, SearchDateTime)
         VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)"
    );
     if (!$stmt_insert_search) {
        throw new Exception("DB Error (prepare insert search): " . $conn->error);
    }
    $stmt_insert_search->bind_param("iiiss",
        $api_user_uid,
        $companyID,
        $db_client_ccid,
        $search_form_data_json,
        $search_return_data_json // This now contains the report, viz data, and original $report_id (CALC-xxxx)
    );

    if (!$stmt_insert_search->execute()) {
        throw new Exception("DB Error (execute insert search): " . $stmt_insert_search->error);
    }
    $inserted_search_id = $stmt_insert_search->insert_id; // Get the auto-incremented SearchID
    $stmt_insert_search->close();

    // Now update the shareable link for this new SearchID
    $final_shareable_link = rtrim(BASE_URL, '/') . '/view_report.php?id=' . $inserted_search_id . '&token=' . $shareable_token;
    $stmt_update_link = $conn->prepare("UPDATE search SET ShareableLink = ?, ReportHTML = ?, VisualizationData = ? WHERE SearchID = ?");
    if(!$stmt_update_link) throw new Exception("DB Error (prepare update shareable_link): " . $conn->error);

    // We store ReportHTML and VisualizationData separately for direct access if needed,
    // even if they are also inside SearchReturnData.
    $stmt_update_link->bind_param("sssi", $final_shareable_link, $simulated_html_report, json_encode($simulated_visualization_data), $inserted_search_id);
    if(!$stmt_update_link->execute()){
         throw new Exception("DB Error (execute update shareable_link): " . $stmt_update_link->error);
    }
    $stmt_update_link->close();

    $conn->commit();

    // --- Output JSON Response ---
    // The $report_id (CALC-xxxx) is part of $search_return_data_json now.
    // The primary ID for DB is $inserted_search_id.
    echo json_encode([
        'success' => true,
        'reportId' => $report_id, // This is the CALC-xxxx string ID
        'searchDbId' => $inserted_search_id, // This is the actual DB primary key
        'htmlReport' => $simulated_html_report,
        'visualizationData' => $simulated_visualization_data,
        'shareableLink' => $shareable_link,
        'message' => 'Report generated successfully (simulated).'
    ]);

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500); // Internal Server Error
    error_log("API generateReport Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'An internal error occurred while saving the calculation. ' . $e->getMessage()]);
}


$conn->close();
exit;
?>
