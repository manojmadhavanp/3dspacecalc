<?php
if (!defined('BASE_URL')) {
    require_once __DIR__ . '/../config.php';
}
$pageTitle = "Login";
// For this specific page, we might not include the standard header/footer if it's a full-screen design
// However, APP_CONFIG is needed for JS. We can output it directly or ensure router_index.php handles it.
// For now, let's assume APP_CONFIG will be made available if header_website.php is included by the router.
// If this page is loaded standalone by the router, it should ideally still get APP_CONFIG.
// Let's include header_website.php for now, and its nav can be hidden by CSS if this page needs full control.
// OR, a minimal header just for APP_CONFIG.
// For simplicity of this step, I'll keep it minimal and add APP_CONFIG directly.
?>
<!DOCTYPE html>
<?php
if (!defined('BASE_URL')) { // Should be defined by config included in header
    require_once __DIR__ . '/../config.php';
}
$pageTitle = "Login";
require_once 'templates/header_website.php'; // Use website header
?>
<?php /* Body tag is opened in header_website.php, add class for styling */ ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        if (!document.body.classList.contains('login-page-body')) {
            document.body.classList.add('login-page-body');
        }
    });
</script>

<div class="login-page-wrapper">
    <div class="animated-blob blob1"></div>
        <div class="animated-blob blob2"></div>
        <div class="animated-blob blob3"></div>

        <div class="container login-form-container"> {/* Added login-form-container for specific styling */}
            <h2>Login to Your Account</h2>

            <div id="login-message-placeholder">
                <?php
                if (isset($_GET['error'])) {
                    echo '<p class="message error-message">' . htmlspecialchars($_GET['error']) . '</p>';
                }
                if (isset($_GET['message'])) {
                    echo '<p class="message success-message">' . htmlspecialchars($_GET['message']) . '</p>';
                }
                 if (isset($_GET['session_expired'])) {
                    echo '<p class="message error-message">Your session has expired. Please login again.</p>';
                }
                ?>
            </div>
            <div id="login-error-message" class="message error-message" style="display: none;"></div>

            <form id="login-form">
                <div>
                    <label for="identifier">Email or Mobile:</label>
                    <input type="text" id="identifier" name="identifier" required>
                </div>
                <div>
                    <label for="password">Password:</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <div>
                    <button type="submit" id="login-button">Login</button>
                </div>
            </form>
            <div class="register-link" style="text-align: center; margin-top: 15px;">
                <p>Don't have an account? <a href="<?php echo rtrim(BASE_URL, '/'); ?>/register">Register here</a></p>
            </div>
            <div class="register-link" style="text-align: center; margin-top: 10px;">
                <p><a href="<?php echo rtrim(BASE_URL, '/'); ?>/forgot_password">Forgot Password?</a></p>
            </div>
        </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof APP_CONFIG === 'undefined' || !APP_CONFIG.baseApiUrl || !APP_CONFIG.baseUrl) {
        console.error('APP_CONFIG is not defined. Login functionality might be impaired.');
        const errorDiv = document.getElementById('login-error-message');
        if(errorDiv) {
            errorDiv.textContent = 'Application configuration error. Please try again later.';
            errorDiv.style.display = 'block';
        }
        // return; // Don't return, allow form to be visible for HTML/CSS check
    }

    const loginForm = document.getElementById('login-form');
    const loginButton = document.getElementById('login-button');
    const errorMessageDiv = document.getElementById('login-error-message');
    const messagePlaceholderDiv = document.getElementById('login-message-placeholder');

    // Clear session_expired message from URL after displaying it once, if it was a redirect
    if (new URLSearchParams(window.location.search).has('session_expired')) {
        if (window.history.replaceState) {
            const cleanUrl = window.location.pathname + window.location.search.replace(/&?session_expired=true/, '').replace(/^\?$/, '');
            window.history.replaceState({ path: cleanUrl }, '', cleanUrl);
        }
    }


    loginForm.addEventListener('submit', function(event) {
        event.preventDefault();
        errorMessageDiv.textContent = '';
        errorMessageDiv.style.display = 'none';
        if(messagePlaceholderDiv) messagePlaceholderDiv.innerHTML = '';

        const identifier = document.getElementById('identifier').value.trim();
        const password = document.getElementById('password').value;

        if (!identifier || !password) {
            errorMessageDiv.textContent = 'Email/Mobile and Password are required.';
            errorMessageDiv.style.display = 'block';
            return;
        }

        loginButton.textContent = 'Logging in...';
        loginButton.disabled = true;

        // Using authenticatedFetch, though for login, it won't have a token yet.
        // The purpose of authenticatedFetch here is more for consistent API call structure
        // and potential future common error handling, though 401 from login is an expected failure.
        // A separate non-authenticated fetch function could also be used for public endpoints like login/register.
        // For now, authenticatedFetch will simply not add an Auth header if no token.
        authenticatedFetch(APP_CONFIG.baseApiUrl + '/auth/login', {
            method: 'POST',
            body: JSON.stringify({ identifier: identifier, password: password })
            // Content-Type and Accept headers are added by authenticatedFetch
        })
        .then(response => {
            // For login, a 401 is an expected "invalid credentials" type error, not necessarily a session expiry.
            // authenticatedFetch's global 401 handler might redirect prematurely if not adjusted.
            // Let's assume login API returns 400 or 403 for bad creds, and 401 for other auth issues.
            // Or, we adjust authenticatedFetch or use raw fetch here.
            // For now, let's assume login API returns non-200 for bad creds, and we parse JSON.
            if (!response.ok) {
                return response.json().then(errData => {
                    throw new Error(errData.error || errData.message || `Login failed: ${response.status}`);
                }).catch(() => { // If response is not JSON or parsing fails
                     throw new Error(`Login failed: ${response.status} ${response.statusText}`);
                });
            }
            return response.json();
        })
        .then(result => { // Expects standardized JSON: {status: 'success', data: {token: '...', user: {...}}}
            if (result.status === 'success' && result.data && result.data.token) {
                localStorage.setItem('authToken', result.data.token);
                if(result.data.user) { // Store user data if API provides it
                    localStorage.setItem('userData', JSON.stringify(result.data.user));
                }

                window.dispatchEvent(new CustomEvent('authChange'));

                const urlParams = new URLSearchParams(window.location.search);
                let redirectTo = urlParams.get('redirect_to') || urlParams.get('return_to'); // Check both
                if (redirectTo) {
                    // Basic sanitization for redirectTo to prevent open redirect if it's not from a trusted source
                    // For now, assume it's from our own app (e.g., session_expired redirect)
                    if (redirectTo.startsWith('/') || redirectTo.startsWith(APP_CONFIG.baseUrl)) {
                        window.location.href = redirectTo;
                    } else {
                         window.location.href = APP_CONFIG.baseUrl + '/' + redirectTo.replace(/^\//, '');
                    }
                } else {
                    window.location.href = APP_CONFIG.baseUrl + '/user/dashboard';
                }
            } else {
                throw new Error(result.message || result.error || 'Login failed: Invalid response from server.');
            }
        })
        .catch(error => {
            errorMessageDiv.textContent = error.message;
            errorMessageDiv.style.display = 'block';
        })
        .finally(() => {
            loginButton.textContent = 'Login';
            loginButton.disabled = false;
        });
    });
});
</script>

<?php require_once 'templates/footer_website.php'; // Use website footer ?>
