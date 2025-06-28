<?php
require_once 'check_session.php';
$pageTitle = "Manage Clients";
require_once __DIR__ . '/../templates/header_app.php';
?>
<?php /* Specific styles for clients.php, body class 'app-layout' is set in header_app.php */ ?>
<style>
    .client-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
    .client-table th, .client-table td { border: 1px solid #ddd; padding: 10px; text-align: left; }
    .client-table th { background-color: #f2f2f2; }
    .client-table td .action-btn { margin-right: 5px; text-decoration: none; padding: 5px 8px; border-radius: 3px; color: white; font-size:0.9em; cursor:pointer; }
    .client-table td .edit-btn { background-color: #ffc107; color:black; }
    .client-table td .delete-btn { background-color: #dc3545; }

    .form-modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4); }
    .form-modal-content { background-color: #fefefe; margin: 10% auto; padding: 20px; border: 1px solid #888; width: 80%; max-width: 600px; border-radius: 8px; position: relative; }
    .form-modal .close-btn-modal { color: #aaa; float: right; font-size: 28px; font-weight: bold; position: absolute; top: 10px; right: 20px; cursor: pointer; }
    .form-modal .close-btn-modal:hover, .form-modal .close-btn-modal:focus { color: black; text-decoration: none; }

    .form-container legend { font-size: 1.2em; font-weight: bold; margin-bottom: 10px; }
    .form-container label { display: block; margin-bottom: 5px; font-weight: bold; }
    .form-container input[type="text"],
    .form-container input[type="email"],
    .form-container textarea { width: calc(100% - 22px); padding: 10px; margin-bottom: 15px; border: 1px solid #ccc; border-radius: 4px; }
    .form-container button[type="submit"] { padding: 10px 15px; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; }
    .form-container .cancel-btn-modal { background-color: #6c757d; margin-left:10px; color:white; padding: 10px 15px; border:none; border-radius:4px; cursor:pointer;}

    #client-contacts-section h4 { border-bottom: 1px solid #eee; padding-bottom: 5px; margin-top:20px; }
    #client-contacts-list { list-style-type: none; padding-left: 0; }
    #client-contacts-list li { background-color: #f9f9f9; border:1px solid #eee; padding:8px; margin-bottom:5px; border-radius:3px; display:flex; justify-content:space-between; align-items:center; }
    #client-contacts-list li .contact-actions button { font-size:0.8em; padding:3px 6px; margin-left:5px; }
    button.action-btn { text-decoration:none; color:white; padding:8px 12px; border-radius:4px; border:none; cursor:pointer; }
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
                <th>Contacts Count</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody id="clients-table-body">
            <tr><td colspan="5" style="text-align:center;">Loading clients...</td></tr>
        </tbody>
    </table>
</div>

<div id="client-form-modal" class="form-modal">
    <div class="form-modal-content">
        <span class="close-btn-modal" id="close-client-modal-btn">&times;</span>
        <form id="client-form" class="form-container">
            <fieldset>
                <legend id="client-form-legend">Add New Client</legend>
                <input type="hidden" id="client-form-ccid" name="ccid">
                <div><label for="client-form-clientName">Company Name:</label><input type="text" id="client-form-clientName" name="clientName" required></div>
                <div><label for="client-form-clientEmail">Email:</label><input type="email" id="client-form-clientEmail" name="clientEmail"></div>
                <div><label for="client-form-clientAddress">Address:</label><textarea id="client-form-clientAddress" name="clientAddress" rows="3"></textarea></div>
                <button type="submit" id="save-client-btn">Save Client</button>
                <button type="button" class="cancel-btn-modal" id="cancel-client-form-btn">Cancel</button>
            </fieldset>
        </form>
        <div id="client-contacts-section" style="display: none; margin-top:25px;">
            <h4>Manage Contacts for <span id="contacts-for-client-name-span"></span></h4>
            <ul id="client-contacts-list"></ul>
            <div class="form-container" style="margin-top:10px; background-color:#f0f0f0; padding:15px; border-radius:4px;">
                 <form id="add-contact-form">
                    <input type="hidden" id="contact-form-current-client-ccid" name="contact_client_ccid">
                    <input type="hidden" id="contact-form-cccid" name="contact_cccid">
                    <fieldset>
                        <legend id="contact-form-legend">Add New Contact</legend>
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof APP_CONFIG === 'undefined' || !APP_CONFIG.baseApiUrl) {
        console.error('APP_CONFIG with baseApiUrl is not defined. Ensure header_app.php includes it.');
        const feedbackDiv = document.getElementById('client-feedback-message');
        feedbackDiv.textContent = 'Application configuration error. Cannot load clients.';
        feedbackDiv.className = 'message error-message';
        feedbackDiv.style.display = 'block';
        const tableBody = document.getElementById('clients-table-body');
        if(tableBody) tableBody.innerHTML = '<tr><td colspan="5" style="text-align:center; color:red;">App Config Error</td></tr>';
        return;
    }

    const clientsTableBody = document.getElementById('clients-table-body');
    const feedbackMessageDiv = document.getElementById('client-feedback-message');

    const clientModal = document.getElementById('client-form-modal');
    const clientForm = document.getElementById('client-form');
    const clientFormLegend = document.getElementById('client-form-legend');
    const clientFormCcidInput = document.getElementById('client-form-ccid');
    const clientContactsSection = document.getElementById('client-contacts-section');
    const saveClientBtn = document.getElementById('save-client-btn');
    const showAddClientBtn = document.getElementById('show-add-client-form-btn');
    const closeClientModalBtn = document.getElementById('close-client-modal-btn');
    const cancelClientFormBtn = document.getElementById('cancel-client-form-btn');

    const addContactForm = document.getElementById('add-contact-form');
    const addContactBtn = document.getElementById('add-contact-btn');
    const contactFormCccidInput = document.getElementById('contact-form-cccid'); // Already created hidden input
    const contactFormLegend = addContactForm.querySelector('legend');


    function displayFeedback(message, type = 'error') {
        feedbackMessageDiv.textContent = message;
        feedbackMessageDiv.className = `message ${type === 'success' ? 'success-message' : 'error-message'}`;
        feedbackMessageDiv.style.display = 'block';
        if (type === 'success') {
            setTimeout(() => { feedbackMessageDiv.style.display = 'none'; }, 5000);
        }
    }

    function fetchClients() {
        clientsTableBody.innerHTML = '<tr><td colspan="5" style="text-align:center;">Loading clients...</td></tr>';
        // authenticatedFetch is defined in header_app.php
        authenticatedFetch(APP_CONFIG.baseApiUrl + '/clients', { method: 'GET' })
        .then(response => {
            if (!response.ok) {
                return response.json().then(errData => { throw new Error(errData.message || errData.error || `Failed to load clients: ${response.status}`); })
                               .catch(() => new Error(`Failed to load clients: ${response.status} ${response.statusText}`));
            }
            return response.json();
        })
        .then(result => {
            clientsTableBody.innerHTML = '';
            if (result.status === 'success' && result.data && Array.isArray(result.data.clients)) {
                const clients = result.data.clients;
                if (clients.length === 0) {
                    clientsTableBody.innerHTML = '<tr><td colspan="5" style="text-align:center;">No clients found. Add your first client!</td></tr>';
                } else {
                    clients.forEach(client => {
                        const row = clientsTableBody.insertRow();
                        row.insertCell().textContent = client.id || client.ccid || 'N/A';
                        row.insertCell().textContent = client.name || client.companyName || 'N/A';
                        row.insertCell().textContent = client.email || 'N/A';
                        row.insertCell().textContent = client.contactsCount || client.contactCount || 0;
                        const actionsCell = row.insertCell();
                        actionsCell.innerHTML = `
                            <button class="action-btn edit-btn" data-client-id="${client.id || client.ccid}">Edit/Contacts</button>
                            <button class="action-btn delete-btn" data-client-id="${client.id || client.ccid}">Delete</button>
                        `;
                    });
                }
            } else {
                throw new Error(result.message || result.error || 'Invalid data received for clients.');
            }
        })
        .catch(error => {
            console.error('Error fetching clients:', error);
            clientsTableBody.innerHTML = `<tr><td colspan="5" style="text-align:center; color:red;">Error loading clients: ${error.message}</td></tr>`;
            if (error.message.includes("Session expired")) { /* authenticatedFetch handles redirect */ }
        });
    }

    function openClientModalForAdd() {
        clientFormLegend.textContent = 'Add New Client';
        clientForm.reset();
        contactFormCccidInput.value = ''; // Clear contact edit id
        addContactForm.reset(); // also reset contact form
        contactFormLegend.textContent = 'Add New Contact';
        document.getElementById('add-contact-btn').textContent = 'Add Contact';
        clientFormCcidInput.value = '';
        clientContactsSection.style.display = 'none';
        saveClientBtn.textContent = 'Add Client';
        displayFeedback('', 'success');
        clientModal.style.display = 'block';
    }

    function closeClientModal() {
        clientModal.style.display = 'none';
        clientForm.reset();
        addContactForm.reset();
        contactFormCccidInput.value = '';
        contactFormLegend.textContent = 'Add New Contact';
        document.getElementById('add-contact-btn').textContent = 'Add Contact';
    }

    showAddClientBtn.addEventListener('click', openClientModalForAdd);
    closeClientModalBtn.addEventListener('click', closeClientModal);
    cancelClientFormBtn.addEventListener('click', closeClientModal);
    window.addEventListener('click', function(event) {
        if (event.target == clientModal) closeClientModal();
    });

    clientForm.addEventListener('submit', function(event) {
        event.preventDefault();
        const ccid = clientFormCcidInput.value;
        const isEditMode = !!ccid;
        const clientName = document.getElementById('client-form-clientName').value.trim();
        const clientEmail = document.getElementById('client-form-clientEmail').value.trim();
        const clientAddress = document.getElementById('client-form-clientAddress').value.trim();

        if (!clientName) {
            displayFeedback('Client Company Name is required.', 'error');
            document.getElementById('client-form-clientName').focus();
            return;
        }
        if (clientEmail && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(clientEmail)) {
            displayFeedback('Invalid email format for client.', 'error');
            document.getElementById('client-form-clientEmail').focus();
            return;
        }

        const payload = { clientName, clientEmail, clientAddress };
        const apiUrl = isEditMode ? `${APP_CONFIG.baseApiUrl}/clients/${ccid}` : `${APP_CONFIG.baseApiUrl}/clients`;
        const apiMethod = isEditMode ? 'PUT' : 'POST';
        const originalButtonText = saveClientBtn.textContent;
        saveClientBtn.textContent = 'Saving...';
        saveClientBtn.disabled = true;

        authenticatedFetch(apiUrl, { method: apiMethod, body: JSON.stringify(payload) })
        .then(response => response.json().then(result => ({ok: response.ok, status: response.status, result})))
        .then(({ok, status, result}) => {
            if (!ok) {
                let errorMsg = result.message || result.error || `Operation failed: ${status}`;
                if (result.errors) {
                    const fieldErrors = Object.entries(result.errors).map(([field, messages]) => {
                        const capField = field.charAt(0).toUpperCase() + field.slice(1);
                        return `${capField}: ${messages.join(', ')}`;
                    }).join('; ');
                    errorMsg += ` Details: ${fieldErrors}`;
                }
                throw new Error(errorMsg);
            }
            if (result.status === 'success') {
                const nameForMsg = clientName || (result.data && (result.data.client?.name || result.data.client?.companyName)) || "Client";
                displayFeedback(result.message || `Client "${nameForMsg}" ${isEditMode ? 'updated' : 'added'} successfully!`, 'success');
                closeClientModal();
                fetchClients();
            } else {
                throw new Error(result.message || result.error || `Failed to ${isEditMode ? 'update' : 'add'} client.`);
            }
        })
        .catch(error => {
            console.error(`Error ${isEditMode ? 'updating' : 'adding'} client:`, error);
            displayFeedback(`Error: ${error.message}`, 'error');
        })
        .finally(() => {
            saveClientBtn.textContent = originalButtonText;
            saveClientBtn.disabled = false;
        });
    });

    clientsTableBody.addEventListener('click', function(event) {
        const target = event.target;
        const clientId = target.dataset.clientId;
        if (target.classList.contains('edit-btn') && clientId) {
            openClientModalForEdit(clientId);
        } else if (target.classList.contains('delete-btn') && clientId) {
            handleDeleteClient(clientId, target.closest('tr').querySelector('td:nth-child(2)').textContent);
        }
    });

    function openClientModalForEdit(ccid) {
        clientFormLegend.textContent = 'Loading client data...';
        clientForm.reset();
        addContactForm.reset();
        contactFormCccidInput.value = '';
        contactFormLegend.textContent = 'Add New Contact';
        document.getElementById('add-contact-btn').textContent = 'Add Contact';

        clientFormCcidInput.value = ccid;
        saveClientBtn.textContent = 'Save Changes';
        clientContactsSection.style.display = 'block';
        document.getElementById('contacts-for-client-name-span').textContent = '...';
        document.getElementById('client-contacts-list').innerHTML = '<li>Loading contacts...</li>';
        document.getElementById('contact-form-current-client-ccid').value = ccid;
        displayFeedback('', 'success');
        clientModal.style.display = 'block';

        authenticatedFetch(`${APP_CONFIG.baseApiUrl}/clients/${ccid}`, { method: 'GET' })
        .then(response => response.json().then(result => ({ok: response.ok, status: response.status, result})))
        .then(({ok, status, result}) => {
             if (!ok) { throw new Error(result.message || result.error || `Failed to fetch client details: ${status}`); }
            if (result.status === 'success' && result.data && result.data.client) {
                const client = result.data.client;
                clientFormLegend.textContent = `Edit Client: ${client.name || client.companyName}`;
                document.getElementById('client-form-clientName').value = client.name || client.companyName || '';
                document.getElementById('client-form-clientEmail').value = client.email || '';
                document.getElementById('client-form-clientAddress').value = client.address || '';
                document.getElementById('contacts-for-client-name-span').textContent = client.name || client.companyName || 'Selected Client';
                renderClientContacts(client.contacts || [], ccid);
            } else {
                throw new Error(result.message || result.error || 'Could not load client details.');
            }
        })
        .catch(error => {
            console.error('Error fetching client for edit:', error);
            displayFeedback(`Error: ${error.message}`, 'error');
            closeClientModal();
        });
    }

    function renderClientContacts(contacts, parentClientId) {
        const contactsListUl = document.getElementById('client-contacts-list');
        contactsListUl.innerHTML = '';
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
                    <button type="button" class="action-btn edit-contact-btn"
                        data-contact-id="${contact.id || contact.cccid}"
                        data-client-id="${parentClientId}"
                        data-contact-name="${contact.contactName || ''}"
                        data-contact-email="${contact.email || ''}"
                        data-contact-phone="${contact.phoneNumber || ''}"
                        style="background-color:#ffc107; color:black;">Edit</button>
                    <button type="button" class="action-btn delete-contact-btn"
                        data-contact-id="${contact.id || contact.cccid}"
                        data-client-id="${parentClientId}"
                        style="background-color:#dc3545;">Delete</button>
                </span>`;
            contactsListUl.appendChild(li);
        });
    }

    addContactForm.addEventListener('submit', function(event) {
        event.preventDefault();
        const clientCcid = document.getElementById('contact-form-current-client-ccid').value;
        const contactCccid = contactFormCccidInput.value;
        const isEditContactMode = !!contactCccid;
        const contactName = document.getElementById('contact-form-name').value.trim();
        const contactEmail = document.getElementById('contact-form-email').value.trim();
        const contactPhone = document.getElementById('contact-form-phone').value.trim();

        if (!clientCcid) { displayFeedback('Error: Client ID is missing for contact operation.', 'error'); return; }
        if (!contactName) { displayFeedback('Contact Name is required.', 'error'); document.getElementById('contact-form-name').focus(); return; }
        if (contactEmail && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(contactEmail)) { displayFeedback('Invalid email format for contact.', 'error'); document.getElementById('contact-form-email').focus(); return; }

        const payload = { contactName, contactEmail, contactPhone };
        const originalButtonText = addContactBtn.textContent;
        addContactBtn.textContent = isEditContactMode ? 'Saving...' : 'Adding...';
        addContactBtn.disabled = true;

        const contactApiUrl = isEditContactMode ?
            `${APP_CONFIG.baseApiUrl}/clients/${clientCcid}/contacts/${contactCccid}` :
            `${APP_CONFIG.baseApiUrl}/clients/${clientCcid}/contacts`;
        const contactApiMethod = isEditContactMode ? 'PUT' : 'POST';

        authenticatedFetch(contactApiUrl, { method: contactApiMethod, body: JSON.stringify(payload) })
        .then(response => response.json().then(result => ({ok: response.ok, status: response.status, result})))
        .then(({ok, status, result}) => {
            if (!ok) {
                let errorMsg = result.message || result.error || `Failed to ${isEditContactMode ? 'update' : 'add'} contact: ${status}`;
                if (result.errors) {
                    const fieldErrors = Object.entries(result.errors).map(([field, messages]) => `${field.charAt(0).toUpperCase() + field.slice(1)}: ${messages.join(', ')}`).join('; ');
                    errorMsg += ` Details: ${fieldErrors}`;
                }
                throw new Error(errorMsg);
            }
            if (result.status === 'success') {
                const nameForMsg = contactName || (result.data && result.data.contact?.contactName) || "Contact";
                displayFeedback(result.message || `Contact "${nameForMsg}" ${isEditContactMode ? 'updated' : 'added'} successfully!`, 'success');
                addContactForm.reset();
                contactFormCccidInput.value = '';
                contactFormLegend.textContent = 'Add New Contact';
                addContactBtn.textContent = 'Add Contact';
                openClientModalForEdit(clientCcid);
            } else {
                throw new Error(result.message || result.error || `Failed to ${isEditContactMode ? 'update' : 'add'} contact.`);
            }
        })
        .catch(error => {
            console.error(`Error ${isEditContactMode ? 'updating' : 'adding'} contact:`, error);
            displayFeedback(`Error: ${error.message}`, 'error');
        })
        .finally(() => {
            if(contactFormCccidInput.value === '') {
                 contactFormLegend.textContent = 'Add New Contact';
                 addContactBtn.textContent = 'Add Contact';
            } else {
                 contactFormLegend.textContent = 'Edit Contact';
                 addContactBtn.textContent = 'Save Contact Changes';
            }
            addContactBtn.disabled = false;
        });
    });

    function openContactFormForEdit(contactData) {
        addContactForm.reset();
        contactFormLegend.textContent = 'Edit Contact';
        contactFormCccidInput.value = contactData.id;
        document.getElementById('contact-form-current-client-ccid').value = contactData.clientId;
        document.getElementById('contact-form-name').value = contactData.name;
        document.getElementById('contact-form-email').value = contactData.email;
        document.getElementById('contact-form-phone').value = contactData.phone;
        addContactBtn.textContent = 'Save Contact Changes';
        document.getElementById('contact-form-name').focus();
    }

    document.getElementById('client-contacts-list').addEventListener('click', function(event) {
        const target = event.target.closest('button.action-btn');
        if (!target) return;
        const contactId = target.dataset.contactId;
        const clientId = target.dataset.clientId;
        if (target.classList.contains('edit-contact-btn') && contactId) {
            const contactData = {
                id: contactId, clientId: clientId,
                name: target.dataset.contactName,
                email: target.dataset.contactEmail,
                phone: target.dataset.contactPhone
            };
            openContactFormForEdit(contactData);
        } else if (target.classList.contains('delete-contact-btn') && contactId) {
            handleDeleteClientContact(clientId, contactId, target.closest('li').querySelector('strong').textContent);
        }
    });

    function handleDeleteClient(ccid, clientName) {
        if (!confirm(`Are you sure you want to delete client "${clientName}" (ID: ${ccid})? This action cannot be undone and will also delete all associated contacts.`)) {
            return;
        }
        displayFeedback('Deleting client...', 'info');
        authenticatedFetch(`${APP_CONFIG.baseApiUrl}/clients/${ccid}`, { method: 'DELETE' })
        .then(response => {
            if (response.status === 204) {
                return { status: 'success', message: `Client "${clientName}" (ID: ${ccid}) has been deleted.` , data: { id: ccid } };
            }
            return response.json().then(result => ({ok: response.ok, status: response.status, result}));
        })
        .then(({ok, status, result}) => { // Destructure only if not already an object from 204 handler
             if (result && result.status === 'success') { // Check if result exists and has status (for 204, result is our constructed obj)
                displayFeedback(result.message, 'success');
                fetchClients();
            } else if (ok && status !== 204 ) { // HTTP OK but internal API status might not be success
                 throw new Error(result.message || result.error || 'Failed to delete client due to an API processing issue.');
            } else if (!ok) { // HTTP error
                 throw new Error(result.message || result.error || `Failed to delete client: ${status}`);
            } else { // Fallback for 204 if not caught by first if, though it should be.
                displayFeedback(`Client "${clientName}" (ID: ${ccid}) deleted successfully.`, 'success');
                fetchClients();
            }
        })
        .catch(error => {
            console.error('Error deleting client:', error);
            displayFeedback(`Error deleting client: ${error.message}`, 'error');
        });
    }

    function handleDeleteClientContact(clientId, contactId, contactName) {
        if (!confirm(`Are you sure you want to delete contact "${contactName}" (ID: ${contactId})?`)) {
            return;
        }
        displayFeedback('Deleting contact...', 'info');
        const authToken = getAuthToken(); // Ensure getAuthToken is defined and used
        if (!authToken) { displayFeedback('Auth error.'); return; }

        authenticatedFetch(`${APP_CONFIG.baseApiUrl}/clients/${clientId}/contacts/${contactId}`, { method: 'DELETE' })
        .then(response => {
            if (response.status === 204) {
                return { status: 'success', message: `Contact "${contactName}" (ID: ${contactId}) deleted.` , data: {id: contactId} };
            }
            return response.json().then(result => ({ok: response.ok, status: response.status, result}));
        })
        .then(({ok, status, result}) => {
            if (result && result.status === 'success') {
                displayFeedback(result.message, 'success');
                openClientModalForEdit(clientId);
            } else if (ok && status !== 204) {
                 throw new Error(result.message || result.error || 'Failed to delete contact due to an API processing issue.');
            } else if (!ok) {
                 throw new Error(result.message || result.error || `Failed to delete contact: ${status}`);
            } else {
                displayFeedback(`Contact "${contactName}" (ID: ${contactId}) deleted successfully.`, 'success');
                openClientModalForEdit(clientId);
            }
        })
        .catch(error => {
            console.error('Error deleting contact:', error);
            displayFeedback(`Error deleting contact: ${error.message}`, 'error');
        });
    }
    fetchClients(); // Initial load
});
</script>

<?php
require_once __DIR__ . '/../templates/footer_app.php'; // Use APP footer
?>
