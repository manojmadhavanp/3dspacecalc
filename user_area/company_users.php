<?php
require_once 'check_session.php'; // Ensures user is logged in, provides $user_first_name
require_once __DIR__ . '/../db_connect.php'; // Ensures $conn is available, and generateUUID()

$pageTitle = "Manage Company Users";
require_once __DIR__ . '/../templates/header.php';

$feedback_message = '';
$feedback_type = ''; // 'success' or 'error'

// --- Authorization Check: Only Company Admins can manage users ---
$isCompanyAdmin = false;
if (isset($_SESSION['user_role_ruid']) && $_SESSION['user_role_ruid'] === 'COMPANY_ADMIN_ROLE_UID') {
    $isCompanyAdmin = true;
}

if (!$isCompanyAdmin) {
    echo "<div class='container'><p class='message error-message'>You do not have permission to manage company users. Please contact your company administrator.</p></div>";
    require_once __DIR__ . '/../templates/footer.php';
    exit;
}

// Get current user's CompanyID and subscription details
$currentCompanyID = null;
$maxUsersAllowed = 0;
$currentUserCount = 0;
$companyPackageName = 'N/A';

if (isset($_SESSION['user_uid'])) {
    $user_uid = $_SESSION['user_uid']; // This is the admin's UID
    $stmt_company_info = $conn->prepare(
        "SELECT u.CompanyID, cs.PackageName, cs.MaxUsers
         FROM users u
         JOIN company_subscription cs ON u.CompanyID = cs.CompanyID
         WHERE u.UID = ?"
    );
    if ($stmt_company_info) {
        $stmt_company_info->bind_param("i", $user_uid);
        $stmt_company_info->execute();
        $result_company_info = $stmt_company_info->get_result();
        if ($company_info = $result_company_info->fetch_assoc()) {
            $currentCompanyID = $company_info['CompanyID'];
            $maxUsersAllowed = (int)$company_info['MaxUsers'];
            $companyPackageName = $company_info['PackageName'];
        } else {
            $feedback_message = "Error: Could not retrieve your company or subscription details.";
            $feedback_type = 'error';
        }
        $stmt_company_info->close();
    } else {
        $feedback_message = "Database error (company/sub prepare): " . $conn->error;
        $feedback_type = 'error';
        error_log("Error preparing to get company/sub details for admin UID {$user_uid}: " . $conn->error);
    }
} else {
    // Should not happen if check_session is working
    $feedback_message = "Error: User session not found.";
    $feedback_type = 'error';
}

// --- Handle Form Submissions (Add/Edit User) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $currentCompanyID && $isCompanyAdmin) {
    $action_user = $_POST['action_user'] ?? '';

    if ($action_user === 'add_user') {
        $newUserFirstName = trim($_POST['newUserFirstName'] ?? '');
        $newUserLastName = trim($_POST['newUserLastName'] ?? '');
        $newUserEmail = trim($_POST['newUserEmail'] ?? '');
        $newUserPhone = trim($_POST['newUserPhone'] ?? '');
        $newUserRoleID = (int)($_POST['newUserRoleID'] ?? 0); // Make sure this role is valid for the company
        $newUserPassword = $_POST['newUserPassword'] ?? '';

        // Fetch current user count before adding
        $stmt_count = $conn->prepare("SELECT COUNT(UID) as UserCount FROM users WHERE CompanyID = ?");
        if ($stmt_count) {
            $stmt_count->bind_param("i", $currentCompanyID);
            $stmt_count->execute();
            $count_result = $stmt_count->get_result()->fetch_assoc();
            $currentUserCount = (int)$count_result['UserCount'];
            $stmt_count->close();
        } else {
            $feedback_message = "Error checking current user count: " . $conn->error;
            $feedback_type = 'error';
        }


        if (empty($newUserFirstName) || empty($newUserLastName) || empty($newUserEmail) || empty($newUserRoleID) || empty($newUserPassword)) {
            $feedback_message = "All fields for the new user are required.";
            $feedback_type = 'error';
        } elseif (!filter_var($newUserEmail, FILTER_VALIDATE_EMAIL)) {
            $feedback_message = "Invalid email format for the new user.";
            $feedback_type = 'error';
        } elseif (strlen($newUserPassword) < 8) {
            $feedback_message = "Password must be at least 8 characters long.";
            $feedback_type = 'error';
        } elseif ($maxUsersAllowed > 0 && $currentUserCount >= $maxUsersAllowed) {
            $feedback_message = "Cannot add new user. Your company has reached the maximum of {$maxUsersAllowed} users for the '{$companyPackageName}' plan. Please <a href='../pricing.php'>upgrade your plan</a>.";
            $feedback_type = 'error';
        } else {
            // Check if email or phone already exists
            $stmt_check = $conn->prepare("SELECT UID FROM users WHERE Email = ? OR PhoneNumber = ?");
            if ($stmt_check) {
                $stmt_check->bind_param("ss", $newUserEmail, $newUserPhone);
                $stmt_check->execute();
                if ($stmt_check->get_result()->num_rows > 0) {
                    $feedback_message = "A user with this email or phone number already exists.";
                    $feedback_type = 'error';
                }
                $stmt_check->close();
            } else {
                 $feedback_message = "DB error (user check): " . $conn->error;
                 $feedback_type = 'error';
            }

            if ($feedback_type !== 'error') { // Proceed if no errors so far
                $conn->begin_transaction();
                try {
                    $userUUID = generateUUID();
                    $stmt_add_user = $conn->prepare("INSERT INTO users (UUID, FirstName, LastName, Email, PhoneNumber, CompanyID, RID) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    if (!$stmt_add_user) throw new Exception("User insert prepare failed: " . $conn->error);
                    $stmt_add_user->bind_param("sssssii", $userUUID, $newUserFirstName, $newUserLastName, $newUserEmail, $newUserPhone, $currentCompanyID, $newUserRoleID);
                    if (!$stmt_add_user->execute()) throw new Exception("User insert execution failed: " . $stmt_add_user->error);
                    $newlyAddedUserID = $stmt_add_user->insert_id;
                    $stmt_add_user->close();

                    $hashedPassword = password_hash($newUserPassword, PASSWORD_DEFAULT);
                    // New users added by admin are 'active'. Trial status is company-wide.
                    $initialUserStatus = 'active';
                    $stmt_add_auth = $conn->prepare("INSERT INTO auth (UID, PasswordEncrypted, Status) VALUES (?, ?, ?)");
                    if (!$stmt_add_auth) throw new Exception("Auth insert prepare failed: " . $conn->error);
                    $stmt_add_auth->bind_param("iss", $newlyAddedUserID, $hashedPassword, $initialUserStatus);
                    if (!$stmt_add_auth->execute()) throw new Exception("Auth insert execution failed: " . $stmt_add_auth->error);
                    $stmt_add_auth->close();

                    $conn->commit();
                    $feedback_message = "User '" . htmlspecialchars($newUserFirstName . " " . $newUserLastName) . "' added successfully!";
                    $feedback_type = 'success';
                } catch (Exception $e) {
                    $conn->rollback();
                    $feedback_message = "Error adding user: " . $e->getMessage();
                    $feedback_type = 'error';
                    error_log("Add company user error: " . $e->getMessage());
                }
            }
        }
    } elseif ($action_user === 'update_user_status') {
        $userToUpdateUID = (int)($_POST['userToUpdateUID'] ?? 0);
        $newStatus = trim($_POST['newStatus'] ?? ''); // e.g., 'active', 'inactive'

        // Prevent admin from deactivating themselves or the primary company contact (if such logic exists)
        // For simplicity, just check if it's not their own UID
        if ($userToUpdateUID === $_SESSION['user_uid']) {
            $feedback_message = "You cannot change your own status.";
            $feedback_type = 'error';
        } elseif (in_array($newStatus, ['active', 'inactive', 'suspended'])) {
            // Ensure userToUpdateUID belongs to currentCompanyID
            $stmt_check_owner = $conn->prepare("SELECT UID FROM users WHERE UID = ? AND CompanyID = ?");
            if($stmt_check_owner){
                $stmt_check_owner->bind_param("ii", $userToUpdateUID, $currentCompanyID);
                $stmt_check_owner->execute();
                if($stmt_check_owner->get_result()->num_rows == 1){
                    $stmt_update_status = $conn->prepare("UPDATE auth SET Status = ? WHERE UID = ?");
                    if($stmt_update_status){
                        $stmt_update_status->bind_param("si", $newStatus, $userToUpdateUID);
                        if ($stmt_update_status->execute()) {
                            $feedback_message = "User status updated successfully.";
                            $feedback_type = 'success';
                        } else {
                            $feedback_message = "Error updating user status: " . $stmt_update_status->error;
                            $feedback_type = 'error';
                        }
                        $stmt_update_status->close();
                    } else {
                        $feedback_message = "DB error (update status prepare): " . $conn->error;
                        $feedback_type = 'error';
                    }
                } else {
                     $feedback_message = "User not found in your company.";
                     $feedback_type = 'error';
                }
                $stmt_check_owner->close();
            } else {
                $feedback_message = "DB error (check owner prepare): " . $conn->error;
                $feedback_type = 'error';
            }
        } else {
            $feedback_message = "Invalid status value.";
            $feedback_type = 'error';
        }
    }
    // TODO: Implement Edit User Role logic if needed
}


// --- Fetch Data for Display ---
$company_users_list = [];
$available_roles = []; // Roles that can be assigned within a company

if ($currentCompanyID && $isCompanyAdmin) {
    // Fetch users of the current company
    $stmt_users = $conn->prepare(
        "SELECT u.UID, u.FirstName, u.LastName, u.Email, u.PhoneNumber, r.RoleName, a.Status, a.LastLogin
         FROM users u
         JOIN roles r ON u.RID = r.RID
         JOIN auth a ON u.UID = a.UID
         WHERE u.CompanyID = ?
         ORDER BY u.LastName, u.FirstName"
    );
    if ($stmt_users) {
        $stmt_users->bind_param("i", $currentCompanyID);
        $stmt_users->execute();
        $result_users = $stmt_users->get_result();
        while ($row = $result_users->fetch_assoc()) {
            $company_users_list[] = $row;
        }
        $currentUserCount = count($company_users_list); // Update current user count after any additions/deletions
        $stmt_users->close();
    } else {
        $feedback_message = "Error fetching company users: " . $conn->error;
        $feedback_type = 'error';
    }

    // Fetch assignable roles (e.g., 'User', maybe others, but not 'Admin' (system-wide admin))
    // For now, hardcoding 'USER_ROLE_UID' as the assignable one.
    $assignableRoleRUID = 'USER_ROLE_UID';
    $stmt_roles = $conn->prepare("SELECT RID, RoleName FROM roles WHERE RoleRUID = ? OR RoleRUID = 'COMPANY_ADMIN_ROLE_UID'"); // Allow assigning CompanyAdmin too
     if ($stmt_roles) {
        $stmt_roles->bind_param("s", $assignableRoleRUID);
        $stmt_roles->execute();
        $result_roles = $stmt_roles->get_result();
        while ($row = $result_roles->fetch_assoc()) {
            $available_roles[] = $row;
        }
        $stmt_roles->close();
    } else {
        $feedback_message = "Error fetching assignable roles: " . $conn->error;
        $feedback_type = 'error';
    }
}
?>

<style>
    .users-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
    .users-table th, .users-table td { border: 1px solid #ddd; padding: 10px; text-align: left; }
    .users-table th { background-color: #f2f2f2; }
    .form-container { border:1px solid #ddd; padding:20px; border-radius:5px; margin-top:20px; background-color:#f9f9f9; }
    /* Other styles from clients.php can be reused or put in global CSS */
</style>

<h2 class="page-title">Manage Company Users</h2>

<?php if ($feedback_message): ?>
    <div class="message <?php echo ($feedback_type === 'success') ? 'success-message' : 'error-message'; ?>">
        <?php echo $feedback_message; // Already HTML escaped if it came from user input, or is a system message ?>
    </div>
<?php endif; ?>

<?php if ($currentCompanyID && $isCompanyAdmin): ?>
    <div style="margin-bottom: 20px; padding: 10px; background-color: #eef; border-radius: 5px;">
        <p><strong>Company:</strong> <?php /* We don't have company name here directly, could fetch if needed */ echo "Your Company"; ?></p>
        <p><strong>Current Plan:</strong> <?php echo htmlspecialchars($companyPackageName); ?></p>
        <p><strong>User Limit:</strong> <?php echo $currentUserCount; ?> / <?php echo ($maxUsersAllowed > 0) ? $maxUsersAllowed : 'Unlimited'; ?> Users</p>
        <?php if ($maxUsersAllowed > 0 && $currentUserCount >= $maxUsersAllowed): ?>
            <p style="color:red;">You have reached your user limit. <a href="../pricing.php">Upgrade Plan</a> to add more users.</p>
        <?php endif; ?>
    </div>

    <?php if (($maxUsersAllowed === 0 || $currentUserCount < $maxUsersAllowed)): ?>
    <div class="form-container">
        <form action="company_users.php" method="POST">
            <input type="hidden" name="action_user" value="add_user">
            <fieldset>
                <legend>Add New User</legend>
                <div><label for="newUserFirstName">First Name:</label><input type="text" name="newUserFirstName" required></div>
                <div><label for="newUserLastName">Last Name:</label><input type="text" name="newUserLastName" required></div>
                <div><label for="newUserEmail">Email:</label><input type="email" name="newUserEmail" required></div>
                <div><label for="newUserPhone">Phone Number:</label><input type="tel" name="newUserPhone"></div>
                <div>
                    <label for="newUserRoleID">Role:</label>
                    <select name="newUserRoleID" required style="padding:10px; width:100%; margin-bottom:15px;">
                        <option value="">-- Select Role --</option>
                        <?php foreach($available_roles as $role):
                            // Typically, don't allow assigning system 'Admin' role from here.
                            if ($role['RoleName'] !== 'Admin') {
                        ?>
                            <option value="<?php echo $role['RID']; ?>"><?php echo htmlspecialchars($role['RoleName']); ?></option>
                        <?php } endforeach; ?>
                    </select>
                </div>
                <div><label for="newUserPassword">Set Initial Password (min 8 chars):</label><input type="password" name="newUserPassword" required minlength="8"></div>
                <button type="submit">Add User</button>
            </fieldset>
        </form>
    </div>
    <?php endif; ?>

    <h3 style="margin-top:30px;">Existing Company Users (<?php echo $currentUserCount; ?>)</h3>
    <?php if (!empty($company_users_list)): ?>
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
        <tbody>
            <?php foreach ($company_users_list as $user): ?>
            <tr>
                <td><?php echo htmlspecialchars($user['FirstName'] . " " . $user['LastName']); ?></td>
                <td><?php echo htmlspecialchars($user['Email']); ?><br><?php echo htmlspecialchars($user['PhoneNumber'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($user['RoleName']); ?></td>
                <td><?php echo htmlspecialchars(ucfirst($user['Status'])); ?></td>
                <td><?php echo $user['LastLogin'] ? date('Y-m-d H:i', strtotime($user['LastLogin'])) : 'Never'; ?></td>
                <td>
                    <?php if ($user['UID'] !== $_SESSION['user_uid']): // Admin cannot change their own status here ?>
                    <form action="company_users.php" method="POST" style="display:inline;">
                        <input type="hidden" name="action_user" value="update_user_status">
                        <input type="hidden" name="userToUpdateUID" value="<?php echo $user['UID']; ?>">
                        <select name="newStatus" onchange="this.form.submit()" style="padding:5px;">
                            <option value="active" <?php if($user['Status'] === 'active') echo 'selected'; ?>>Active</option>
                            <option value="inactive" <?php if($user['Status'] === 'inactive') echo 'selected'; ?>>Inactive</option>
                            <option value="suspended" <?php if($user['Status'] === 'suspended') echo 'selected'; ?>>Suspended</option>
                        </select>
                        noscript(<button type="submit">Set Status</button>)
                    </form>
                    <!-- Add Edit Role button/form here if needed -->
                    <!-- Add Delete User button/form here if needed (careful with this!) -->
                    <?php else: echo " (Your Account)"; endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
        <p>No other users found in your company yet.</p>
    <?php endif; ?>

<?php elseif (!$isCompanyAdmin): ?>
    <?php /* Message already shown at the top */ ?>
<?php else: ?>
    <p class="message error-message">Could not load company user management details. <?php echo htmlspecialchars($feedback_message); ?></p>
<?php endif; ?>

<?php
$conn->close();
require_once __DIR__ . '/../templates/footer.php';
?>
