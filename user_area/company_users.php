<?php
require_once 'check_session.php'; // Still useful for initial page access protection if direct access is attempted.
                                 // Actual feature authorization will be API-driven & client-side role checks.
$pageTitle = "Manage Company Users";
require_once __DIR__ . '/../templates/header.php'; // Provides APP_CONFIG
?>

<style>
    /* Styles specific to company_users.php, can be moved or merged */
    .users-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
    .users-table th, .users-table td { border: 1px solid #ddd; padding: 10px; text-align: left; }
    .users-table th { background-color: #f2f2f2; }
    .users-table td select { padding: 5px; border-radius: 3px; border: 1px solid #ccc; }

    .form-modal { /* Reusing modal style, could be global */
        display: none; position: fixed; z-index: 1000; left: 0; top: 0;
        width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4);
    }
    .form-modal-content {
        background-color: #fefefe; margin: 10% auto; padding: 20px; border: 1px solid #888;
        width: 80%; max-width: 550px; border-radius: 8px; position: relative;
    }
    .form-modal .close-btn-modal {
        color: #aaa; float: right; font-size: 28px; font-weight: bold;
        position: absolute; top: 10px; right: 20px; cursor: pointer;
    }
    .form-container legend { font-size: 1.2em; font-weight: bold; margin-bottom: 10px; }
    .form-container label { display: block; margin-bottom: 5px; font-weight: bold; }
    .form-container input[type="text"],
    .form-container input[type="email"],
    .form-container input[type="tel"],
    .form-container input[type="password"],
    .form-container select {
        width: calc(100% - 22px); padding: 10px; margin-bottom: 15px;
        border: 1px solid #ccc; border-radius: 4px;
    }
    .form-container button[type="submit"] { padding: 10px 15px; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; }
    .form-container .cancel-btn-modal { background-color: #6c757d; margin-left:10px; color:white; padding: 10px 15px; border:none; border-radius:4px; cursor:pointer;}

    .subscription-info { margin-bottom: 20px; padding: 15px; background-color: #e9ecef; border-radius: 5px; border: 1px solid #ced4da; }
    .subscription-info p { margin: 5px 0; }
</style>

<h2 class="page-title"><?php echo htmlspecialchars($pageTitle); ?></h2>

<div id="company-users-feedback" class="message" style="display: none;"></div>

<div id="subscription-info-container" class="subscription-info">
    <p><strong>Current Plan:</strong> <span id="company-plan-name">Loading...</span></p>
    <p><strong>User Limit:</strong> <span id="company-user-count">?</span> / <span id="company-max-users">?</span> Users</p>
    <p id="user-limit-message" style="color:red; display:none;">You have reached your user limit. <a href="<?php echo rtrim(BASE_URL, '/'); ?>/pricing">Upgrade Plan</a> to add more users.</p>
</div>

<p style="margin-top:20px;">
    <button type="button" id="show-add-user-form-btn" class="action-btn" style="background-color: #28a745;">+ Add New User</button>
</p>

<div id="user-list-container">
    <table class="users-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Email / Phone</th>
                <th>Role</th>
                <th>Status</th>
                <th>Last Login</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody id="company-users-table-body">
            <tr><td colspan="6" style="text-align:center;">Loading users...</td></tr>
        </tbody>
    </table>
</div>

<!-- Add User Modal -->
<div id="add-user-modal" class="form-modal">
    <div class="form-modal-content">
        <span class="close-btn-modal" id="close-add-user-modal-btn">&times;</span>
        <form id="add-user-form" class="form-container">
            <fieldset>
                <legend>Add New User to Company</legend>
                <div><label for="newUserFirstName">First Name:</label><input type="text" id="newUserFirstName" name="newUserFirstName" required></div>
                <div><label for="newUserLastName">Last Name:</label><input type="text" id="newUserLastName" name="newUserLastName" required></div>
                <div><label for="newUserEmail">Email:</label><input type="email" id="newUserEmail" name="newUserEmail" required></div>
                <div><label for="newUserPhone">Phone Number (Optional):</label><input type="tel" id="newUserPhone" name="newUserPhone"></div>
                <div>
                    <label for="newUserRoleID">Role:</label>
                    <select id="newUserRoleID" name="newUserRoleID" required>
                        <option value="">-- Select Role --</option>
                        <!-- Roles will be populated by JS if dynamic, or hardcoded if simple -->
                        <!-- Example: <option value="ROLE_USER_ID_FROM_API">User</option> -->
                        <!-- Example: <option value="ROLE_COMPANY_ADMIN_ID_FROM_API">Company Admin</option> -->
                    </select>
                </div>
                <div><label for="newUserPassword">Set Initial Password (min 8 chars):</label><input type="password" id="newUserPassword" name="newUserPassword" required minlength="8"></div>
                <button type="submit" id="save-new-user-btn">Add User</button>
                <button type="button" class="cancel-btn-modal" id="cancel-add-user-form-btn">Cancel</button>
            </fieldset>
        </form>
    </div>
</div>


<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof APP_CONFIG === 'undefined' || !APP_CONFIG.baseApiUrl) {
        console.error('APP_CONFIG with baseApiUrl is not defined.');
        const feedbackDiv = document.getElementById('company-users-feedback');
        feedbackDiv.textContent = 'Application configuration error.';
        feedbackDiv.className = 'message error-message';
        feedbackDiv.style.display = 'block';
        document.getElementById('company-users-table-body').innerHTML = '<tr><td colspan="6" style="text-align:center;color:red;">App Config Error</td></tr>';
        return;
    }

    // --- DOM Elements ---
    const feedbackDiv = document.getElementById('company-users-feedback');
    const usersTableBody = document.getElementById('company-users-table-body');
    // Subscription Info Elements
    const companyPlanNameSpan = document.getElementById('company-plan-name');
    const companyUserCountSpan = document.getElementById('company-user-count');
    const companyMaxUsersSpan = document.getElementById('company-max-users');
    const userLimitMessageP = document.getElementById('user-limit-message');
    const showAddUserBtn = document.getElementById('show-add-user-form-btn');

    // Add User Modal Elements
    const addUserModal = document.getElementById('add-user-modal');
    const closeAddUserModalBtn = document.getElementById('close-add-user-modal-btn');
    const cancelAddUserBtn = document.getElementById('cancel-add-user-form-btn');
    const addUserForm = document.getElementById('add-user-form');
    const newUserRoleIDSelect = document.getElementById('newUserRoleID');


    // --- Helper Functions ---
    function displayFeedback(message, type = 'error') {
        feedbackDiv.textContent = message;
        feedbackDiv.className = `message ${type === 'success' ? 'success-message' : 'error-message'}`;
        feedbackDiv.style.display = 'block';
    }

    function getAuthToken() {
        const token = localStorage.getItem('authToken');
        if (!token) {
            displayFeedback('Authentication error. Please login again.');
            // Potentially redirect: window.location.href = APP_CONFIG.baseUrl + '/login';
            return null;
        }
        return token;
    }

    // --- Modal Logic for Add User ---
    function openAddUserModal() {
        addUserForm.reset();
        // TODO: Populate #newUserRoleID select with roles fetched from an API or predefined
        // For now, assuming some roles might be hardcoded or fetched separately.
        // Example: fetchRolesAndPopulateSelect();
        addUserModal.style.display = 'block';
    }
    function closeAddUserModal() {
        addUserModal.style.display = 'none';
    }
    showAddUserBtn.addEventListener('click', openAddUserModal);
    closeAddUserModalBtn.addEventListener('click', closeAddUserModal);
    cancelAddUserBtn.addEventListener('click', closeAddUserModal);
    window.addEventListener('click', function(event) {
        if (event.target == addUserModal) closeAddUserModal();
    });

    let currentCompanyMaxUsers = 0;
    let currentCompanyUserCount = 0;

    // --- Fetch Company Users and Subscription Info ---
    function fetchCompanyData() {
        usersTableBody.innerHTML = '<tr><td colspan="6" style="text-align:center;">Loading company data...</td></tr>';
        companyPlanNameSpan.textContent = 'Loading...';
        companyUserCountSpan.textContent = '?';
        companyMaxUsersSpan.textContent = '?';
        userLimitMessageP.style.display = 'none';
        showAddUserBtn.disabled = true; // Disable until limits known

        const authToken = getAuthToken();
        if (!authToken) {
            usersTableBody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:red;">Authentication required.</td></tr>';
            return;
        }

        // Assuming API endpoint /company/details or similar that returns users and subscription info
        // Or two separate calls: /company/users and /company/subscription
        // For this example, let's assume one endpoint /company/users returns all needed info:
        // { success: true, data: { users: [...], subscription: { packageName, maxUsers, currentUserCount (or derive from users.length) } } }
        // Or, if API for users is just /users?company_id=current, and another for subscription.
        // Let's assume GET /company/users returns users and subscription details for the admin's company
        fetch(APP_CONFIG.baseApiUrl + '/company/users', { // This endpoint needs to be defined in backend
            method: 'GET',
            headers: {
                'Authorization': 'Bearer ' + authToken,
                'Accept': 'application/json'
            }
        })
        .then(response => {
            if (response.status === 401) {
                localStorage.removeItem('authToken');
                window.dispatchEvent(new CustomEvent('authChange'));
                throw new Error('Session expired. Please login again.');
            }
            if (!response.ok) {
                return response.json().then(err => { throw new Error(err.message || err.error || `API Error: ${response.status}`); })
                               .catch(() => { throw new Error(`API Error: ${response.status} ${response.statusText}`); });
            }
            return response.json();
        })
        .then(result => {
            if (result.status === 'success' && result.data) {
                const companyData = result.data;

                // Populate Subscription Info
                if (companyData.subscription) {
                    companyPlanNameSpan.textContent = companyData.subscription.packageName || 'N/A';
                    currentCompanyUserCount = companyData.users ? companyData.users.length : (companyData.subscription.currentUserCount || 0);
                    currentCompanyMaxUsers = parseInt(companyData.subscription.maxUsers) || 0; // 0 for unlimited

                    companyUserCountSpan.textContent = currentCompanyUserCount;
                    companyMaxUsersSpan.textContent = currentCompanyMaxUsers === 0 ? 'Unlimited' : currentCompanyMaxUsers;

                    if (currentCompanyMaxUsers > 0 && currentCompanyUserCount >= currentCompanyMaxUsers) {
                        userLimitMessageP.style.display = 'block';
                        showAddUserBtn.disabled = true;
                        showAddUserBtn.title = 'User limit reached for your current plan.';
                    } else {
                        userLimitMessageP.style.display = 'none';
                        showAddUserBtn.disabled = false;
                        showAddUserBtn.title = 'Add a new user to your company';
                    }
                } else {
                    companyPlanNameSpan.textContent = 'Unknown';
                    companyMaxUsersSpan.textContent = 'Unknown';
                    showAddUserBtn.disabled = true; // Can't determine limits
                     displayFeedback('Could not load subscription details.', 'error');
                }

                // Populate Users Table
                usersTableBody.innerHTML = ''; // Clear loading
                if (companyData.users && companyData.users.length > 0) {
                    companyData.users.forEach(user => {
                        const row = usersTableBody.insertRow();
                        row.insertCell().textContent = `${user.firstName || ''} ${user.lastName || ''}`.trim();
                        row.insertCell().innerHTML = `${user.email || 'N/A'}<br><small>${user.phoneNumber || 'N/A'}</small>`;
                        row.insertCell().textContent = user.roleName || 'N/A'; // Assuming roleName is provided

                        const statusCell = row.insertCell();
                        statusCell.textContent = user.status ? user.status.charAt(0).toUpperCase() + user.status.slice(1) : 'N/A';

                        row.insertCell().textContent = user.lastLogin ? new Date(user.lastLogin).toLocaleString() : 'Never';

                        const actionsCell = row.insertCell();
                        if (user.uid !== (JSON.parse(localStorage.getItem('userData'))?.uid || null) ) { // Don't allow actions on self via this list for status
                             // Status change will be a dropdown or buttons, implement in 4d
                            actionsCell.innerHTML = `<select class="user-status-select" data-user-id="${user.uid}" data-current-status="${user.status}">
                                <option value="active" ${user.status === 'active' ? 'selected' : ''}>Active</option>
                                <option value="inactive" ${user.status === 'inactive' ? 'selected' : ''}>Inactive</option>
                                <option value="suspended" ${user.status === 'suspended' ? 'selected' : ''}>Suspended</option>
                            </select>`;
                        } else {
                            actionsCell.textContent = '(Your Account)';
                        }
                    });
                } else {
                    usersTableBody.innerHTML = '<tr><td colspan="6" style="text-align:center;">No users found in your company.</td></tr>';
                }
                 // Populate roles dropdown for "Add User" modal
                populateRolesDropdown(companyData.assignableRoles || []);


            } else {
                throw new Error(result.message || result.error || 'Failed to load company data.');
            }
        })
        .catch(error => {
            console.error('Error fetching company data:', error);
            displayFeedback(`Error: ${error.message}`, 'error');
            usersTableBody.innerHTML = `<tr><td colspan="6" style="text-align:center; color:red;">Failed to load data: ${error.message}</td></tr>`;
            companyPlanNameSpan.textContent = 'Error';
        });
    }

    function populateRolesDropdown(roles) {
        newUserRoleIDSelect.innerHTML = '<option value=\"\">-- Select Role --</option>'; // Clear existing
        if (roles && roles.length > 0) {
            roles.forEach(role => {
                const option = document.createElement('option');
                option.value = role.rid; // Assuming API returns 'rid' and 'roleName'
                option.textContent = role.roleName;
                newUserRoleIDSelect.appendChild(option);
            });
        } else {
            // Fallback or default roles if API doesn't provide them
            // This is just an example, ideally API provides this.
            const defaultRoles = [
                { rid: 2, roleName: 'CompanyAdmin' }, // Assuming RID 2 = CompanyAdmin from schema
                { rid: 3, roleName: 'User' }          // Assuming RID 3 = User from schema
            ];
             defaultRoles.forEach(role => {
                if (role.roleName !== 'Admin') { // Prevent assigning system-wide Admin
                    const option = document.createElement('option');
                    option.value = role.rid;
                    option.textContent = role.roleName;
                    newUserRoleIDSelect.appendChild(option);
                }
            });
            console.warn("Assignable roles not provided by API, using hardcoded defaults for Add User form.");
        }
    }


    // Initial data load
    fetchCompanyData();

    // --- Handle User Status Change ---
    usersTableBody.addEventListener('change', function(event) {
        if (event.target.classList.contains('user-status-select')) {
            const selectElement = event.target;
            const userId = selectElement.dataset.userId;
            const currentStatus = selectElement.dataset.currentStatus;
            const newStatus = selectElement.value;

            if (newStatus === currentStatus) {
                return; // No change
            }

            if (!confirm(`Are you sure you want to change status for user ID ${userId} from '${currentStatus}' to '${newStatus}'?`)) {
                selectElement.value = currentStatus; // Revert dropdown if cancelled
                return;
            }

            const authToken = getAuthToken();
            if (!authToken) {
                selectElement.value = currentStatus; // Revert
                return;
            }

            // Disable select while processing to prevent rapid changes
            selectElement.disabled = true;
            displayFeedback('Updating user status...', 'info');

            fetch(`${APP_CONFIG.baseApiUrl}/company/users/${userId}/status`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + authToken,
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ status: newStatus })
            })
            .then(response => {
                if (response.status === 401) { throw new Error('Session expired.'); }
                return response.json().then(result => ({ ok: response.ok, status: response.status, result }));
            })
            .then(({ ok, status, result }) => {
                if (!ok) {
                    let errorMsg = result.message || result.error || `Failed to update status: ${status}`;
                    if (result.errors) {
                        errorMsg += ` Details: ${Object.values(result.errors).flat().join(' ')}`;
                    }
                    throw new Error(errorMsg);
                }

                if (result.status === 'success') {
                    displayFeedback(result.message || 'User status updated successfully!', 'success');
                    // Update the data-current-status attribute and the text in the status cell
                    selectElement.dataset.currentStatus = newStatus;
                    // Find the status text cell for this row to update it visually
                    const statusTextCell = selectElement.closest('tr').cells[3]; // Assuming status is the 4th cell (index 3)
                    if (statusTextCell) {
                        statusTextCell.textContent = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);
                    } else { // Fallback to refresh the whole list if cell not found
                        fetchCompanyData();
                    }
                } else {
                    throw new Error(result.message || result.error || 'API indicated failure for status update.');
                }
            })
            .catch(error => {
                console.error('Error updating user status:', error);
                displayFeedback(`Error: ${error.message}`, 'error');
                selectElement.value = currentStatus; // Revert dropdown on error
            })
            .finally(() => {
                selectElement.disabled = false; // Re-enable select
            });
        }
    });

    // --- Add User Form Submission ---
    addUserForm.addEventListener('submit', function(event) {
        event.preventDefault();
        displayFeedback('', 'success'); // Clear previous messages

        // Check user limit again before proceeding
        if (currentCompanyMaxUsers > 0 && currentCompanyUserCount >= currentCompanyMaxUsers) {
            displayFeedback('Cannot add user: User limit reached for your current plan. Please upgrade.', 'error');
            userLimitMessageP.style.display = 'block'; // Ensure message is visible
            showAddUserBtn.disabled = true;
            closeAddUserModal(); // Close modal as action cannot be performed
            return;
        }

        const firstName = document.getElementById('newUserFirstName').value.trim();
        const lastName = document.getElementById('newUserLastName').value.trim();
        const email = document.getElementById('newUserEmail').value.trim();
        const phone = document.getElementById('newUserPhone').value.trim();
        const roleId = newUserRoleIDSelect.value;
        const password = document.getElementById('newUserPassword').value;

        // Client-side validation
        if (!firstName || !lastName || !email || !roleId || !password) {
            displayFeedback('All fields (except optional phone) are required for new user.', 'error');
            return;
        }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            displayFeedback('Invalid email format.', 'error');
            return;
        }
        if (password.length < 8) {
            displayFeedback('Password must be at least 8 characters long.', 'error');
            return;
        }

        const payload = { firstName, lastName, email, phoneNumber: phone, roleId, password };
        const saveButton = document.getElementById('save-new-user-btn');
        const originalButtonText = saveButton.textContent;
        saveButton.textContent = 'Adding User...';
        saveButton.disabled = true;

        const authToken = getAuthToken();
        if (!authToken) {
            saveButton.textContent = originalButtonText;
            saveButton.disabled = false;
            return; /* getAuthToken already calls displayFeedback */
        }

        fetch(APP_CONFIG.baseApiUrl + '/company/users', {
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
            return response.json().then(result => ({ ok: response.ok, status: response.status, result }));
        })
        .then(({ ok, status, result }) => {
            if (!ok) { // HTTP error status (400, 403, 409, 500 etc.)
                let errorMsg = result.message || result.error || `Failed to add user: ${status}`;
                if (result.errors) { // Field specific errors
                    const fieldErrors = Object.values(result.errors).flat().join(' ');
                    errorMsg += ` Details: ${fieldErrors}`;
                }
                throw new Error(errorMsg);
            }
            // If response.ok (e.g. 201 Created or 200 OK)
            if (result.status === 'success') {
                displayFeedback(result.message || 'User added successfully!', 'success');
                closeAddUserModal();
                fetchCompanyData(); // Refresh user list and counts
            } else { // Should not happen if HTTP was ok and API follows standard
                 throw new Error(result.message || result.error || 'API indicated failure but HTTP status was OK.');
            }
        })
        .catch(error => {
            console.error('Error adding user:', error);
            displayFeedback(`Error: ${error.message}`, 'error');
        })
        .finally(() => {
            saveButton.textContent = originalButtonText;
            saveButton.disabled = false;
        });
    });
});
</script>

<?php
require_once __DIR__ . '/../templates/footer.php';
?>
