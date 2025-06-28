<?php
require_once 'check_session.php'; // Ensures user is logged in, provides $user_first_name
require_once __DIR__ . '/../db_connect.php'; // For DB operations like fetching clients, checking usage

$pageTitle = "New Calculation";
require_once __DIR__ . '/../templates/header.php';

// --- Trial User Usage Check ---
// This is a simplified check. A more robust solution would be needed.
$can_use_tool = true;
$usage_message = '';

// Get user's company ID and subscription details
$companyID = null;
if (isset($_SESSION['user_uid'])) {
    $user_uid = $_SESSION['user_uid'];
    $stmt_company = $conn->prepare("SELECT CompanyID FROM users WHERE UID = ?");
    if($stmt_company){
        $stmt_company->bind_param("i", $user_uid);
        $stmt_company->execute();
        $result_company = $stmt_company->get_result();
        if ($user_company_details = $result_company->fetch_assoc()) {
            $companyID = $user_company_details['CompanyID'];
        }
        $stmt_company->close();
    } else {
        error_log("Failed to prepare statement to get CompanyID for UID: " . $user_uid . " Error: " . $conn->error);
    }
}

if ($companyID) {
    $stmt_sub = $conn->prepare(
        "SELECT cs.PackageName, cs.MaxCalculationsPerDay, COUNT(s.SearchID) as TodayCalculations
         FROM company_subscription cs
         LEFT JOIN search s ON cs.CompanyID = s.CompanyID AND DATE(s.SearchDateTime) = CURDATE()
         WHERE cs.CompanyID = ?
         GROUP BY cs.PackageName, cs.MaxCalculationsPerDay"
    );

    if($stmt_sub) {
        $stmt_sub->bind_param("i", $companyID);
        $stmt_sub->execute();
        $result_sub = $stmt_sub->get_result();

        if ($sub_details = $result_sub->fetch_assoc()) {
            if (strtolower($sub_details['PackageName']) == 'trial' || $sub_details['MaxCalculationsPerDay'] > 0) { // MaxCalculationsPerDay > 0 implies a limit
                if ($sub_details['TodayCalculations'] >= $sub_details['MaxCalculationsPerDay']) {
                    $can_use_tool = false;
                    $usage_message = "You have reached your daily limit of " . $sub_details['MaxCalculationsPerDay'] . " calculation(s) for the '" . htmlspecialchars($sub_details['PackageName']) . "' plan.";
                }
            }
            // If MaxCalculationsPerDay is 0 or NULL for non-trial, it means unlimited for that plan.
        } else {
             $usage_message = "Could not verify your subscription status. Please contact support.";
             // $can_use_tool = false; // Decide if this should block or not
             error_log("No subscription details found for CompanyID: " . $companyID);
        }
        $stmt_sub->close();
    } else {
        $usage_message = "Error checking usage limits.";
        // $can_use_tool = false; // Decide if this should block or not
        error_log("Failed to prepare statement for subscription check. Error: " . $conn->error);
    }
} else {
    $usage_message = "Could not identify your company to check usage limits.";
    // $can_use_tool = false; // Decide if this should block or not
    error_log("Could not get CompanyID for current user (UID: ".$_SESSION['user_uid'].") to check usage limits.");
}


// --- Fetch Clients for Dropdown ---
$clients = [];
if ($companyID) {
    $stmt_clients = $conn->prepare("SELECT CCID, ClientID, CompanyName FROM client WHERE AddedByCompanyID = ? ORDER BY CompanyName ASC");
    if($stmt_clients){
        $stmt_clients->bind_param("i", $companyID);
        $stmt_clients->execute();
        $result_clients = $stmt_clients->get_result();
        while ($row = $result_clients->fetch_assoc()) {
            $clients[] = $row;
        }
        $stmt_clients->close();
    } else {
         error_log("Failed to prepare statement to fetch clients. Error: " . $conn->error);
    }
}

?>

<style>
    #item-mapping-area table { width: 100%; border-collapse: collapse; margin-top: 15px; }
    #item-mapping-area th, #item-mapping-area td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    #item-mapping-area th { background-color: #f2f2f2; }
    #item-mapping-area input[type="text"], #item-mapping-area input[type="number"] { width: 90%; padding: 5px; }
    #report-area, #visualization-area { margin-top: 20px; padding: 15px; border: 1px dashed #ccc; min-height: 100px; background-color: #f9f9f9; }
    .loading-spinner {
        border: 5px solid #f3f3f3; /* Light grey */
        border-top: 5px solid #3498db; /* Blue */
        border-radius: 50%;
        width: 40px;
        height: 40px;
        animation: spin 1s linear infinite;
        margin: 20px auto;
    }
    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
</style>

<h2 class="page-title">Start New Freight Calculation</h2>

<?php if (!$can_use_tool): ?>
    <div class="message error-message">
        <p><?php echo htmlspecialchars($usage_message); ?></p>
        <p>Please <a href="../pricing.php">upgrade your plan</a> or wait until tomorrow to perform new calculations.</p>
    </div>
<?php else: ?>
    <?php if ($usage_message): // Display non-blocking messages, if any ?>
        <p class="message error-message"><?php echo htmlspecialchars($usage_message); ?></p>
    <?php endif; ?>

    <form id="materialUploadForm" enctype="multipart/form-data" style="border:1px solid #ddd; padding:20px; border-radius:5px;">
        <fieldset>
            <legend>Step 1: Setup & Material List</legend>

            <div>
                <label for="clientSelect">Select Client (Optional):</label>
                <select id="clientSelect" name="clientSelect" style="padding:10px; margin-bottom:15px; width:100%;">
                    <option value="">-- Select a Client --</option>
                    <?php foreach ($clients as $client): ?>
                        <option value="<?php echo htmlspecialchars($client['CCID']); ?>">
                            <?php echo htmlspecialchars($client['CompanyName']) . " (" . htmlspecialchars($client['ClientID']) . ")"; ?>
                        </option>
                    <?php endforeach; ?>
                    <?php if (empty($clients) && $companyID): ?>
                        <option value="" disabled>No clients found. <a href="clients.php">Add clients here.</a></option>
                    <?php elseif (!$companyID): ?>
                         <option value="" disabled>Could not load clients.</option>
                    <?php endif; ?>
                </select>
            </div>

            <div>
                <label for="materialFile">Upload Material List (e.g., CSV, XLS):</label>
                <input type="file" id="materialFile" name="materialFile" accept=".csv, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, application/vnd.ms-excel" required style="padding:10px; border:1px solid #ccc; border-radius:4px; width:calc(100% - 22px); margin-bottom:15px;">
            </div>

            <button type="button" id="processListBtn" class="button">Process Material List</button>
        </fieldset>
    </form>

    <div id="item-mapping-section" style="display:none; margin-top:20px; border:1px solid #ddd; padding:20px; border-radius:5px;">
        <fieldset>
            <legend>Step 2: Item Mapping & Additional Inputs</legend>
            <p>Review the items extracted from your file. Please map columns if necessary and provide any additional required inputs.</p>
            <div id="item-mapping-area">
                <!-- Item table will be injected here by JavaScript -->
            </div>
            <div id="additional-inputs-area" style="margin-top:15px;">
                <label for="containerType">Container Type:</label>
                <select id="containerType" name="containerType" style="padding:10px; margin-bottom:15px; width:100%;">
                    <option value="20ft_GP">20ft General Purpose</option>
                    <option value="40ft_GP">40ft General Purpose</option>
                    <option value="40ft_HC">40ft High Cube</option>
                    {/* Add more container types as needed */}
                </select>
                {/* Add other global inputs here, e.g., preferred stacking, etc. */}
            </div>
            <button type="button" id="generateReportBtn" class="button" style="background-color: #28a745;">Generate Report & Visualization</button>
        </fieldset>
    </div>

    <div id="report-generation-status" style="display:none; margin-top:20px; text-align:center;">
        <div class="loading-spinner"></div>
        <p>Generating your report and visualization... This may take a moment.</p>
    </div>

    <div id="output-section" style="display:none; margin-top:20px;">
        <h3 class="text-center">Calculation Results</h3>
        <div id="report-area">
            <h4>HTML Report:</h4>
            <p><em>(Report will be displayed here)</em></p>
        </div>
        <div id="visualization-area">
            <h4>3D Visualization:</h4>
            <p><em>(3D model will be displayed here)</em></p>
        </div>
        <div id="share-link-area" style="margin-top:15px; text-align:center;">
            <p>Shareable Link: <a href="#" id="shareLink" target="_blank"><em>(Link will appear here)</em></a></p>
            <input type="text" id="shareLinkInput" readonly style="width:70%; padding:8px;">
            <button type="button" id="copyShareLinkBtn" class="button" style="background-color:#6c757d;">Copy Link</button>
        </div>
    </div>

<?php endif; // End of $can_use_tool check ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const processListBtn = document.getElementById('processListBtn');
    const generateReportBtn = document.getElementById('generateReportBtn');
    const materialUploadForm = document.getElementById('materialUploadForm');
    const itemMappingSection = document.getElementById('item-mapping-section');
    const itemMappingArea = document.getElementById('item-mapping-area');
    const reportGenerationStatus = document.getElementById('report-generation-status');
    const outputSection = document.getElementById('output-section');
    const reportArea = document.getElementById('report-area');
    const visualizationArea = document.getElementById('visualization-area');
    const shareLinkArea = document.getElementById('share-link-area');
    const shareLink = document.getElementById('shareLink');
    const shareLinkInput = document.getElementById('shareLinkInput');
    const copyShareLinkBtn = document.getElementById('copyShareLinkBtn');

    let processedItemsData = []; // To store items after step 1

    processListBtn.addEventListener('click', function() {
        const materialFileInput = document.getElementById('materialFile');
        const materialFile = materialFileInput.files[0];

        // Frontend Validation
        if (!materialFile) {
            alert('Please select a material list file.');
            materialFileInput.focus();
            return;
        }
        // Basic file type validation (can be expanded)
        const allowedTypes = ['text/csv', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        if (!allowedTypes.includes(materialFile.type)) {
            // Note: Mime type check isn't foolproof. Server-side validation is more reliable.
            // This provides a basic UX check.
            // alert(`Invalid file type: ${materialFile.type}. Please upload a CSV or Excel file.`);
            // For now, let server handle strict type validation, as client-side can be bypassed.
            // We'll primarily check if a file is selected.
        }
        const maxFileSize = 5 * 1024 * 1024; // 5 MB
        if (materialFile.size > maxFileSize) {
            alert(`File is too large (${(materialFile.size / 1024 / 1024).toFixed(2)} MB). Maximum size is 5 MB.`);
            return;
        }

        itemMappingArea.innerHTML = '<div class="loading-spinner"></div><p>Processing file via API...</p>';
        itemMappingSection.style.display = 'block';
        outputSection.style.display = 'none'; // Hide previous results

        const formData = new FormData();
        formData.append('materialFile', materialFile);
        // Add other data if needed by API, e.g., selected client ID
        const clientSelect = document.getElementById('clientSelect').value;
        if(clientSelect) {
            formData.append('clientId', clientSelect);
        }

        // Use absolute API path via APP_CONFIG
        fetch(APP_CONFIG.baseApiUrl + '/calculation/getitemlist', {
            method: 'POST',
            body: formData
            // Headers are not strictly needed for FormData with fetch, browser sets multipart/form-data
        })
        .then(response => {
            if (!response.ok) {
                // Try to parse error JSON if server sent one, otherwise use statusText
                return response.json().catch(() => {
                    throw new Error(`API Error: ${response.status} ${response.statusText}`);
                }).then(errData => {
                     throw new Error(errData.error || `API Error: ${response.status} ${response.statusText}`);
                });
            }
            return response.json();
        })
        .then(data => {
            if (data.success && data.items) {
                processedItemsData = data.items; // Store for later
                renderItemMappingTable(data.items);
                itemMappingSection.style.display = 'block';
                if(data.message) { // Display any informational message from API
                    const msgDiv = document.createElement('p');
                    msgDiv.className = 'message success-message'; // Or 'info-message'
                    msgDiv.textContent = data.message;
                    itemMappingArea.insertBefore(msgDiv, itemMappingArea.firstChild);
                }
            } else {
                throw new Error(data.error || 'Failed to process file: API returned no items.');
            }
        })
        .catch(error => {
            console.error('Error calling getitemlist API:', error);
            itemMappingArea.innerHTML = `<p class="message error-message">Error processing file: ${error.message}</p>`;
        })
        .finally(() => {
            // Remove spinner if it wasn't replaced by table or error
            const spinner = itemMappingArea.querySelector('.loading-spinner');
            if (spinner && spinner.parentNode === itemMappingArea) { // Check if it's still a direct child
                 const p = spinner.nextElementSibling; // also remove the 'Processing file...' P tag
                 if(p) p.remove();
                 spinner.remove();
            }
             reportGenerationStatus.style.display = 'none'; // Ensure this is hidden
        });
    });

    // mockGetItemListAPI function is now removed as we use real fetch

    function renderItemMappingTable(items) {
        if (!items || items.length === 0) {
            itemMappingArea.innerHTML = '<p>No items extracted or file is empty.</p>';
            return;
        }

        let tableHTML = '<table><thead><tr>';
        // Dynamically create headers from the first item's keys, or use a predefined set
        const headers = Object.keys(items[0]);
        headers.forEach(header => tableHTML += `<th>${header.charAt(0).toUpperCase() + header.slice(1)}</th>`);
        tableHTML += '<th>Stackable?</th><th>Priority?</th></tr></thead><tbody>';

        items.forEach(item => {
            tableHTML += `<tr>`;
            headers.forEach(header => {
                // Make some fields editable as an example
                if (['quantity', 'length', 'width', 'height', 'weight', 'diameter'].includes(header)) {
                    tableHTML += `<td><input type="number" name="${item.id}-${header}" value="${item[header] || ''}" style="width:60px;"></td>`;
                } else {
                    tableHTML += `<td>${item[header]}</td>`;
                }
            });
            // Add example custom inputs for mapping
            tableHTML += `<td><input type="checkbox" name="${item.id}-stackable" checked></td>`;
            tableHTML += `<td><input type="number" name="${item.id}-priority" value="1" min="1" max="5" style="width:50px;"></td>`;
            tableHTML += `</tr>`;
        });
        tableHTML += '</tbody></table>';
        itemMappingArea.innerHTML = tableHTML;
    }

    generateReportBtn.addEventListener('click', function() {
        // --- Frontend Validation ---
        if (processedItemsData.length === 0) {
            alert('No items have been processed from a material list yet. Please complete Step 1.');
            // Potentially focus on the file input or processListBtn
            document.getElementById('materialFile').focus();
            return;
        }
        const containerTypeInput = document.getElementById('containerType');
        if (!containerTypeInput.value) {
            alert('Please select a container type.');
            containerTypeInput.focus();
            return;
        }
        // Add more validation for mapped item inputs if necessary (e.g., check for valid numbers in dimensions)
        let itemsValid = true;
        const itemRowsForValidation = itemMappingArea.querySelectorAll('tbody tr');
        itemRowsForValidation.forEach(row => {
            row.querySelectorAll('input[type="number"]').forEach(numInput => {
                if (isNaN(parseFloat(numInput.value)) && numInput.required) { // Simple check, can be more robust
                    itemsValid = false;
                    numInput.style.border = '1px solid red';
                } else {
                    numInput.style.border = ''; // Reset border
                }
            });
        });
        if (!itemsValid) {
            alert('Some item inputs are invalid or missing. Please check the highlighted fields.');
            return;
        }


        // --- Collect mapped data ---
        const mappedItems = [];
        const itemRows = itemMappingArea.querySelectorAll('tbody tr');
        itemRows.forEach((row, index) => {
            const originalItem = processedItemsData[index];
            if (!originalItem) return;

            const mappedItem = { ...originalItem };

            row.querySelectorAll('input, select').forEach(input => { // Include selects if any in rows
                const nameParts = input.name.split('-');
                const key = nameParts.pop();

                if (input.type === 'checkbox') {
                    mappedItem[key] = input.checked;
                } else if (input.type === 'number') {
                    mappedItem[key] = parseFloat(input.value) || 0;
                } else {
                    mappedItem[key] = input.value;
                }
            });
            mappedItems.push(mappedItem);
        });

        const clientSelect = document.getElementById('clientSelect').value;
        const containerType = containerTypeInput.value;

        const reportPayload = {
            clientId: clientSelect || null, // Send null if empty
            containerType: containerType,
            items: mappedItems,
            // Add any other global settings from #additional-inputs-area if they exist
        };

        reportGenerationStatus.style.display = 'block';
        outputSection.style.display = 'none';

        // Use absolute API path via APP_CONFIG
        fetch(APP_CONFIG.baseApiUrl + '/calculation/generatereport', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
            // If your API requires an auth token in headers, it should be added here:
            // 'Authorization': 'Bearer ' + localStorage.getItem('authToken')
          },
          body: JSON.stringify(reportPayload)
        })
        .then(response => {
            if (!response.ok) {
                 return response.json().catch(() => {
                    throw new Error(`API Error: ${response.status} ${response.statusText}`);
                }).then(errData => {
                     throw new Error(errData.error || `API Error: ${response.status} ${response.statusText}`);
                });
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                reportArea.innerHTML = `<h4>HTML Report:</h4><div>${data.htmlReport || 'No HTML report content.'}</div>`;

                // Display structured visualization data (text/JSON for now)
                let vizContent = '<h4>3D Visualization Data:</h4>';
                if (data.visualizationData) {
                    vizContent += `<pre style="background:#f0f0f0; padding:10px; border-radius:4px; font-size:0.8em; white-space: pre-wrap; word-break: break-all;">${JSON.stringify(data.visualizationData, null, 2)}</pre>`;
                } else {
                    vizContent += '<p>No visualization data returned.</p>';
                }
                visualizationArea.innerHTML = vizContent;

                if(data.shareableLink) {
                    shareLink.href = data.shareableLink;
                    shareLink.textContent = data.shareableLink;
                    shareLinkInput.value = data.shareableLink;
                    shareLinkArea.style.display = 'block';
                } else {
                    shareLinkArea.style.display = 'none';
                }
                outputSection.style.display = 'block';
            } else {
                 throw new Error(data.error || 'Failed to generate report: API returned an error.');
            }
        })
        .catch(error => {
            console.error('Error calling generateReport API:', error);
            reportArea.innerHTML = `<p class="message error-message">Error generating report: ${error.message}</p>`;
            visualizationArea.innerHTML = ''; // Clear previous viz
            shareLinkArea.style.display = 'none'; // Hide share link
            outputSection.style.display = 'block'; // Show output section to display the error
        })
        .finally(() => {
            reportGenerationStatus.style.display = 'none';
        });
    });

    // mockGenerateReportAPI function is now removed

    copyShareLinkBtn.addEventListener('click', function() {
        shareLinkInput.select();
        shareLinkInput.setSelectionRange(0, 99999); // For mobile devices
        try {
            document.execCommand('copy');
            alert('Shareable link copied to clipboard!');
        } catch (err) {
            alert('Failed to copy the link. Please copy it manually.');
        }
    });

});
</script>

<?php
$conn->close(); // Close DB connection
require_once __DIR__ . '/../templates/footer.php';
?>
