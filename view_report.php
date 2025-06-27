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
                if (!empty($row['VisualizationData'])) {
                    $viz_data = json_decode($row['VisualizationData'], true);
                    // For display, we'll just show a message or the raw JSON for the placeholder
                    if (isset($viz_data['message'])) {
                        $visualization_content = "<p>" . htmlspecialchars($viz_data['message']) . "</p>";
                        if(isset($viz_data['modelDetails'])) {
                             $visualization_content .= "<pre style='background:#f0f0f0; padding:10px; border-radius:4px; font-size:0.8em;'>" . htmlspecialchars(json_encode($viz_data['modelDetails'], JSON_PRETTY_PRINT)) . "</pre>";
                        }
                    } else {
                        $visualization_content = "<pre style='background:#f0f0f0; padding:10px; border-radius:4px; font-size:0.8em;'>" . htmlspecialchars(json_encode($viz_data, JSON_PRETTY_PRINT)) . "</pre>";
                    }
                } else {
                    $visualization_content = "<p><em>No visualization data available.</em></p>";
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
