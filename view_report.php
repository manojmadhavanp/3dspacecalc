<?php
require_once 'config.php'; // For BASE_URL, APP_NAME, DB constants
require_once 'db_connect.php'; // For $conn

$report_id_from_url = $_GET['id'] ?? null;
$token_from_url = $_GET['token'] ?? null;

$pageTitle = "View Calculation Report";
$report_content = '';
$visualization_content = '';
$error_message = '';

if (!$report_id_from_url || !$token_from_url) {
    $error_message = "Report ID or token is missing from the link.";
} else {
    $search_id = (int)$report_id_from_url; // Assuming SearchID is integer

    $stmt = $conn->prepare("SELECT ReportHTML, VisualizationData, ShareableLink FROM search WHERE SearchID = ?");
    if ($stmt) {
        $stmt->bind_param("i", $search_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            // Validate the token from URL against the token in the stored ShareableLink
            // This is a simplified validation. A better way is to store the token separately.
            $stored_shareable_link = $row['ShareableLink'];
            parse_str(parse_url($stored_shareable_link, PHP_URL_QUERY), $query_params);
            $stored_token = $query_params['token'] ?? null;

            if ($stored_token === $token_from_url) {
                $report_content = $row['ReportHTML'];
                $visualization_content = "<p><em>No detailed visualization data available or data is not in the expected format.</em></p>"; // Default

                if (!empty($row['VisualizationData'])) {
                    $viz_data_json = json_decode($row['VisualizationData'], true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($viz_data_json)) {
                        $temp_viz_html = "<h4>Overall Request: " . htmlspecialchars($viz_data_json['requestName'] ?? 'N/A') . " (Status: " . htmlspecialchars($viz_data_json['status'] ?? 'N/A') . ")</h4>";

                        if (isset($viz_data_json['summary'])) {
                            $summary = $viz_data_json['summary'];
                            $temp_viz_html .= "<p><strong>Summary:</strong> Placed " . htmlspecialchars($summary['totalItemsPlaced'] ?? 0) . "/" . htmlspecialchars($summary['totalItemsToPlace'] ?? 0) . " items. ";
                            $temp_viz_html .= "Weight: " . htmlspecialchars($summary['totalWeightPlaced'] ?? 0) . " kg. ";
                            $temp_viz_html .= "Volume: " . htmlspecialchars($summary['totalVolumePlaced'] ?? 0) . " cm³.</p>";
                        }

                        if (isset($viz_data_json['containers']) && is_array($viz_data_json['containers'])) {
                            foreach($viz_data_json['containers'] as $index => $container) {
                                $temp_viz_html .= "<div style='border:1px solid #eee; padding:10px; margin-top:10px; border-radius:5px;'>";
                                $temp_viz_html .= "<h5>Container " . ($index + 1) . ": " . htmlspecialchars($container['containerName'] ?? $container['containerKey'] ?? 'Unknown Container') . "</h5>";
                                if (isset($container['containerDimensions'])) {
                                    $cd = $container['containerDimensions'];
                                    $temp_viz_html .= "<p style='font-size:0.9em;'>Dimensions (cm): W " . htmlspecialchars($cd['width'] ?? 'N/A') . " x L " . htmlspecialchars($cd['length'] ?? 'N/A') . " x H " . htmlspecialchars($cd['height'] ?? 'N/A') . "<br>";
                                    $temp_viz_html .= "Usable Vol: " . htmlspecialchars($cd['usableVolume'] ?? 'N/A') . " cm³, Usable Payload: " . htmlspecialchars($cd['usablePayload'] ?? 'N/A') . " kg. Floor: ". htmlspecialchars($container['floorType'] ?? 'N/A') ."</p>";
                                }
                                if (isset($container['loadSummary'])) {
                                    $ls = $container['loadSummary'];
                                    $temp_viz_html .= "<p style='font-size:0.9em;'>Load: " . htmlspecialchars($ls['itemCount'] ?? 0) . " items, Weight " . htmlspecialchars($ls['totalWeight'] ?? 0) . " kg, Volume " . htmlspecialchars($ls['totalVolume'] ?? 0) . " cm³.<br>";
                                    $temp_viz_html .= "Utilization: " . htmlspecialchars($ls['volumeUtilizationPercent'] ?? 0) . "% vol, " . htmlspecialchars($ls['payloadUtilizationPercent'] ?? 0) . "% payload.</p>";
                                }

                                if (isset($container['placedItems']) && is_array($container['placedItems']) && count($container['placedItems']) > 0) {
                                    $temp_viz_html .= "<h6>Placed Items:</h6><ul style='font-size:0.85em; max-height:200px; overflow-y:auto;'>";
                                    foreach($container['placedItems'] as $pItem) {
                                        $temp_viz_html .= "<li><strong>" . htmlspecialchars($pItem['itemName'] ?? 'Item') . "</strong> (Type: ".htmlspecialchars($pItem['type'] ?? 'N/A').")";
                                        $dims = $pItem['originalDimensions'] ?? [];
                                        $place = $pItem['placement'] ?? [];
                                        $temp_viz_html .= "<br>&nbsp;&nbsp;Original Dim (W L H): " . htmlspecialchars($dims['width'] ?? '?')." x ".htmlspecialchars($dims['length'] ?? '?')." x ".htmlspecialchars($dims['height'] ?? '?');
                                        $temp_viz_html .= "<br>&nbsp;&nbsp;Placement (X Y Z): " . htmlspecialchars($place['x'] ?? '?').", ".htmlspecialchars($place['y'] ?? '?').", ".htmlspecialchars($place['z'] ?? '?');
                                        $temp_viz_html .= "<br>&nbsp;&nbsp;Oriented Dim (W L H): " . htmlspecialchars($place['orientedWidth'] ?? '?')." x ".htmlspecialchars($place['orientedLength'] ?? '?')." x ".htmlspecialchars($place['orientedHeight'] ?? '?');
                                        $temp_viz_html .= "</li>";
                                    }
                                    $temp_viz_html .= "</ul>";
                                } else {
                                    $temp_viz_html .= "<p><em>No items placed in this container according to visualization data.</em></p>";
                                }
                                $temp_viz_html .= "</div>"; // close container div
                            }
                        }
                         if (isset($viz_data_json['unplacedItems']) && is_array($viz_data_json['unplacedItems']) && count($viz_data_json['unplacedItems']) > 0) {
                            $temp_viz_html .= "<h4>Unplaced Items:</h4><ul>";
                            foreach($viz_data_json['unplacedItems'] as $uItem){
                                $temp_viz_html .= "<li>" . htmlspecialchars($uItem['itemName'] ?? ($uItem['sku'] ?? 'Unknown Item')) . " - Qty: " . htmlspecialchars($uItem['quantity'] ?? 1) . "</li>";
                            }
                            $temp_viz_html .= "</ul>";
                        }
                        $visualization_content = $temp_viz_html;
                    } else {
                         // Keep default message if JSON structure is not as expected or not decodable
                         $visualization_content = "<p><em>Visualization data is present but not in the expected detailed format.</em></p><pre style='background:#f0f0f0; padding:10px; border-radius:4px; font-size:0.8em; white-space:pre-wrap; word-break:break-all;'>" . htmlspecialchars($row['VisualizationData']) . "</pre>";
                    }
                } else {
                    $visualization_content = "<p><em>No visualization data available in database record.</em></p>";
                }

                if (empty($report_content)) {
                    $report_content = "<p><em>Report data is empty or not found.</em></p>";
                }

            } else {
                $error_message = "Invalid or expired report link (token mismatch).";
                error_log("Token mismatch for SearchID {$search_id}. URL Token: {$token_from_url}, Stored Token: {$stored_token}");
            }
        } else {
            $error_message = "Report not found.";
        }
        $stmt->close();
    } else {
        $error_message = "Database error while fetching report: " . $conn->error;
        error_log("DB error in view_report.php: " . $conn->error);
    }
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle) . (isset($search_id) ? " - ID: " . htmlspecialchars($search_id) : ""); ?> - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="css/style.css"> <!-- Assuming a global style.css -->
    <style>
        body { background-color: #f4f8fc; }
        .report-container {
            max-width: 900px;
            margin: 20px auto;
            padding: 25px;
            background-color: #fff;
            border: 1px solid #e0e0e0;
            box-shadow: 0 0 15px rgba(0,0,0,0.05);
            border-radius: 8px;
        }
        .report-header { text-align: center; margin-bottom: 25px; padding-bottom:15px; border-bottom:1px solid #eee; }
        .report-header h1 { color: #333; font-size: 1.8rem; }
        .report-section { margin-bottom: 30px; }
        .report-section h3 {
            font-size: 1.4rem;
            color: #007bff;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid #007bff;
            display: inline-block;
        }
        .error-message-box {
            border: 1px solid #dc3545;
            color: #721c24;
            background-color: #f8d7da;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            text-align: center;
        }
        .visualization-placeholder {
            min-height: 200px;
            background-color: #f0f2f5;
            border: 1px dashed #ccc;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 20px;
            border-radius: 5px;
        }
        .report-footer { text-align: center; margin-top: 30px; font-size: 0.9em; color: #777; padding-top:15px; border-top:1px solid #eee;}
    </style>
</head>
<body>
    <div class="report-container">
        <div class="report-header">
            <h1><?php echo APP_NAME; ?> - Calculation Report</h1>
        </div>

        <?php if ($error_message): ?>
            <div class="error-message-box">
                <p><?php echo htmlspecialchars($error_message); ?></p>
                <p><a href="<?php echo rtrim(BASE_URL, '/'); ?>/index.php">Return to Homepage</a></p>
            </div>
        <?php else: ?>
            <div class="report-section" id="report-details">
                <h3>Report Details</h3>
                <?php echo $report_content; // This is already HTML, ensure it's safe if generated from user input elsewhere ?>
            </div>

            <div class="report-section" id="visualization">
                <h3>3D Visualization</h3>
                <div class="visualization-placeholder">
                    <?php echo $visualization_content; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="report-footer">
            <p>&copy; <?php echo date("Y"); ?> <?php echo APP_NAME; ?>. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
