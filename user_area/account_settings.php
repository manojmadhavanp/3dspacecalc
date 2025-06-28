<?php
require_once 'check_session.php'; // Ensures user is logged in
$pageTitle = "Account Settings";
require_once __DIR__ . '/../templates/header.php'; // Provides APP_CONFIG
?>

<style>
    .settings-container { max-width: 700px; margin: 20px auto; }
    .settings-section { background-color: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 30px; }
    .settings-section h3 { margin-top: 0; color: #007bff; border-bottom: 1px solid #eee; padding-bottom: 10px; }
    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
    .form-group input[type="text"],
    .form-group input[type="email"],
    .form-group input[type="tel"],
    .form-group input[type="password"] {
        width: calc(100% - 22px); /* Account for padding and border */
        padding: 10px;
        border: 1px solid #ccc;
        border-radius: 4px;
    }
    .form-group input[readonly] { background-color: #e9ecef; cursor: not-allowed; }
    #profile-feedback-message, #password-feedback-message { margin-top: 15px; display: none; }
</style>

<div class="settings-container">
    <h2 class="page-title"><?php echo htmlspecialchars($pageTitle); ?></h2>

    <!-- Update Profile Section -->
    <div class="settings-section">
        <h3>Update Your Profile</h3>
        <div id="profile-feedback-message" class="message"></div>
        <form id="update-profile-form">
            <div class="form-group">
                <label for="profile-email">Email (Cannot be changed)</label>
                <input type="email" id="profile-email" name="email" readonly>
            </div>
            <div class="form-group">
                <label for="profile-firstName">First Name:</label>
                <input type="text" id="profile-firstName" name="firstName" required>
            </div>
            <div class="form-group">
                <label for="profile-lastName">Last Name:</label>
                <input type="text" id="profile-lastName" name="lastName" required>
            </div>
            <div class="form-group">
                <label for="profile-phone">Phone Number:</label>
                <input type="tel" id="profile-phone" name="phone">
            </div>
            <button type="submit" id="update-profile-btn" class="button">Update Profile</button>
        </form>
    </div>

    <!-- Change Password Section -->
    <div class="settings-section">
        <h3>Change Your Password</h3>
        <div id="password-feedback-message" class="message"></div>
        <form id="change-password-form">
            <div class="form-group">
                <label for="currentPassword">Current Password:</label>
                <input type="password" id="currentPassword" name="currentPassword" required>
            </div>
            <div class="form-group">
                <label for="newPassword">New Password (min. 8 characters):</label>
                <input type="password" id="newPassword" name="newPassword" required minlength="8">
            </div>
            <div class="form-group">
                <label for="confirmNewPassword">Confirm New Password:</label>
                <input type="password" id="confirmNewPassword" name="confirmNewPassword" required minlength="8">
            </div>
            <button type="submit" id="change-password-btn" class="button">Change Password</button>
        </form>
    </div>
</div>

<script>
// JavaScript for Account Settings will be added in the next steps
document.addEventListener('DOMContentLoaded', function() {
    if (typeof APP_CONFIG === 'undefined' || !APP_CONFIG.baseApiUrl) {
        console.error('APP_CONFIG with baseApiUrl is not defined.');
        // Display a general error on the page if needed
        const profileFeedback = document.getElementById('profile-feedback-message');
        if(profileFeedback) {
            profileFeedback.textContent = 'Application configuration error. Features may not work.';
            profileFeedback.className = 'message error-message';
            profileFeedback.style.display = 'block';
        }
        return;
    }

    // Placeholder for fetching current user data to populate profile form
    // This would typically call an API like GET APP_CONFIG.baseApiUrl + '/user/profile'
    function loadUserProfile() {
        const authToken = localStorage.getItem('authToken');
        if (!authToken) {
            // Handle not authenticated - though check_session.php should prevent this page load
            console.warn("No auth token found for loading profile.");
            return;
        }

        // Example: Assuming API endpoint /user/me or /user/profile to get current user data
        // fetch(APP_CONFIG.baseApiUrl + '/user/profile', { /* ... headers with auth ... */ })
        // .then(response => response.json())
        // .then(data => {
        //     if (data.success && data.user) {
        //         document.getElementById('profile-email').value = data.user.email || '';
        //         document.getElementById('profile-firstName').value = data.user.firstName || '';
        //         document.getElementById('profile-lastName').value = data.user.lastName || '';
        //         document.getElementById('profile-phone').value = data.user.phone || data.user.phoneNumber || '';
        //     } else {
        //          console.error("Failed to load user profile", data.error || data.message);
        //     }
        // })
        // .catch(error => console.error('Error loading user profile:', error));

        // For now, using placeholder or data potentially stored during login if API structure known
        // If user data was stored in localStorage after login:
        const storedUserData = JSON.parse(localStorage.getItem('userData')); // Assuming 'userData' is stored on login
        if (storedUserData) {
            document.getElementById('profile-email').value = storedUserData.email || '';
            document.getElementById('profile-firstName').value = storedUserData.firstName || '';
            document.getElementById('profile-lastName').value = storedUserData.lastName || '';
            document.getElementById('profile-phone').value = storedUserData.phone || storedUserData.phoneNumber || '';
        } else {
            // Fallback if no data - user will have to fill it or it means login API needs to return this.
            // The email might be non-editable and always fetched.
            // For this placeholder, we'll assume the user might have to fill some fields
            // or that the login API needs to provide these if they are to be pre-filled.
            console.warn("No 'userData' found in localStorage to pre-fill profile. API call needed.");
            // A dedicated API call for user profile is better.
            // For now, let's assume the email can be pre-filled if we have a way to get it.
            // The token itself might contain some info (if JWT), or a /me endpoint is standard.
        }
    }
    loadUserProfile(); // Call on page load

    // --- Update Profile Form Submission ---
    const updateProfileForm = document.getElementById('update-profile-form');
    const updateProfileBtn = document.getElementById('update-profile-btn');
    const profileFeedbackDiv = document.getElementById('profile-feedback-message');

    updateProfileForm.addEventListener('submit', function(event) {
        event.preventDefault();
        profileFeedbackDiv.textContent = '';
        profileFeedbackDiv.style.display = 'none';

        const firstName = document.getElementById('profile-firstName').value.trim();
        const lastName = document.getElementById('profile-lastName').value.trim();
        const phone = document.getElementById('profile-phone').value.trim();

        if (!firstName || !lastName) {
            profileFeedbackDiv.textContent = 'First Name and Last Name are required.';
            profileFeedbackDiv.className = 'message error-message';
            profileFeedbackDiv.style.display = 'block';
            return;
        }

        const payload = { firstName, lastName, phone };
        const originalButtonText = updateProfileBtn.textContent;
        updateProfileBtn.textContent = 'Updating...';
        updateProfileBtn.disabled = true;

        const authToken = localStorage.getItem('authToken');
        if (!authToken) {
            profileFeedbackDiv.textContent = 'Authentication error. Please login again.';
            profileFeedbackDiv.className = 'message error-message';
            profileFeedbackDiv.style.display = 'block';
            updateProfileBtn.textContent = originalButtonText;
            updateProfileBtn.disabled = false;
            return;
        }

        // Assuming API endpoint is /user/profile or similar for the logged-in user
        fetch(APP_CONFIG.baseApiUrl + '/user/profile', {
            method: 'PUT', // Or POST, depending on API design
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + authToken,
                'Accept': 'application/json'
            },
            body: JSON.stringify(payload)
        })
        .then(response => {
            if (response.status === 401) { throw new Error('Session expired. Please login again.'); }
            if (!response.ok) {
                return response.json().then(errData => {
                    throw new Error(errData.error || errData.message || `Failed to update profile: ${response.status}`);
                }).catch(() => new Error(`Failed to update profile: ${response.status} ${response.statusText}`));
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                profileFeedbackDiv.textContent = data.message || 'Profile updated successfully!';
                profileFeedbackDiv.className = 'message success-message';
                profileFeedbackDiv.style.display = 'block';

                // Optionally, update localStorage if API returns updated user data and header needs it
                if (data.user) { // Assuming API might return updated user object
                    // Update stored user data if any (e.g., if first/last name is shown in header)
                    // This part depends on how user data is managed client-side beyond just the token
                    const currentUserData = JSON.parse(localStorage.getItem('userData')) || {};
                    const updatedUserData = { ...currentUserData, ...data.user };
                    localStorage.setItem('userData', JSON.stringify(updatedUserData));
                    // If header displays name from localStorage, dispatch authChange to potentially refresh it
                    window.dispatchEvent(new CustomEvent('authChange', { detail: { user: updatedUserData } }));
                }

            } else {
                throw new Error(data.error || data.message || 'Failed to update profile.');
            }
        })
        .catch(error => {
            profileFeedbackDiv.textContent = `Error: ${error.message}`;
            profileFeedbackDiv.className = 'message error-message';
            profileFeedbackDiv.style.display = 'block';
        })
        .finally(() => {
            updateProfileBtn.textContent = originalButtonText;
            updateProfileBtn.disabled = false;
        });
    });


    // --- Change Password Form Submission ---
    const changePasswordForm = document.getElementById('change-password-form');
    const changePasswordBtn = document.getElementById('change-password-btn');
    const passwordFeedbackDiv = document.getElementById('password-feedback-message');

    changePasswordForm.addEventListener('submit', function(event) {
        event.preventDefault();
        passwordFeedbackDiv.textContent = '';
        passwordFeedbackDiv.style.display = 'none';

        const currentPassword = document.getElementById('currentPassword').value;
        const newPassword = document.getElementById('newPassword').value;
        const confirmNewPassword = document.getElementById('confirmNewPassword').value;

        // Client-side validation
        if (!currentPassword || !newPassword || !confirmNewPassword) {
            passwordFeedbackDiv.textContent = 'All password fields are required.';
            passwordFeedbackDiv.className = 'message error-message';
            passwordFeedbackDiv.style.display = 'block';
            return;
        }
        if (newPassword.length < 8) {
            passwordFeedbackDiv.textContent = 'New password must be at least 8 characters long.';
            passwordFeedbackDiv.className = 'message error-message';
            passwordFeedbackDiv.style.display = 'block';
            return;
        }
        if (newPassword !== confirmNewPassword) {
            passwordFeedbackDiv.textContent = 'New passwords do not match.';
            passwordFeedbackDiv.className = 'message error-message';
            passwordFeedbackDiv.style.display = 'block';
            return;
        }

        const payload = { currentPassword, newPassword }; // API might just need newPassword if old one verified by session/token context
                                                        // Or it might require currentPassword for verification. Assuming it needs current.
        const originalButtonText = changePasswordBtn.textContent;
        changePasswordBtn.textContent = 'Changing...';
        changePasswordBtn.disabled = true;

        const authToken = localStorage.getItem('authToken');
        if (!authToken) {
            passwordFeedbackDiv.textContent = 'Authentication error. Please login again.';
            passwordFeedbackDiv.className = 'message error-message';
            passwordFeedbackDiv.style.display = 'block';
            changePasswordBtn.textContent = originalButtonText;
            changePasswordBtn.disabled = false;
            return;
        }

        // Assuming API endpoint is /user/password/change or similar
        fetch(APP_CONFIG.baseApiUrl + '/user/password/change', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + authToken,
                'Accept': 'application/json'
            },
            body: JSON.stringify(payload)
        })
        .then(response => {
            if (response.status === 401) { throw new Error('Session expired. Please login again.'); }
            if (!response.ok) {
                return response.json().then(errData => {
                    throw new Error(errData.error || errData.message || `Failed to change password: ${response.status}`);
                }).catch(() => new Error(`Failed to change password: ${response.status} ${response.statusText}`));
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                passwordFeedbackDiv.textContent = data.message || 'Password changed successfully! You might need to log in again with your new password.';
                passwordFeedbackDiv.className = 'message success-message';
                passwordFeedbackDiv.style.display = 'block';
                changePasswordForm.reset(); // Clear the form
                // Consider forcing logout or session refresh if API doesn't handle it.
                // localStorage.removeItem('authToken');
                // window.dispatchEvent(new CustomEvent('authChange'));
                // alert("Password changed. Please log in again.");
                // window.location.href = APP_CONFIG.baseUrl + '/login';
            } else {
                throw new Error(data.error || data.message || 'Failed to change password.');
            }
        })
        .catch(error => {
            passwordFeedbackDiv.textContent = `Error: ${error.message}`;
            passwordFeedbackDiv.className = 'message error-message';
            passwordFeedbackDiv.style.display = 'block';
        })
        .finally(() => {
            changePasswordBtn.textContent = originalButtonText;
            changePasswordBtn.disabled = false;
        });
    });
});
</script>

<?php
require_once __DIR__ . '/../templates/footer.php';
?>
