<?php
// This file will now be primarily a view, with JS handling data and interactions.
// Session check for page access is still important.
require_once 'check_session.php';
// config.php is included by header.php, which gives us APP_CONFIG.baseApiUrl etc.
$pageTitle = "Manage Clients";
require_once __DIR__ . '/../templates/header.php';
?>

<style>
    /* Styles specific to clients.php, can be moved to global style.css if widely used */
    .client-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
    .client-table th, .client-table td { border: 1px solid #ddd; padding: 10px; text-align: left; }
    .client-table th { background-color: #f2f2f2; }
    .client-table td .action-btn { margin-right: 5px; text-decoration: none; padding: 5px 8px; border-radius: 3px; color: white; font-size:0.9em; cursor:pointer; }
    .client-table td .edit-btn { background-color: #ffc107; color:black; }
    .client-table td .delete-btn { background-color: #dc3545; }
    /* .client-table td .view-contacts-btn { background-color: #17a2b8; } */ /* Combined with edit */


    .form-modal {
        display: none; /* Hidden by default */
        position: fixed;
        z-index: 1000; /* Sit on top */
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        overflow: auto; /* Enable scroll if needed */
        background-color: rgba(0,0,0,0.4); /* Black w/ opacity */
    }

    .form-modal-content {
        background-color: #fefefe;
        margin: 10% auto; /* 10% from the top and centered */
        padding: 20px;
        border: 1px solid #888;
        width: 80%;
        max-width: 600px;
        border-radius: 8px;
        position: relative;
    }

    .form-modal .close-btn-modal { /* Renamed to avoid conflict if other close buttons exist */
        color: #aaa;
        float: right;
        font-size: 28px;
        font-weight: bold;
        position: absolute;
        top: 10px;
        right: 20px;
    }
    .form-modal .close-btn-modal:hover,
    .form-modal .close-btn-modal:focus {
        color: black;
        text-decoration: none;
        cursor: pointer;
    }

    .form-container legend { font-size: 1.2em; font-weight: bold; margin-bottom: 10px; }
    .form-container label { display: block; margin-bottom: 5px; font-weight: bold; }
    .form-container input[type="text"],
    .form-container input[type="email"],
    .form-container textarea {
        width: calc(100% - 22px); padding: 10px; margin-bottom: 15px;
        border: 1px solid #ccc; border-radius: 4px;
    }
    .form-container button[type="submit"] { padding: 10px 15px; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; }
    .form-container .cancel-btn-modal { background-color: #6c757d; margin-left:10px; color:white; padding: 10px 15px; border:none; border-radius:4px; cursor:pointer;}

    #client-contacts-section h4 { border-bottom: 1px solid #eee; padding-bottom: 5px; margin-top:20px; }
    #client-contacts-list { list-style-type: none; padding-left: 0; }
    #client-contacts-list li { background-color: #f9f9f9; border:1px solid #eee; padding:8px; margin-bottom:5px; border-radius:3px; display:flex; justify-content:space-between; align-items:center; }
    #client-contacts-list li .contact-actions button { font-size:0.8em; padding:3px 6px; margin-left:5px; }

    /* General button styling from style.css might be button.button or input[type=button] */
    button.action-btn { /* For general action buttons if not input type submit */
        text-decoration:none; color:white; padding:8px 12px; border-radius:4px; border:none; cursor:pointer;
    }
</style>

<h2 class="page-title">Manage Clients</h2>

<div id="client-feedback-message" class="message" style="display: none;"></div>

<p style="margin-top:20px;">
    <button type="button" id="show-add-client-form-btn" class="action-btn" style="background-color: #28a745;">+ Add New Client</button>
</p>

<div id="client-list-container">
    <table class="client-table">
        <thead>
            <tr>
                <th>Client ID (System)</th>
                <th>Company Name</th>
                <th>Email</th>
                <th>Contacts Count</th> <!-- JS will fill this based on API response -->
                <th>Actions</th>
            </tr>
        </thead>
        <tbody id="clients-table-body">
            <tr><td colspan="5" style="text-align:center;">Loading clients...</td></tr>
        </tbody>
    </table>
</div>


<!-- Add/Edit Client Modal -->
<div id="client-form-modal" class="form-modal">
    <div class="form-modal-content">
        <span class="close-btn-modal" id="close-client-modal-btn">&times;</span>
        <form id="client-form" class="form-container">
            <fieldset>
                <legend id="client-form-legend">Add New Client</legend>
                <input type="hidden" id="client-form-ccid" name="ccid">
                <div>
                    <label for="client-form-clientName">Company Name:</label>
                    <input type="text" id="client-form-clientName" name="clientName" required>
                </div>
                <div>
                    <label for="client-form-clientEmail">Email:</label>
                    <input type="email" id="client-form-clientEmail" name="clientEmail">
                </div>
                <div>
                    <label for="client-form-clientAddress">Address:</label>
                    <textarea id="client-form-clientAddress" name="clientAddress" rows="3"></textarea>
                </div>
                <button type="submit" id="save-client-btn">Save Client</button>
                <button type="button" class="cancel-btn-modal" id="cancel-client-form-btn">Cancel</button>
            </fieldset>
        </form>

        <!-- Contacts section - only shown when editing a client -->
        <div id="client-contacts-section" style="display: none; margin-top:25px;">
            <h4>Manage Contacts for <span id="contacts-for-client-name-span"></span></h4>
            <ul id="client-contacts-list">
                <!-- Contacts will be listed here: <li>Name (Email) <button>Delete</button></li> -->
            </ul>
            <div class="form-container" style="margin-top:10px; background-color:#f0f0f0; padding:15px; border-radius:4px;">
                 <form id="add-contact-form">
                    <!-- ccid for this contact will be set programmatically when edit modal opens -->
                    <input type="hidden" id="contact-form-current-client-ccid" name="contact_client_ccid">
                    <fieldset>
                        <legend>Add New Contact</legend>
                        <div><label for="contact-form-name">Contact Name:</label><input type="text" id="contact-form-name" name="contact_name" required></div>
                        <div><label for="contact-form-email">Contact Email:</label><input type="email" id="contact-form-email" name="contact_email"></div>
                        <div><label for="contact-form-phone">Contact Phone:</label><input type="text" id="contact-form-phone" name="contact_phone"></div>
                        <button type="submit" id="add-contact-btn">Add Contact</button>
                    </fieldset>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript for API interactions will go here in the next step -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof APP_CONFIG === 'undefined' || !APP_CONFIG.baseApiUrl) {
        console.error('APP_CONFIG with baseApiUrl is not defined. Ensure header.php includes it.');
        const feedbackDiv = document.getElementById('client-feedback-message');
        feedbackDiv.textContent = 'Application configuration error. Cannot load clients.';
        feedbackDiv.className = 'message error-message';
        feedbackDiv.style.display = 'block';
        // Clear loading message from table
        const tableBody = document.getElementById('clients-table-body');
        if(tableBody) tableBody.innerHTML = '<tr><td colspan="5" style="text-align:center; color:red;">App Config Error</td></tr>';
        return;
    }

    const clientsTableBody = document.getElementById('clients-table-body');
    const feedbackMessageDiv = document.getElementById('client-feedback-message');

    function displayFeedback(message, type = 'error') {
        feedbackMessageDiv.textContent = message;
        feedbackMessageDiv.className = `message ${type === 'success' ? 'success-message' : 'error-message'}`;
        feedbackMessageDiv.style.display = 'block';
    }

    function fetchClients() {
        clientsTableBody.innerHTML = '<tr><td colspan="5" style="text-align:center;">Loading clients...</td></tr>';
        const authToken = localStorage.getItem('authToken');
        if (!authToken) {
            displayFeedback('Authentication token not found. Please login again.');
            // Potentially redirect to login: window.location.href = APP_CONFIG.baseUrl + '/login';
            clientsTableBody.innerHTML = '<tr><td colspan="5" style="text-align:center; color:red;">Not Authenticated</td></tr>';
            return;
        }

        fetch(APP_CONFIG.baseApiUrl + '/clients', {
            method: 'GET',
            headers: {
                'Authorization': 'Bearer ' + authToken,
                'Accept': 'application/json'
            }
        })
        .then(response => {
            if (response.status === 401) { // Unauthorized
                localStorage.removeItem('authToken');
                window.dispatchEvent(new CustomEvent('authChange'));
                throw new Error('Session expired or invalid. Please login again.');
            }
            if (!response.ok) {
                return response.json().then(errData => {
                    throw new Error(errData.error || errData.message || `Failed to load clients: ${response.status}`);
                }).catch(() => new Error(`Failed to load clients: ${response.status} ${response.statusText}`));
            }
            return response.json();
        })
        .then(data => {
            clientsTableBody.innerHTML = ''; // Clear loading message
            if (data.success && Array.isArray(data.clients)) {
                if (data.clients.length === 0) {
                    clientsTableBody.innerHTML = '<tr><td colspan="5" style="text-align:center;">No clients found. Add your first client!</td></tr>';
                } else {
                    data.clients.forEach(client => {
                        const row = clientsTableBody.insertRow();
                        row.insertCell().textContent = client.ccid || client.id || 'N/A'; // Assuming API returns ccid or id
                        row.insertCell().textContent = client.companyName || 'N/A';
                        row.insertCell().textContent = client.email || 'N/A';
                        row.insertCell().textContent = client.contactCount || 0; // Assuming API returns contactCount

                        const actionsCell = row.insertCell();
                        actionsCell.innerHTML = `
                            <button class="action-btn edit-btn" data-client-id="${client.ccid || client.id}">Edit/Contacts</button>
                            <button class="action-btn delete-btn" data-client-id="${client.ccid || client.id}">Delete</button>
                        `;
                    });
                }
            } else {
                throw new Error(data.error || data.message || 'Invalid data received for clients.');
            }
        })
        .catch(error => {
            console.error('Error fetching clients:', error);
            clientsTableBody.innerHTML = `<tr><td colspan="5" style="text-align:center; color:red;">Error loading clients: ${error.message}</td></tr>`;
            if (error.message.includes("Session expired")) {
                // Optional: redirect to login after a short delay
                // setTimeout(() => { window.location.href = APP_CONFIG.baseUrl + '/login?session_expired=true'; }, 2000);
            }
        });
    }

    // Initial load of clients
    fetchClients();

    // --- Modal handling basic logic (from previous step, ensure it's here) ---
    const clientModal = document.getElementById('client-form-modal');
    // Ensure other modal related consts are defined if they were separate
    // const showAddClientBtn = ... , const closeClientModalBtn = ..., const cancelClientFormBtn = ...
    // --- Modal handling basic logic ---
    const clientModal = document.getElementById('client-form-modal');
    const clientForm = document.getElementById('client-form');
    const clientFormLegend = document.getElementById('client-form-legend');
    const clientFormCcidInput = document.getElementById('client-form-ccid');
    const clientContactsSection = document.getElementById('client-contacts-section');
    const saveClientBtn = document.getElementById('save-client-btn');

    const showAddClientBtn = document.getElementById('show-add-client-form-btn');
    const closeClientModalBtn = document.getElementById('close-client-modal-btn');
    const cancelClientFormBtn = document.getElementById('cancel-client-form-btn');

    function openClientModalForAdd() {
        clientFormLegend.textContent = 'Add New Client';
        clientForm.reset();
        clientFormCcidInput.value = ''; // Ensure no ID for add mode
        clientContactsSection.style.display = 'none'; // Hide contacts for new client
        saveClientBtn.textContent = 'Add Client';
        displayFeedback('', 'success'); // Clear previous feedback
        clientModal.style.display = 'block';
    }

    function closeClientModal() {
        clientModal.style.display = 'none';
        clientForm.reset(); // Reset form when closing
    }

    showAddClientBtn.addEventListener('click', openClientModalForAdd);
    closeClientModalBtn.addEventListener('click', closeClientModal);
    cancelClientFormBtn.addEventListener('click', closeClientModal);
    window.addEventListener('click', function(event) { // Close if clicked outside modal content
        if (event.target == clientModal) {
            closeClientModal();
        }
    });

    // --- Handle Add/Edit Client Form Submission ---
    clientForm.addEventListener('submit', function(event) {
        event.preventDefault();
        const ccid = clientFormCcidInput.value;
        const isEditMode = !!ccid;

        const clientName = document.getElementById('client-form-clientName').value.trim();
        const clientEmail = document.getElementById('client-form-clientEmail').value.trim();
        const clientAddress = document.getElementById('client-form-clientAddress').value.trim();

        // Basic Client-side validation
        if (!clientName) {
            alert('Client Company Name is required.'); // Simple alert for now
            return;
        }
        if (clientEmail && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(clientEmail)) {
            alert('Invalid email format for client.');
            return;
        }

        const payload = { clientName, clientEmail, clientAddress };
        const apiUrl = isEditMode ? `${APP_CONFIG.baseApiUrl}/clients/${ccid}` : `${APP_CONFIG.baseApiUrl}/clients`;
        const apiMethod = isEditMode ? 'PUT' : 'POST';

        const originalButtonText = saveClientBtn.textContent;
        saveClientBtn.textContent = 'Saving...';
        saveClientBtn.disabled = true;

        const authToken = localStorage.getItem('authToken');
        if (!authToken) {
            displayFeedback('Authentication error. Please login again.');
            saveClientBtn.textContent = originalButtonText;
            saveClientBtn.disabled = false;
            return;
        }

        fetch(apiUrl, {
            method: apiMethod,
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + authToken,
                'Accept': 'application/json'
            },
            body: JSON.stringify(payload)
        })
        .then(response => {
            if (response.status === 401) {
                localStorage.removeItem('authToken');
                window.dispatchEvent(new CustomEvent('authChange'));
                throw new Error('Session expired. Please login again.');
            }
            if (!response.ok) {
                 return response.json().then(errData => {
                    throw new Error(errData.error || errData.message || `Operation failed: ${response.status}`);
                }).catch(() => new Error(`Operation failed: ${response.status} ${response.statusText}`));
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                displayFeedback(data.message || `Client ${isEditMode ? 'updated' : 'added'} successfully!`, 'success');
                closeClientModal();
                fetchClients(); // Refresh the client list
            } else {
                throw new Error(data.error || data.message || `Failed to ${isEditMode ? 'update' : 'add'} client.`);
            }
        })
        .catch(error => {
            console.error(`Error ${isEditMode ? 'updating' : 'adding'} client:`, error);
            // Display error inside the modal or as a general feedback message
            alert(`Error: ${error.message}`); // Simple alert for now, can integrate with modal's own error display
            displayFeedback(`Error: ${error.message}`, 'error');
        })
        .finally(() => {
            saveClientBtn.textContent = originalButtonText;
            saveClientBtn.disabled = false;
        });
    });

    // --- Event Delegation for Edit/Delete buttons on Client Table ---
    clientsTableBody.addEventListener('click', function(event) {
        const target = event.target;
        const clientId = target.dataset.clientId;

        if (target.classList.contains('edit-btn') && clientId) {
            openClientModalForEdit(clientId);
        } else if (target.classList.contains('delete-btn') && clientId) {
            handleDeleteClient(clientId, target.closest('tr').querySelector('td:nth-child(2)').textContent); // Pass name for confirm message
        }
    });

    function handleDeleteClient(ccid, clientName) {
        if (!confirm(`Are you sure you want to delete client "${clientName}" (ID: ${ccid})? This action cannot be undone and will also delete all associated contacts.`)) {
            return;
        }

        displayFeedback('Deleting client...', 'info'); // Show an info message
        const authToken = localStorage.getItem('authToken');
        if (!authToken) {
            displayFeedback('Authentication error. Please login again.');
            return;
        }

        fetch(`${APP_CONFIG.baseApiUrl}/clients/${ccid}`, {
            method: 'DELETE',
            headers: {
                'Authorization': 'Bearer ' + authToken,
                'Accept': 'application/json'
            }
        })
        .then(response => {
            if (response.status === 401) { /* ... (auth error handling) ... */ throw new Error('Session expired.'); }
            // DELETE might return 204 No Content on success, or JSON
            if (response.status === 204) { // Successfully deleted, no content
                return { success: true, message: `Client "${clientName}" deleted successfully.` };
            }
            if (!response.ok) {
                return response.json().then(errData => {
                    throw new Error(errData.error || errData.message || `Failed to delete client: ${response.status}`);
                }).catch(() => new Error(`Failed to delete client: ${response.status} ${response.statusText}`));
            }
            return response.json(); // If API returns JSON on successful delete
        })
        .then(data => {
            if (data.success) {
                displayFeedback(data.message || `Client "${clientName}" deleted successfully.`, 'success');
                fetchClients(); // Refresh the client list
            } else {
                throw new Error(data.error || data.message || 'Failed to delete client.');
            }
        })
        .catch(error => {
            console.error('Error deleting client:', error);
            displayFeedback(`Error deleting client: ${error.message}`, 'error');
        });
    }


    function openClientModalForEdit(ccid) {
        clientFormLegend.textContent = 'Loading client data...';
        clientForm.reset();
        clientFormCcidInput.value = ccid; // Set ID for edit mode
        saveClientBtn.textContent = 'Save Changes';
        clientContactsSection.style.display = 'block'; // Show contacts section
        document.getElementById('contacts-for-client-name-span').textContent = '...';
        document.getElementById('client-contacts-list').innerHTML = '<li>Loading contacts...</li>';
        document.getElementById('contact-form-current-client-ccid').value = ccid;
        displayFeedback('', 'success'); // Clear global feedback
        clientModal.style.display = 'block';

        const authToken = localStorage.getItem('authToken');
        if (!authToken) {
            displayFeedback('Authentication error. Please login again.');
            closeClientModal();
            return;
        }

        // Fetch client details (which should include contacts as per API design assumption)
        fetch(`${APP_CONFIG.baseApiUrl}/clients/${ccid}`, {
            method: 'GET',
            headers: {
                'Authorization': 'Bearer ' + authToken,
                'Accept': 'application/json'
            }
        })
        .then(response => {
            if (response.status === 401) { /* ... (auth error handling as in fetchClients) ... */ throw new Error('Session expired.'); }
            if (!response.ok) { /* ... (general error handling as in fetchClients) ... */ throw new Error('Failed to fetch client details.'); }
            return response.json();
        })
        .then(data => {
            if (data.success && data.client) {
                const client = data.client;
                clientFormLegend.textContent = `Edit Client: ${client.companyName}`;
                document.getElementById('client-form-clientName').value = client.companyName || '';
                document.getElementById('client-form-clientEmail').value = client.email || '';
                document.getElementById('client-form-clientAddress').value = client.address || '';
                document.getElementById('contacts-for-client-name-span').textContent = client.companyName || 'Selected Client';

                // Render contacts
                renderClientContacts(client.contacts || []);
            } else {
                throw new Error(data.error || data.message || 'Could not load client details.');
            }
        })
        .catch(error => {
            console.error('Error fetching client for edit:', error);
            displayFeedback(`Error: ${error.message}`, 'error');
            closeClientModal(); // Close modal on error fetching details
        });
    }

    function renderClientContacts(contacts) {
        const contactsListUl = document.getElementById('client-contacts-list');
        contactsListUl.innerHTML = ''; // Clear previous contacts or loading message

        if (!contacts || contacts.length === 0) {
            contactsListUl.innerHTML = '<li>No contacts found for this client.</li>';
            return;
        }

        contacts.forEach(contact => {
            const li = document.createElement('li');
            li.innerHTML = `
                <span>
                    <strong>${contact.contactName || 'N/A'}</strong>
                    (${contact.email || 'No Email'}) - ${contact.phoneNumber || 'No Phone'}
                </span>
                <span class="contact-actions">
                    <button type="button" class="action-btn edit-contact-btn" data-contact-id="${contact.cccid || contact.id}" data-client-id="${contact.ccid}" style="background-color:#ffc107; color:black;">Edit</button>
                    <button type="button" class="action-btn delete-contact-btn" data-contact-id="${contact.cccid || contact.id}" data-client-id="${contact.ccid}" style="background-color:#dc3545;">Delete</button>
                </span>
            `;
            contactsListUl.appendChild(li);
        });
        // Add event listeners for new contact edit/delete buttons here or via delegation
    }


    // --- Add Contact Form Submission (inside modal) ---
    // --- Add Contact Form Submission (inside modal) ---
    const addContactForm = document.getElementById('add-contact-form');
    const addContactBtn = document.getElementById('add-contact-btn');

    addContactForm.addEventListener('submit', function(event) {
        event.preventDefault();
        const clientCcid = document.getElementById('contact-form-current-client-ccid').value;
        const contactName = document.getElementById('contact-form-name').value.trim();
        const contactEmail = document.getElementById('contact-form-email').value.trim();
        const contactPhone = document.getElementById('contact-form-phone').value.trim();

        if (!clientCcid) {
            alert('Error: Client ID is missing for adding contact.');
            return;
        }
        if (!contactName) {
            alert('Contact Name is required.');
            document.getElementById('contact-form-name').focus();
            return;
        }
        if (contactEmail && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(contactEmail)) {
            alert('Invalid email format for contact.');
            document.getElementById('contact-form-email').focus();
            return;
        }

        const payload = { contactName, contactEmail, contactPhone };
        const originalButtonText = addContactBtn.textContent;
        addContactBtn.textContent = 'Adding...';
        addContactBtn.disabled = true;

        const authToken = localStorage.getItem('authToken');
        if (!authToken) {
            displayFeedback('Authentication error. Please login again.'); // Use global feedback
            addContactBtn.textContent = originalButtonText;
            addContactBtn.disabled = false;
            return;
        }

        fetch(`${APP_CONFIG.baseApiUrl}/clients/${clientCcid}/contacts`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + authToken,
                'Accept': 'application/json'
            },
            body: JSON.stringify(payload)
        })
        .then(response => {
            if (response.status === 401) { /* ... */ throw new Error('Session expired.'); }
            if (!response.ok) {
                return response.json().then(errData => {
                    throw new Error(errData.error || errData.message || `Failed to add contact: ${response.status}`);
                }).catch(() => new Error(`Failed to add contact: ${response.status} ${response.statusText}`));
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                displayFeedback(data.message || 'Contact added successfully!', 'success');
                addContactForm.reset();
                // Refresh contacts list for the current client
                // Assuming API for GET /clients/{ccid} returns the updated client with contacts
                openClientModalForEdit(clientCcid); // This will re-fetch and re-render
            } else {
                throw new Error(data.error || data.message || 'Failed to add contact.');
            }
        })
        .catch(error => {
            console.error('Error adding contact:', error);
            // Display error. Could be a specific div within the contacts section or global.
            alert(`Error adding contact: ${error.message}`); // Simple alert for now
            displayFeedback(`Error adding contact: ${error.message}`, 'error');
        })
        .finally(() => {
            addContactBtn.textContent = originalButtonText;
            addContactBtn.disabled = false;
        });
    });


    // --- Event Delegation for Contact Actions (Delete/Edit) ---
    document.getElementById('client-contacts-list').addEventListener('click', function(event) {
        const target = event.target.closest('button.action-btn'); // Ensure we get the button
        if (!target) return;

        const contactId = target.dataset.contactId;
        const clientId = target.dataset.clientId;

        if (target.classList.contains('edit-contact-btn') && contactId) {
            alert(`Edit contact ID: ${contactId} for client ID: ${clientId} - to be implemented.`);
            // TODO: Implement edit contact:
            // 1. Fetch contact details (or have them already if GET /clients/{id} returns full contact objects)
            // 2. Populate a form (could reuse add-contact-form or a new one)
            // 3. Submit PUT request to /clients/{clientId}/contacts/{contactId}
        } else if (target.classList.contains('delete-contact-btn') && contactId) {
            handleDeleteClientContact(clientId, contactId, target.closest('li').querySelector('strong').textContent);
        }
    });

    function handleDeleteClientContact(clientId, contactId, contactName) {
        if (!confirm(`Are you sure you want to delete contact "${contactName}" (ID: ${contactId})?`)) {
            return;
        }
        displayFeedback('Deleting contact...', 'info');
        const authToken = localStorage.getItem('authToken');
        // ... (Auth token check as in other functions) ...
        if (!authToken) { displayFeedback('Auth error.'); return; }


        fetch(`${APP_CONFIG.baseApiUrl}/clients/${clientId}/contacts/${contactId}`, {
            method: 'DELETE',
            headers: {
                'Authorization': 'Bearer ' + authToken,
                'Accept': 'application/json'
            }
        })
        .then(response => {
            if (response.status === 401) { /* ... */ throw new Error('Session expired.'); }
            if (response.status === 204) { // No Content success
                return { success: true, message: `Contact "${contactName}" deleted.` };
            }
            if (!response.ok) {
                return response.json().then(errData => {
                    throw new Error(errData.error || errData.message || `Failed to delete contact: ${response.status}`);
                }).catch(() => new Error(`Failed to delete contact: ${response.status} ${response.statusText}`));
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                displayFeedback(data.message || 'Contact deleted successfully.', 'success');
                openClientModalForEdit(clientId); // Refresh contacts list by re-fetching client
            } else {
                throw new Error(data.error || data.message || 'Failed to delete contact.');
            }
        })
        .catch(error => {
            console.error('Error deleting contact:', error);
            displayFeedback(`Error deleting contact: ${error.message}`, 'error');
        });
    }
});
</script>

<?php
require_once __DIR__ . '/../templates/footer.php';
?>
