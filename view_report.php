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
                <h3>Visualization</h3>

                <h4>2D Layered View (Top-Down)</h4>
                <div id="report-2d-layers-view" style="margin-bottom: 20px; padding:10px; background-color:#f8f9fa; border:1px solid #dee2e6; border-radius:5px;">
                    <!-- Canvases for 2D layers will be appended here by JavaScript -->
                     <p id="layers-view-placeholder"><em>Loading 2D layer views... If this message persists, visualization data might be missing required fields (layers, item placements).</em></p>
                </div>

                <h4>3D Model View (Placeholder)</h4>
                <div class="visualization-placeholder" id="report-3d-view-placeholder">
                    <p><em>3D model rendering will appear here. The data below is what would be used.</em></p>
                    <?php echo $visualization_content; // This shows the structured JSON data ?>
                </div>
            </div>
        <?php endif; ?>

        <script>
            function renderContainer2DLayers(containerDivId, vizDataContainer) {
                const containerDiv = document.getElementById(containerDivId);
                if (!containerDiv) {
                    console.error("2D Layers container DIV not found:", containerDivId);
                    return;
                }
                containerDiv.innerHTML = ''; // Clear placeholder or previous content

                const dims = vizDataContainer.containerDimensions;
                if (!dims || !dims.width || !dims.length) {
                    containerDiv.innerHTML = "<p><em>Container dimensions (width/length) missing for 2D view.</em></p>";
                    console.error("Container dimensions (width/length) missing:", dims);
                    return;
                }

                const itemsToDraw = vizDataContainer.placedItems;
                if (!itemsToDraw || itemsToDraw.length === 0) {
                    containerDiv.innerHTML = "<p><em>No placed items to render in 2D view.</em></p>";
                    return;
                }

                const scale = 1.5; // Adjust scale as needed for display size
                const layerMap = new Map();

                for (const item of itemsToDraw) {
                    const layer = item.placement?.layer ?? 0; // Default to layer 0 if not specified
                    if (!layerMap.has(layer)) layerMap.set(layer, []);
                    layerMap.get(layer).push(item);
                }

                if (layerMap.size === 0 && itemsToDraw.length > 0) { // If items exist but no layer info
                     containerDiv.innerHTML = "<p><em>Items found, but no layer information available for 2D view. Drawing all on one layer.</em></p>";
                     layerMap.set(0, itemsToDraw); // Draw all on a default layer 0
                } else if (layerMap.size === 0) {
                    containerDiv.innerHTML = "<p><em>No layers to display.</em></p>";
                    return;
                }


                const sortedLayers = Array.from(layerMap.entries()).sort((a, b) => a[0] - b[0]);

                sortedLayers.forEach(([layer, items]) => {
                    const label = document.createElement("div");
                    label.innerHTML = `<strong>Layer ${layer}</strong> (Top-Down View)`;
                    label.style.marginTop = "10px";
                    containerDiv.appendChild(label);

                    const canvas = document.createElement("canvas");
                    // Canvas width corresponds to container length, canvas height to container width for typical top-down
                    // Or, if x is width and y is length on floor:
                    canvas.width = dims.length * scale; // Canvas X-axis = Container Length
                    canvas.height = dims.width * scale;  // Canvas Y-axis = Container Width
                    // This assumes containerDimensions.length is along the X-axis of the canvas,
                    // and containerDimensions.width is along the Y-axis of the canvas.
                    // The item's placement.x and placement.y should correspond to this.

                    canvas.style.border = "1px solid #999";
                    canvas.style.margin = "10px 0";

                    const ctx = canvas.getContext("2d");

                    ctx.fillStyle = "#e9ecef"; // Light grey background for canvas
                    ctx.fillRect(0, 0, canvas.width, canvas.height);

                    ctx.strokeStyle = "#343a40"; // Darker border for container
                    ctx.lineWidth = 2;
                    ctx.strokeRect(0, 0, canvas.width, canvas.height);

                    items.forEach(item => {
                        // For top-down view, item.placement.x and item.placement.y are on the floor.
                        // item.placement.orientedLength is along the container's length axis (canvas X)
                        // item.placement.orientedWidth is along the container's width axis (canvas Y)
                        const x = (item.placement?.x ?? 0) * scale; // Position along container length
                        const y = (item.placement?.y ?? 0) * scale; // Position along container width
                        const l = (item.placement?.orientedLength ?? item.originalDimensions?.length ?? 0) * scale; // Item's length on canvas X
                        const w = (item.placement?.orientedWidth ?? item.originalDimensions?.width ?? 0) * scale;   // Item's width on canvas Y

                        ctx.fillStyle = item.color || "#adb5bd"; // Default item color: grey
                        ctx.fillRect(x, y, l, w); // Draw item: x, y, length, width

                        ctx.strokeStyle = "#212529"; // Darker border for items
                        ctx.lineWidth = 1;
                        ctx.strokeRect(x, y, l, w);

                        ctx.fillStyle = "#000";
                        ctx.font = Math.max(10, 8 * scale * 0.2) + "px Arial"; // Adjust font size with scale
                        ctx.textAlign = "center";
                        ctx.textBaseline = "middle";
                        const itemName = item.itemName || item.type || 'Item';
                        // Truncate text if too long for the box
                        const maxTextWidth = l - 4; // Max width for text inside box
                        let displayText = itemName;
                        if (ctx.measureText(displayText).width > maxTextWidth && l > 15) { // only truncate if box is not too small
                           while(ctx.measureText(displayText + "...").width > maxTextWidth && displayText.length > 0){
                               displayText = displayText.substring(0, displayText.length -1);
                           }
                           displayText += "...";
                        }
                        if(l > 15 && w > 10) { // Only draw text if box is reasonably sized
                           ctx.fillText(displayText, x + l / 2, y + w / 2);
                        }
                    });
                    containerDiv.appendChild(canvas);
                });
            }

            // This script block needs to be called after the main PHP block that defines $row or $error_message
            <?php if (!$error_message && isset($row['VisualizationData'])): ?>
            document.addEventListener('DOMContentLoaded', function() {
                try {
                    const vizDataString = <?php echo json_encode($row['VisualizationData']); ?>; // Get raw JSON string
                    const vizData = JSON.parse(vizDataString); // Parse it

                    if (vizData && vizData.containers && vizData.containers.length > 0) {
                        // For now, render the first container's 2D layers
                        renderContainer2DLayers('report-2d-layers-view', vizData.containers[0]);
                    } else {
                         document.getElementById('report-2d-layers-view').innerHTML = "<p><em>No container data found in visualization for 2D view.</em></p>";
                    }

                    // Placeholder for 3D initialization call
                    // if (typeof init3DViewer === 'function') {
                    //    init3DViewer('report-3d-view-placeholder', vizData);
                    // }

                } catch (e) {
                    console.error("Error processing visualization data for 2D/3D views:", e);
                    document.getElementById('report-2d-layers-view').innerHTML = "<p><em>Error loading 2D/3D visualization: " + e.message + "</em></p>";
                }
            });
            <?php endif; ?>

            // Placeholder for init3DViewer function if it were to be defined here
            /*
            function init3DViewer(containerId, vizData) {
                const placeholderDiv = document.getElementById(containerId);
                placeholderDiv.innerHTML = `<p><strong>3D Viewer Initialized (Simulated)</strong></p><pre>${JSON.stringify(vizData, null, 2)}</pre>`;
                // Actual Three.js/BabylonJS code would go here
            }
            */
        </script>

        <div class="report-footer">
            <p>&copy; <?php echo date("Y"); ?> <?php echo APP_NAME; ?>. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
