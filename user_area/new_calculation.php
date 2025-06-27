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
        const materialFile = document.getElementById('materialFile').files[0];
        if (!materialFile) {
            alert('Please select a material list file.');
            return;
        }

        // Show loading state (optional, for a real API call)
        itemMappingArea.innerHTML = '<div class="loading-spinner"></div><p>Processing file...</p>';
        itemMappingSection.style.display = 'block';
        outputSection.style.display = 'none'; // Hide previous results

        // Simulate API call to `getitemlist`
        // In a real app, this would be an AJAX call:
        // const formData = new FormData(materialUploadForm);
        // fetch('/api/getitemlist', { method: 'POST', body: formData })
        //   .then(response => response.json())
        //   .then(data => { /* ... handle data ... */ })
        //   .catch(error => { /* ... handle error ... */ });

        mockGetItemListAPI(materialFile)
            .then(items => {
                processedItemsData = items; // Store for later
                renderItemMappingTable(items);
                itemMappingSection.style.display = 'block';
                reportGenerationStatus.style.display = 'none';
            })
            .catch(error => {
                itemMappingArea.innerHTML = `<p class="error-message">Error processing file: ${error.message}</p>`;
                reportGenerationStatus.style.display = 'none';
            });
    });

    function mockGetItemListAPI(file) {
        console.log("Simulating API call to getitemlist with file:", file.name);
        return new Promise((resolve, reject) => {
            setTimeout(() => {
                // Simulate reading some items. For CSV, you might parse it here or server-side.
                // This is a very basic mock.
                if (file.name.endsWith('.csv') || file.name.endsWith('.txt')) {
                     const reader = new FileReader();
                     reader.onload = function(e) {
                        const lines = e.target.result.split('\\n').filter(line => line.trim() !== '');
                        const headers = lines[0] ? lines[0].split(',').map(h => h.trim()) : ['SKU', 'Name', 'Quantity', 'Length', 'Width', 'Height', 'Weight'];
                        const mockItems = [];
                        // Start from line 1 if headers exist, else 0
                        for(let i = (lines[0] ? 1:0) ; i < Math.min(lines.length, 6); i++) { // take first 5 data lines
                            const values = lines[i] ? lines[i].split(',') : [];
                            mockItems.push({
                                id: `item-${i}`,
                                sku: values[0] || `SKU_XYZ${i}`,
                                name: values[1] || `Product ${i}`,
                                quantity: parseInt(values[2] || (i+1) * 2),
                                length: parseFloat(values[3] || 10 + i),
                                width: parseFloat(values[4] || 10 + i),
                                height: parseFloat(values[5] || 10 + i),
                                weight: parseFloat(values[6] || 5 + i),
                                // Add more fields as needed by your backend
                            });
                        }
                         if(mockItems.length === 0 && lines.length > 0) { // If only header or empty file
                            mockItems.push({ id: 'item-empty', sku: 'NO_DATA', name: 'No data rows found in file', quantity:0, length:0,width:0,height:0,weight:0 });
                        } else if (mockItems.length === 0) {
                             mockItems.push({ id: 'item-empty', sku: 'EMPTY_FILE', name: 'File appears empty', quantity:0, length:0,width:0,height:0,weight:0 });
                        }
                        resolve(mockItems);
                     };
                     reader.onerror = function() {
                         reject(new Error('Could not read file content for mocking.'));
                     }
                     reader.readAsText(file);
                } else {
                     // Generic mock for non-csv for now
                    resolve([
                        { id: 'item-1', sku: 'SKU001', name: 'Large Box', quantity: 10, length: 50, width: 30, height: 20, weight: 5 },
                        { id: 'item-2', sku: 'SKU002', name: 'Medium Cylinder', quantity: 5, diameter: 20, height: 40, weight: 3 }, // Example of different shape
                        { id: 'item-3', sku: 'SKU003', name: 'Small Cube', quantity: 20, length: 10, width: 10, height: 10, weight: 1 }
                    ]);
                }
            }, 1500); // Simulate network delay
        });
    }

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
        // Collect mapped data
        const mappedItems = [];
        const itemRows = itemMappingArea.querySelectorAll('tbody tr');
        itemRows.forEach((row, index) => {
            const originalItem = processedItemsData[index]; // Get original item by index
            if (!originalItem) return;

            const mappedItem = { ...originalItem }; // Start with original data

            row.querySelectorAll('input').forEach(input => {
                const nameParts = input.name.split('-'); // e.g., item-1-quantity
                const key = nameParts.pop(); // e.g., quantity or stackable
                // const itemId = nameParts.join('-'); // e.g., item-1 (not strictly needed if using processedItemsData index)

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
        const containerType = document.getElementById('containerType').value;

        const reportPayload = {
            clientId: clientSelect,
            containerType: containerType,
            items: mappedItems,
            // Add any other global settings from #additional-inputs-area
        };

        // Show loading state
        reportGenerationStatus.style.display = 'block';
        outputSection.style.display = 'none';

        // Simulate API call to `generateReport`
        // In a real app, this would be an AJAX call:
        // fetch('/api/generateReport', {
        //   method: 'POST',
        //   headers: { 'Content-Type': 'application/json' },
        //   body: JSON.stringify(reportPayload)
        // })
        // .then(response => response.json())
        // .then(data => { /* ... handle data ... */ })
        // .catch(error => { /* ... handle error ... */ });

        mockGenerateReportAPI(reportPayload)
            .then(result => {
                reportArea.innerHTML = `<h4>HTML Report:</h4><div>${result.htmlReport}</div>`;
                visualizationArea.innerHTML = `<h4>3D Visualization:</h4><div id="viz-container" style="width:100%; height:300px; background:#eee; display:flex; align-items:center; justify-content:center;">${result.visualizationData.message} (Actual 3D model would render here based on data: ${JSON.stringify(result.visualizationData.modelDetails)})</div>`;

                shareLink.href = result.shareableLink;
                shareLink.textContent = result.shareableLink;
                shareLinkInput.value = result.shareableLink;
                shareLinkArea.style.display = 'block';

                outputSection.style.display = 'block';
            })
            .catch(error => {
                reportArea.innerHTML = `<p class="error-message">Error generating report: ${error.message}</p>`;
                visualizationArea.innerHTML = '';
                shareLinkArea.style.display = 'none';
                outputSection.style.display = 'block'; // Show output section to display the error
            })
            .finally(() => {
                reportGenerationStatus.style.display = 'none';
            });
    });

    function mockGenerateReportAPI(payload) {
        console.log("Simulating API call to generateReport with payload:", payload);
        return new Promise((resolve, reject) => {
            setTimeout(() => {
                // Simulate a successful response
                const reportId = `rep-${Date.now()}`;
                const shareableLink = `https://example.com/view_report/${reportId}?token=xyzabc`;

                // Here, a UID would be associated with the search, and the CompanyID
                // This would be done server-side when actually saving the search.
                // For now, this is just a mock.
                // A real implementation would also save payload (SearchFormData) and this response (SearchReturnData, ReportHTML, VisualizationData)
                // to the `search` table in the database.

                resolve({
                    reportId: reportId,
                    htmlReport: `<p>This is a <strong>simulated HTML report</strong> for container type ${payload.containerType} with ${payload.items.length} item types.</p><p>Total items: ${payload.items.reduce((sum, item) => sum + (item.quantity || 0), 0)}</p><p>Client ID: ${payload.clientId || 'N/A'}</p>`,
                    visualizationData: {
                        message: "Simulated 3D model placeholder.",
                        modelDetails: {
                            container: payload.containerType,
                            itemsLoaded: payload.items.length,
                            // ... more data for actual 3D rendering
                        }
                    },
                    shareableLink: shareableLink,
                    status: "success"
                });
            }, 2500); // Simulate network and processing delay
        });
    }

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
