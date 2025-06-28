<?php
// This file is now a frontend view.
// config.php is included by header_website.php which provides BASE_URL and APP_CONFIG for JS.
$pageTitle = "Forgot Password";
require_once 'templates/header_website.php'; // Use website header
?>
<?php /* Body tag is opened in header_website.php, we add a class to it for specific page styling */ ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Add login-page-body class if not already on body from header_website.php logic for auth pages
        if (!document.body.classList.contains('login-page-body')) {
            document.body.classList.add('login-page-body');
        }
    });
</script>

<div class="login-page-wrapper"> {/* For background effects, same as login.php */}
    <div class="animated-blob blob1"></div>
    <div class="animated-blob blob2"></div>
    <div class="animated-blob blob3"></div>

    <div class="container login-form-container"> {/* Use same container style as login.php */}
        <h2>Forgot Your Password?</h2>
        <p style="text-align:center; margin-bottom:20px; color: #e0e7ff;">Enter your email address. If an account exists for that email, a password reset link will be sent.</p>

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
        <div class="register-link" style="text-align: center; margin-top: 15px;">
            <p><a href="<?php echo rtrim(BASE_URL, '/'); ?>/login">Back to Login</a></p>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // APP_CONFIG is expected to be defined from header_website.php
    if (typeof APP_CONFIG === 'undefined' || !APP_CONFIG.baseApiUrl) {
        console.error('APP_CONFIG (baseApiUrl) is not defined for forgot password form.');
        const feedbackDiv = document.getElementById('forgot-password-feedback');
        if(feedbackDiv) {
            feedbackDiv.textContent = 'Application configuration error.';
            feedbackDiv.className = 'message error-message';
            feedbackDiv.style.display = 'block';
        }
        const submitBtn = document.getElementById('send-reset-link-btn');
        if(submitBtn) submitBtn.disabled = true;
        return;
    }

    const forgotPasswordForm = document.getElementById('forgot-password-form');
    const sendButton = document.getElementById('send-reset-link-btn');
    const feedbackDiv = document.getElementById('forgot-password-feedback');

    forgotPasswordForm.addEventListener('submit', function(event) {
        event.preventDefault();
        feedbackDiv.textContent = '';
        feedbackDiv.style.display = 'none';

        const email = document.getElementById('email').value.trim();

        if (!email) {
            feedbackDiv.textContent = 'Please enter your email address.';
            feedbackDiv.className = 'message error-message';
            feedbackDiv.style.display = 'block';
            document.getElementById('email').focus();
            return;
        }
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(email)) {
            feedbackDiv.textContent = 'Please enter a valid email address.';
            feedbackDiv.className = 'message error-message';
            feedbackDiv.style.display = 'block';
            document.getElementById('email').focus();
            return;
        }

        sendButton.textContent = 'Sending...';
        sendButton.disabled = true;

        // Using raw fetch because this is a public, unauthenticated action.
        fetch(APP_CONFIG.baseApiUrl + '/auth/forgot-password', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ email: email })
        })
        .then(response => {
            // Even for errors, backend should ideally return JSON with the standard structure.
            return response.json().then(data => ({ ok: response.ok, status: response.status, data }));
        })
        .then(result => {
            // Standardized: API should always return a generic success message to prevent email enumeration.
            // The actual sending of email happens on backend.
            // Success is determined by data.status === 'success' or just HTTP ok if API is designed that way for this public endpoint.
            if (result.data && result.data.status === 'success') {
                feedbackDiv.textContent = result.data.message || 'If an account with that email exists, a password reset link has been sent. Please check your inbox (and spam folder).';
                feedbackDiv.className = 'message success-message'; // Always show as success to user
                forgotPasswordForm.reset();
            } else if (result.ok && !result.data.status) { // HTTP OK, but no specific status: 'success' in body (e.g. simple 200 OK response)
                 feedbackDiv.textContent = 'If an account with that email exists, a password reset link has been sent. Please check your inbox (and spam folder).';
                feedbackDiv.className = 'message success-message';
                forgotPasswordForm.reset();
            }
            else { // API indicated failure or unexpected structure
                throw new Error(result.data.message || result.data.error || `Request failed. Please try again.`);
            }
        })
        .catch(error => {
            console.error('Forgot Password Error:', error);
            // Still show a generic-like success message on client for actual errors to avoid confirming email non-existence to malicious users.
            // The real error is logged to console for dev/admin.
            feedbackDiv.textContent = 'If an account with that email exists, a password reset link has been sent. Please check your inbox (and spam folder). (If issues persist, contact support).';
            feedbackDiv.className = 'message success-message'; // Intentionally show as success to user
        })
        .finally(() => {
            sendButton.textContent = 'Send Reset Link';
            sendButton.disabled = false;
            feedbackDiv.style.display = 'block';
        });
    });
});
</script>

<?php require_once 'templates/footer_website.php'; ?>
