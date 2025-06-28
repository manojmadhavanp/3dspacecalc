<?php
// This file is now a frontend view.
// config.php might be included by router_index.php if this view is routed.
// If accessed directly (should not happen with routing), ensure BASE_URL is available for links.
if (!defined('BASE_URL')) {
    // Adjust path if this file moves, e.g., to a 'views/auth/' subdirectory
    require_once __DIR__ . '/../config.php';
}
$pageTitle = "Forgot Password";
// No full header/footer if this is a standalone page for now, or it can be wrapped by router.
// For consistency with login/register, let's assume it's a full page view for now.
// If it's part of a larger SPA shell, header/footer would be managed differently.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="/css/style.css"> <!-- Assuming global style.css -->
    <script>
        // Make config available to JavaScript if header.php isn't included
        // This would typically be handled by including templates/header.php
        if (typeof APP_CONFIG === 'undefined') {
            window.APP_CONFIG = {
                baseUrl: '<?php echo rtrim(BASE_URL, '/'); ?>',
                baseApiUrl: '<?php echo rtrim(BASE_API_URL, '/'); ?>'
            };
        }
    </script>
</head>
<body>
    <div class="container" style="max-width: 400px; margin: 50px auto;">
        <h2>Forgot Your Password?</h2>
        <p style="text-align:center; color: #555; margin-bottom:20px;">Enter your email address and we will send you a link to reset your password (if an account exists).</p>

        <div id="forgot-password-message-placeholder">
            <?php
            // For any old messages passed via URL (e.g., from direct access before full SPA conversion)
            if (isset($_GET['error'])) {
                echo '<p class="message error-message">' . htmlspecialchars($_GET['error']) . '</p>';
            }
            if (isset($_GET['success'])) {
                echo '<p class="message success-message">' . htmlspecialchars($_GET['success']) . '</p>';
            }
            // DEV INFO - Token info display removed as it's now API handled
            ?>
        </div>
        <div id="forgot-password-feedback" class="message" style="display: none;"></div>

        <form id="forgot-password-form">
            <div>
                <label for="email">Your Email Address:</label>
                <input type="email" id="email" name="email" required>
            </div>
            <div style="margin-top:15px;">
                <button type="submit" id="send-reset-link-btn">Send Reset Link</button>
            </div>
        </form>
        <div class="login-link" style="text-align: center; margin-top: 15px;">
            <p><a href="<?php echo rtrim(BASE_URL, '/'); ?>/login">Back to Login</a></p>
        </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof APP_CONFIG === 'undefined' || !APP_CONFIG.baseApiUrl) {
        console.error('APP_CONFIG (baseApiUrl) is not defined.');
        const feedbackDiv = document.getElementById('forgot-password-feedback');
        feedbackDiv.textContent = 'Application configuration error.';
        feedbackDiv.className = 'message error-message';
        feedbackDiv.style.display = 'block';
        return;
    }

    const forgotPasswordForm = document.getElementById('forgot-password-form');
    const sendButton = document.getElementById('send-reset-link-btn');
    const feedbackDiv = document.getElementById('forgot-password-feedback');
    const oldMessagesDiv = document.getElementById('forgot-password-message-placeholder');

    forgotPasswordForm.addEventListener('submit', function(event) {
        event.preventDefault();
        feedbackDiv.textContent = '';
        feedbackDiv.style.display = 'none';
        if(oldMessagesDiv) oldMessagesDiv.innerHTML = '';


        const email = document.getElementById('email').value.trim();

        if (!email) {
            feedbackDiv.textContent = 'Please enter your email address.';
            feedbackDiv.className = 'message error-message';
            feedbackDiv.style.display = 'block';
            return;
        }
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(email)) {
            feedbackDiv.textContent = 'Please enter a valid email address.';
            feedbackDiv.className = 'message error-message';
            feedbackDiv.style.display = 'block';
            return;
        }

        sendButton.textContent = 'Sending...';
        sendButton.disabled = true;

        fetch(APP_CONFIG.baseApiUrl + '/auth/forgot-password', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ email: email })
        })
        .then(response => {
            // API should ideally always return JSON, even for errors, if possible.
            // If it might not, more robust error checking of response type is needed.
            return response.json().then(data => ({ ok: response.ok, status: response.status, data }));
        })
        .then(result => {
            // Backend API should always return a generic success message to prevent email enumeration.
            // The actual sending of email happens on backend.
            if (result.data.success || result.ok) { // Check data.success or just HTTP ok
                feedbackDiv.textContent = result.data.message || 'If an account with that email exists, a password reset link has been sent. Please check your inbox (and spam folder).';
                feedbackDiv.className = 'message success-message';
                forgotPasswordForm.reset(); // Clear the email field
            } else {
                // Use error from API if available, otherwise a generic one
                throw new Error(result.data.error || result.data.message || `Request failed with status: ${result.status}`);
            }
        })
        .catch(error => {
            console.error('Forgot Password Error:', error);
            feedbackDiv.textContent = error.message || 'An error occurred. Please try again.';
            feedbackDiv.className = 'message error-message';
        })
        .finally(() => {
            sendButton.textContent = 'Send Reset Link';
            sendButton.disabled = false;
            feedbackDiv.style.display = 'block';
        });
    });
});
</script>

</body>
</html>
