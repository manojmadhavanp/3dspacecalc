<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - SaaS Platform</title>
    <!-- Using styles from global style.css, specific login styles can be added if needed -->
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
    <?php
        // config.php might be included by router_index.php already.
        // If not, and BASE_URL is needed for links below, ensure it's available.
        // For now, assuming router_index.php handles config loading.
        // Or, if this file is loaded directly for some reason (though it shouldn't be with routing):
        if (!defined('BASE_URL')) {
             require_once __DIR__ . '/../config.php'; // Adjust path if login.php moves to a 'views' folder
        }
    ?>
    <div class="container" style="max-width: 400px; margin: 50px auto;">
        <h2>Login to Your Account</h2>

        <div id="login-message-placeholder">
            <?php
            // This PHP block is for messages passed via URL if redirected here by PHP (e.g. from old check_session)
            // For API-driven flow, JS will populate #login-error-message
            if (isset($_GET['error'])) {
                echo '<p class="message error-message">' . htmlspecialchars($_GET['error']) . '</p>';
            }
            if (isset($_GET['message'])) {
                echo '<p class="message success-message">' . htmlspecialchars($_GET['message']) . '</p>';
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Ensure APP_CONFIG is available from header.php
    if (typeof APP_CONFIG === 'undefined' || !APP_CONFIG.baseApiUrl || !APP_CONFIG.baseUrl) {
        console.error('APP_CONFIG is not defined. Make sure header.php includes it.');
        const errorDiv = document.getElementById('login-error-message');
        if(errorDiv) errorDiv.textContent = 'Application configuration error. Please try again later.';
        if(errorDiv) errorDiv.style.display = 'block';
        return; // Stop further execution if config is missing
    }

    const loginForm = document.getElementById('login-form');
    const loginButton = document.getElementById('login-button');
    const errorMessageDiv = document.getElementById('login-error-message');
    const messagePlaceholderDiv = document.getElementById('login-message-placeholder'); // For PHP messages

    loginForm.addEventListener('submit', function(event) {
        event.preventDefault();
        errorMessageDiv.textContent = '';
        errorMessageDiv.style.display = 'none';
        if(messagePlaceholderDiv) messagePlaceholderDiv.innerHTML = ''; // Clear old PHP messages

        const identifier = document.getElementById('identifier').value.trim();
        const password = document.getElementById('password').value;

        // Basic client-side validation
        if (!identifier || !password) {
            errorMessageDiv.textContent = 'Email/Mobile and Password are required.';
            errorMessageDiv.style.display = 'block';
            return;
        }

        loginButton.textContent = 'Logging in...';
        loginButton.disabled = true;

        fetch(APP_CONFIG.baseApiUrl + '/auth/login', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ identifier: identifier, password: password })
        })
        .then(response => {
            if (!response.ok) {
                return response.json().then(errData => {
                    throw new Error(errData.error || errData.message || `Login failed with status: ${response.status}`);
                }).catch(() => { // If response is not JSON or parsing fails
                     throw new Error(`Login failed with status: ${response.status} ${response.statusText}`);
                });
            }
            return response.json();
        })
        .then(data => {
            if (data.success && data.token) { // Assuming API returns { success: true, token: '...', user: {...} }
                localStorage.setItem('authToken', data.token);
                // Optionally store user details if returned by API, e.g., localStorage.setItem('userData', JSON.stringify(data.user));

                window.dispatchEvent(new CustomEvent('authChange')); // Notify header to update

                // Redirect to dashboard or intended page
                const urlParams = new URLSearchParams(window.location.search);
                const redirectTo = urlParams.get('redirect_to');
                if (redirectTo) {
                    window.location.href = APP_CONFIG.baseUrl + '/' + redirectTo.replace(/^\//, ''); // Ensure no double slash
                } else {
                    window.location.href = APP_CONFIG.baseUrl + '/user/dashboard';
                }
            } else {
                throw new Error(data.error || data.message || 'Login failed: Invalid response from server.');
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

</body>
</html>
