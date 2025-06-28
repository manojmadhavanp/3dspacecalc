<?php
// This file is now a frontend view.
if (!defined('BASE_URL')) {
    // Adjust path if this file moves, e.g., to a 'views/auth/' subdirectory
    require_once __DIR__ . '/../config.php';
}
$pageTitle = "Reset Password";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="/css/style.css">
    <?php
        // header_website.php will provide APP_CONFIG
        require_once 'templates/header_website.php';
    ?>
</head>
<?php /* Body tag is opened in header_website.php, add class for styling */ ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        if (!document.body.classList.contains('login-page-body')) {
            document.body.classList.add('login-page-body');
        }
    });
</script>
<body class="login-page-body">
    <div class="login-page-wrapper">
        <div class="animated-blob blob1"></div>
        <div class="animated-blob blob2"></div>
        <div class="animated-blob blob3"></div>

        <div class="container login-form-container" style="max-width: 400px; margin: 50px auto;">
            <h2>Reset Your Password</h2>
            <div id="reset-password-feedback" class="message" style="display: none;"></div>

        <form id="reset-password-form">
            <input type="hidden" id="reset-token" name="token" value="">

            <div class="form-group">
                <label for="new_password">New Password (min. 8 characters):</label>
                <input type="password" id="new_password" name="new_password" required minlength="8">
            </div>
            <div class="form-group">
                <label for="confirm_password">Confirm New Password:</label>
                <input type="password" id="confirm_password" name="confirm_password" required minlength="8">
            </div>
            <div style="margin-top:15px;">
                <button type="submit" id="reset-password-btn">Reset Password</button>
            </div>
        </form>
        <div id="login-link-container" style="text-align: center; margin-top: 15px; display:none;">
            <p><a href="<?php echo rtrim(BASE_URL, '/'); ?>/login">Proceed to Login</a></p>
        </div>
         <div class="login-link" style="text-align: center; margin-top: 20px;">
            <p><a href="<?php echo rtrim(BASE_URL, '/'); ?>/login">Back to Login</a> (if you don't have a token)</p>
        </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof APP_CONFIG === 'undefined' || !APP_CONFIG.baseApiUrl) {
        console.error('APP_CONFIG (baseApiUrl) is not defined.');
        const feedbackDiv = document.getElementById('reset-password-feedback');
        feedbackDiv.textContent = 'Application configuration error.';
        feedbackDiv.className = 'message error-message';
        feedbackDiv.style.display = 'block';
        document.getElementById('reset-password-form').style.display = 'none'; // Hide form
        return;
    }

    const resetPasswordForm = document.getElementById('reset-password-form');
    const resetButton = document.getElementById('reset-password-btn');
    const feedbackDiv = document.getElementById('reset-password-feedback');
    const tokenInput = document.getElementById('reset-token');
    const loginLinkContainer = document.getElementById('login-link-container');

    // Get token from URL query parameter
    const urlParams = new URLSearchParams(window.location.search);
    const tokenFromUrl = urlParams.get('token');

    if (tokenFromUrl) {
        tokenInput.value = tokenFromUrl;
    } else {
        feedbackDiv.textContent = 'Invalid or missing reset token in URL. Please use the link from your email.';
        feedbackDiv.className = 'message error-message';
        feedbackDiv.style.display = 'block';
        resetPasswordForm.style.display = 'none'; // Hide form if no token
    }

    resetPasswordForm.addEventListener('submit', function(event) {
        event.preventDefault();
        feedbackDiv.textContent = '';
        feedbackDiv.style.display = 'none';

        const token = tokenInput.value;
        const newPassword = document.getElementById('new_password').value;
        const confirmPassword = document.getElementById('confirm_password').value;

        // Client-side validation
        if (!token) {
            feedbackDiv.textContent = 'Reset token is missing. Cannot proceed.';
            feedbackDiv.className = 'message error-message';
            feedbackDiv.style.display = 'block';
            return;
        }
        if (!newPassword || !confirmPassword) {
            feedbackDiv.textContent = 'Please fill in both new password fields.';
            feedbackDiv.className = 'message error-message';
            feedbackDiv.style.display = 'block';
            return;
        }
        if (newPassword.length < 8) {
            feedbackDiv.textContent = 'New password must be at least 8 characters long.';
            feedbackDiv.className = 'message error-message';
            feedbackDiv.style.display = 'block';
            return;
        }
        if (newPassword !== confirmPassword) {
            feedbackDiv.textContent = 'New passwords do not match.';
            feedbackDiv.className = 'message error-message';
            feedbackDiv.style.display = 'block';
            return;
        }

        resetButton.textContent = 'Resetting...';
        resetButton.disabled = true;

        fetch(APP_CONFIG.baseApiUrl + '/auth/reset-password', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ token: token, newPassword: newPassword })
        })
        .then(response => {
            return response.json().then(data => ({ ok: response.ok, status: response.status, data }));
        })
        .then(result => {
            if (result.ok && result.data.success) {
                feedbackDiv.textContent = result.data.message || 'Password has been reset successfully. You can now login.';
                feedbackDiv.className = 'message success-message';
                resetPasswordForm.style.display = 'none'; // Hide form on success
                loginLinkContainer.style.display = 'block'; // Show login link
            } else {
                throw new Error(result.data.error || result.data.message || `Password reset failed: ${result.status}`);
            }
        })
        .catch(error => {
            console.error('Reset Password Error:', error);
            feedbackDiv.textContent = error.message || 'An error occurred. Please try again or request a new reset link.';
            feedbackDiv.className = 'message error-message';
        })
        .finally(() => {
            resetButton.textContent = 'Reset Password';
            resetButton.disabled = false;
            feedbackDiv.style.display = 'block';
        });
    });
});
</script>

<?php require_once 'templates/footer_website.php'; // Use website footer ?>
