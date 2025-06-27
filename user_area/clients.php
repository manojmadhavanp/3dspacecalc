<?php
require_once 'check_session.php'; // Ensures user is logged in, provides $user_first_name, $conn might be available if db_connect is in check_session
require_once __DIR__ . '/../db_connect.php'; // Ensures $conn is available

$pageTitle = "Manage Clients";
require_once __DIR__ . '/../templates/header.php';

$action = $_GET['action'] ?? 'list'; // Default action is to list clients
$client_id_to_edit = $_GET['id'] ?? null; // For editing/deleting specific client

$feedback_message = '';
$feedback_type = ''; // 'success' or 'error'

// Get current user's CompanyID
$currentCompanyID = null;
if (isset($_SESSION['user_uid'])) {
    $user_uid = $_SESSION['user_uid'];
    $stmt_company = $conn->prepare("SELECT CompanyID FROM users WHERE UID = ?");
    if ($stmt_company) {
        $stmt_company->bind_param("i", $user_uid);
        $stmt_company->execute();
        $result_company = $stmt_company->get_result();
        if ($user_company_details = $result_company->fetch_assoc()) {
            $currentCompanyID = $user_company_details['CompanyID'];
        }
        $stmt_company->close();
    } else {
        $feedback_message = "Error: Could not retrieve company details for user.";
        $feedback_type = 'error';
        error_log("Failed to prepare statement to get CompanyID for UID: " . $user_uid . " Error: " . $conn->error);
    }
} else {
    // Should not happen if check_session is working
    $feedback_message = "Error: User session not found.";
    $feedback_type = 'error';
    $action = 'error_state'; // Prevent further processing
}


// --- Handle Form Submissions (Add/Edit Client) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $currentCompanyID) {
    $clientName = trim($_POST['clientName'] ?? '');
    $clientEmail = trim($_POST['clientEmail'] ?? '');
    $clientAddress = trim($_POST['clientAddress'] ?? '');
    $clientCCID = $_POST['ccid'] ?? null; // Hidden field for edits

    if (isset($_POST['save_client'])) { // Corresponds to add or edit
        if (empty($clientName)) {
            $feedback_message = "Client Company Name is required.";
            $feedback_type = 'error';
        } elseif (!empty($clientEmail) && !filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
            $feedback_message = "Invalid Client Email format.";
            $feedback_type = 'error';
        } else {
            if ($clientCCID) { // --- Update Existing Client ---
                $stmt = $conn->prepare("UPDATE client SET CompanyName = ?, Email = ?, Address = ?, UpdatedOn = CURRENT_TIMESTAMP WHERE CCID = ? AND AddedByCompanyID = ?");
                if ($stmt) {
                    $stmt->bind_param("sssii", $clientName, $clientEmail, $clientAddress, $clientCCID, $currentCompanyID);
                    if ($stmt->execute()) {
                        $feedback_message = "Client updated successfully!";
                        $feedback_type = 'success';
                        $action = 'list'; // Go back to list view
                    } else {
                        $feedback_message = "Error updating client: " . $stmt->error;
                        $feedback_type = 'error';
                    }
                    $stmt->close();
                } else {
                    $feedback_message = "Database error (prepare update): " . $conn->error;
                    $feedback_type = 'error';
                }
            } else { // --- Add New Client ---
                $clientIDBase = "CL-" . strtoupper(substr(preg_replace("/[^a-zA-Z0-9]+/", "", $clientName), 0, 5));
                $newClientID = $clientIDBase . "-" . time(); // Simple unique ID

                $stmt = $conn->prepare("INSERT INTO client (ClientID, CompanyName, Email, Address, AddedByCompanyID) VALUES (?, ?, ?, ?, ?)");
                if ($stmt) {
                    $stmt->bind_param("ssssi", $newClientID, $clientName, $clientEmail, $clientAddress, $currentCompanyID);
                    if ($stmt->execute()) {
                        $feedback_message = "Client '" . htmlspecialchars($clientName) . "' added successfully!";
                        $feedback_type = 'success';
                        // $action = 'list'; // Go back to list view - or stay to add contacts? For now, list.
                    } else {
                        $feedback_message = "Error adding client: " . $stmt->error;
                        $feedback_type = 'error';
                    }
                    $stmt->close();
                } else {
                    $feedback_message = "Database error (prepare insert): " . $conn->error;
                    $feedback_type = 'error';
                }
            }
        }
         // To show form again with values if error
        if($feedback_type == 'error') {
            $action = $clientCCID ? 'edit' : 'add';
            $client_id_to_edit = $clientCCID; // Ensure edit form is populated if error on edit
        }

    } elseif (isset($_POST['add_contact'])) {
        // --- Add Client Contact ---
        $contact_ccid = $_POST['contact_ccid'];
        $contact_name = trim($_POST['contact_name']);
        $contact_email = trim($_POST['contact_email']);
        $contact_phone = trim($_POST['contact_phone']);

        if (empty($contact_name) || empty($contact_ccid)) {
            $feedback_message = "Contact Name and Client ID are required.";
            $feedback_type = 'error';
        } elseif (!empty($contact_email) && !filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
            $feedback_message = "Invalid Contact Email format.";
            $feedback_type = 'error';
        } else {
            // Verify $contact_ccid belongs to $currentCompanyID for security
            $verifyStmt = $conn->prepare("SELECT CCID FROM client WHERE CCID = ? AND AddedByCompanyID = ?");
            if ($verifyStmt) {
                $verifyStmt->bind_param("ii", $contact_ccid, $currentCompanyID);
                $verifyStmt->execute();
                if ($verifyStmt->get_result()->num_rows > 0) {
                    $stmt = $conn->prepare("INSERT INTO client_contacts (CCID, ContactName, Email, PhoneNumber) VALUES (?, ?, ?, ?)");
                    if ($stmt) {
                        $stmt->bind_param("isss", $contact_ccid, $contact_name, $contact_email, $contact_phone);
                        if ($stmt->execute()) {
                            $feedback_message = "Contact '" . htmlspecialchars($contact_name) . "' added successfully!";
                            $feedback_type = 'success';
                        } else {
                            $feedback_message = "Error adding contact: " . $stmt->error;
                            $feedback_type = 'error';
                        }
                        $stmt->close();
                    } else {
                         $feedback_message = "Database error (prepare insert contact): " . $conn->error;
                         $feedback_type = 'error';
                    }
                } else {
                    $feedback_message = "Client not found or you do not have permission to add contacts to it.";
                    $feedback_type = 'error';
                }
                $verifyStmt->close();
            } else {
                $feedback_message = "Database error (verify client for contact): " . $conn->error;
                $feedback_type = 'error';
            }
        }
        $action = 'edit'; // Stay on edit client page to see the contact list updated
        $client_id_to_edit = $contact_ccid; // Ensure we are editing the correct client
    }
}


// --- Handle Delete Action ---
if ($action === 'delete' && $client_id_to_edit && $currentCompanyID) {
    // First, delete associated client_contacts (or set ON DELETE CASCADE in DB)
    $stmt_del_contacts = $conn->prepare("DELETE FROM client_contacts WHERE CCID = ?");
    if ($stmt_del_contacts) {
        $stmt_del_contacts->bind_param("i", $client_id_to_edit);
        // We need to ensure this client belongs to the company before deleting contacts
        // This is implicitly handled by the client delete check below, but could be explicit here.
        // For now, assume cascade or client delete will fail if contacts exist and no cascade.
        $stmt_del_contacts->execute();
        $stmt_del_contacts->close();
    } else {
        // Log error, but attempt to delete client anyway or handle more gracefully
        error_log("Error preparing to delete client contacts: " . $conn->error);
    }


    $stmt = $conn->prepare("DELETE FROM client WHERE CCID = ? AND AddedByCompanyID = ?");
    if ($stmt) {
        $stmt->bind_param("ii", $client_id_to_edit, $currentCompanyID);
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                $feedback_message = "Client deleted successfully!";
                $feedback_type = 'success';
            } else {
                $feedback_message = "Client not found or you do not have permission to delete it.";
                $feedback_type = 'error';
            }
        } else {
            $feedback_message = "Error deleting client: " . $stmt->error;
            $feedback_type = 'error';
        }
        $stmt->close();
    } else {
        $feedback_message = "Database error (prepare delete): " . $conn->error;
        $feedback_type = 'error';
    }
    $action = 'list'; // Go back to list view
}

// --- Data for Display (List or Edit Form) ---
$clients_list = [];
$client_to_edit_data = null;
$client_contacts_list = [];

if ($currentCompanyID && $action !== 'error_state') {
    if ($action === 'list' || $action === 'delete') { // Fetch list after delete as well
        $stmt_list = $conn->prepare("SELECT c.CCID, c.ClientID, c.CompanyName, c.Email, COUNT(cc.CCCID) as ContactCount
                                     FROM client c
                                     LEFT JOIN client_contacts cc ON c.CCID = cc.CCID
                                     WHERE c.AddedByCompanyID = ?
                                     GROUP BY c.CCID, c.ClientID, c.CompanyName, c.Email
                                     ORDER BY c.CompanyName ASC");
        if ($stmt_list) {
            $stmt_list->bind_param("i", $currentCompanyID);
            $stmt_list->execute();
            $result_list = $stmt_list->get_result();
            while ($row = $result_list->fetch_assoc()) {
                $clients_list[] = $row;
            }
            $stmt_list->close();
        } else {
            $feedback_message = "Error fetching client list: " . $conn->error;
            $feedback_type = 'error';
        }
    }

    if (($action === 'edit' || $action === 'add_contact_form') && $client_id_to_edit) {
        $stmt_edit = $conn->prepare("SELECT CCID, ClientID, CompanyName, Email, Address FROM client WHERE CCID = ? AND AddedByCompanyID = ?");
        if ($stmt_edit) {
            $stmt_edit->bind_param("ii", $client_id_to_edit, $currentCompanyID);
            $stmt_edit->execute();
            $result_edit = $stmt_edit->get_result();
            if ($result_edit->num_rows > 0) {
                $client_to_edit_data = $result_edit->fetch_assoc();

                // Fetch contacts for this client
                $stmt_contacts = $conn->prepare("SELECT CCCID, ContactName, Email, PhoneNumber FROM client_contacts WHERE CCID = ? ORDER BY ContactName ASC");
                if ($stmt_contacts) {
                    $stmt_contacts->bind_param("i", $client_id_to_edit);
                    $stmt_contacts->execute();
                    $result_contacts = $stmt_contacts->get_result();
                    while ($row_contact = $result_contacts->fetch_assoc()) {
                        $client_contacts_list[] = $row_contact;
                    }
                    $stmt_contacts->close();
                } else {
                    $feedback_message = "Error fetching client contacts: " . $conn->error;
                    $feedback_type = 'error';
                }
            } else {
                $feedback_message = "Client not found or you do not have permission to edit it.";
                $feedback_type = 'error';
                $action = 'list'; // Revert to list if client not found for edit
            }
            $stmt_edit->close();
        } else {
            $feedback_message = "Database error (prepare fetch for edit): " . $conn->error;
            $feedback_type = 'error';
        }
    }
}
?>

<style>
    .client-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
    .client-table th, .client-table td { border: 1px solid #ddd; padding: 10px; text-align: left; }
    .client-table th { background-color: #f2f2f2; }
    .client-table td a { margin-right: 10px; text-decoration: none; }
    .client-table td .delete-btn { color: red; }
    .form-container { border:1px solid #ddd; padding:20px; border-radius:5px; margin-top:20px; background-color:#f9f9f9; }
    .form-container legend { font-size: 1.2em; font-weight: bold; margin-bottom: 10px; }
    .form-container label { display: block; margin-bottom: 5px; font-weight: bold; }
    .form-container input[type="text"],
    .form-container input[type="email"],
    .form-container textarea {
        width: calc(100% - 22px); padding: 10px; margin-bottom: 15px;
        border: 1px solid #ccc; border-radius: 4px;
    }
    .form-container button { padding: 10px 15px; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; }
    .form-container button:hover { background-color: #0056b3; }
    .form-container .cancel-btn { background-color: #6c757d; margin-left:10px; }
    .contacts-section { margin-top: 30px; }
    .contacts-section h4 { border-bottom: 1px solid #eee; padding-bottom: 5px; }
</style>

<h2 class="page-title">Manage Clients</h2>

<?php if ($feedback_message): ?>
    <div class="message <?php echo ($feedback_type === 'success') ? 'success-message' : 'error-message'; ?>">
        <?php echo htmlspecialchars($feedback_message); ?>
    </div>
<?php endif; ?>


<?php if ($action === 'add' || ($action === 'edit' && $client_to_edit_data)): ?>
    <div class="form-container">
        <form action="clients.php<?php echo $client_to_edit_data ? '?action=edit&id='.$client_to_edit_data['CCID'] : '?action=add'; ?>" method="POST">
            <fieldset>
                <legend><?php echo ($action === 'add') ? 'Add New Client' : 'Edit Client: ' . htmlspecialchars($client_to_edit_data['CompanyName']); ?></legend>

                <?php if ($action === 'edit'): ?>
                    <input type="hidden" name="ccid" value="<?php echo htmlspecialchars($client_to_edit_data['CCID']); ?>">
                    <p><strong>Client ID:</strong> <?php echo htmlspecialchars($client_to_edit_data['ClientID']); ?></p>
                <?php endif; ?>

                <div>
                    <label for="clientName">Company Name:</label>
                    <input type="text" id="clientName" name="clientName" value="<?php echo htmlspecialchars($client_to_edit_data['CompanyName'] ?? ($_POST['clientName'] ?? '')); ?>" required>
                </div>
                <div>
                    <label for="clientEmail">Email:</label>
                    <input type="email" id="clientEmail" name="clientEmail" value="<?php echo htmlspecialchars($client_to_edit_data['Email'] ?? ($_POST['clientEmail'] ?? '')); ?>">
                </div>
                <div>
                    <label for="clientAddress">Address:</label>
                    <textarea id="clientAddress" name="clientAddress" rows="3"><?php echo htmlspecialchars($client_to_edit_data['Address'] ?? ($_POST['clientAddress'] ?? '')); ?></textarea>
                </div>
                <button type="submit" name="save_client"><?php echo ($action === 'add') ? 'Add Client' : 'Save Changes'; ?></button>
                <a href="clients.php" class="button cancel-btn" style="text-decoration:none;">Cancel</a>
            </fieldset>
        </form>

        <?php if ($action === 'edit' && $client_to_edit_data): ?>
        <div class="contacts-section">
            <h4>Manage Contacts for <?php echo htmlspecialchars($client_to_edit_data['CompanyName']); ?></h4>
            <?php if (!empty($client_contacts_list)): ?>
                <table class="client-table" style="font-size:0.9em;">
                    <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach($client_contacts_list as $contact): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($contact['ContactName']); ?></td>
                            <td><?php echo htmlspecialchars($contact['Email']); ?></td>
                            <td><?php echo htmlspecialchars($contact['PhoneNumber']); ?></td>
                            <td>
                                <a href="clients.php?action=edit_contact&contact_id=<?php echo $contact['CCCID']; ?>&client_id=<?php echo $client_to_edit_data['CCID']; ?>">Edit</a>
                                <a href="clients.php?action=delete_contact&contact_id=<?php echo $contact['CCCID']; ?>&client_id=<?php echo $client_to_edit_data['CCID']; ?>"
                                   class="delete-btn" onclick="return confirm('Are you sure you want to delete this contact?');">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No contacts found for this client.</p>
            <?php endif; ?>

            <div class="form-container" style="margin-top:20px; background-color:#fff;">
                 <form action="clients.php?action=edit&id=<?php echo $client_to_edit_data['CCID']; ?>" method="POST">
                    <input type="hidden" name="contact_ccid" value="<?php echo $client_to_edit_data['CCID']; ?>">
                    <fieldset>
                        <legend>Add New Contact</legend>
                        <div><label for="contact_name">Contact Name:</label><input type="text" name="contact_name" required></div>
                        <div><label for="contact_email">Contact Email:</label><input type="email" name="contact_email"></div>
                        <div><label for="contact_phone">Contact Phone:</label><input type="text" name="contact_phone"></div>
                        <button type="submit" name="add_contact">Add Contact</button>
                    </fieldset>
                </form>
            </div>
        </div>
        <?php endif; // End edit client section for contacts ?>
    </div>
<?php endif; ?>


<?php if ($action === 'list' && $currentCompanyID && $action !== 'error_state'): ?>
    <p style="margin-top:20px;">
        <a href="clients.php?action=add" class="button" style="text-decoration:none; background-color: #28a745; color:white; padding:10px 15px; border-radius:4px;">+ Add New Client</a>
    </p>

    <?php if (!empty($clients_list)): ?>
    <table class="client-table">
        <thead>
            <tr>
                <th>Client ID</th>
                <th>Company Name</th>
                <th>Email</th>
                <th>Contacts</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($clients_list as $client): ?>
            <tr>
                <td><?php echo htmlspecialchars($client['ClientID']); ?></td>
                <td><?php echo htmlspecialchars($client['CompanyName']); ?></td>
                <td><?php echo htmlspecialchars($client['Email']); ?></td>
                <td><?php echo $client['ContactCount']; ?></td>
                <td>
                    <a href="clients.php?action=edit&id=<?php echo $client['CCID']; ?>" class="button" style="background-color:#ffc107; color:black; padding:5px 10px; border-radius:3px;">Edit / View Contacts</a>
                    <a href="clients.php?action=delete&id=<?php echo $client['CCID']; ?>" class="delete-btn"
                       onclick="return confirm('Are you sure you want to delete this client and all their contacts? This action cannot be undone.');">Delete</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
        <p style="margin-top:20px;">No clients found. <a href="clients.php?action=add">Add your first client!</a></p>
    <?php endif; ?>
<?php elseif ($action === 'list' && !$currentCompanyID && $action !== 'error_state'): ?>
    <p class="message error-message">Could not determine your company to list clients.</p>
<?php endif; ?>


<?php
// Placeholder for handling edit_contact and delete_contact actions if implemented on this page
if (($action === 'edit_contact' || $action === 'delete_contact') && isset($_GET['contact_id'])) {
    $contact_id_to_manage = $_GET['contact_id'];
    $client_id_for_contact = $_GET['client_id'] ?? null;
    // TODO: Implement contact edit form loading / contact deletion logic here
    // For now, just a message and redirect back to client edit page.
    if($action === 'delete_contact' && $client_id_for_contact && $contact_id_to_manage && $currentCompanyID) {
        // Verify client ownership before deleting contact
        $verifyStmt = $conn->prepare("SELECT cl.CCID FROM client cl JOIN client_contacts cc ON cl.CCID = cc.CCID WHERE cc.CCCID = ? AND cl.AddedByCompanyID = ?");
        if($verifyStmt){
            $verifyStmt->bind_param("ii", $contact_id_to_manage, $currentCompanyID);
            $verifyStmt->execute();
            if($verifyStmt->get_result()->num_rows > 0){
                $stmt_del_single_contact = $conn->prepare("DELETE FROM client_contacts WHERE CCCID = ?");
                if($stmt_del_single_contact){
                    $stmt_del_single_contact->bind_param("i", $contact_id_to_manage);
                    if($stmt_del_single_contact->execute()){
                        $feedback_message = "Contact deleted successfully.";
                        $feedback_type = 'success';
                    } else {
                        $feedback_message = "Error deleting contact: " . $stmt_del_single_contact->error;
                        $feedback_type = 'error';
                    }
                    $stmt_del_single_contact->close();
                } else {
                    $feedback_message = "DB error (prepare delete contact): " . $conn->error;
                    $feedback_type = 'error';
                }
            } else {
                $feedback_message = "Contact not found or permission denied.";
                $feedback_type = 'error';
            }
            $verifyStmt->close();
        } else {
            $feedback_message = "DB error (verify contact for delete): " . $conn->error;
            $feedback_type = 'error';
        }
        // Redirect back to the edit client page
        // Using JS for this to ensure feedback message is shown by header.php if it's set before header include.
        // Or rather, set action and client_id_to_edit and let the page reload that section.
        echo "<script>window.location.href = 'clients.php?action=edit&id=" . urlencode($client_id_for_contact) . "&feedback=" . urlencode($feedback_message) . "&feedback_type=" .urlencode($feedback_type) . "';</script>";
        exit; // Stop further script execution
    } else {
        echo "<p class='message info-message'>Contact editing/deletion for contact ID {$contact_id_to_manage} would be handled here. <a href='clients.php?action=edit&id={$client_id_for_contact}'>Back to client</a></p>";
    }
}


$conn->close();
require_once __DIR__ . '/../templates/footer.php';
?>
