<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - SaaS Platform</title>
    <!-- Using styles from global style.css -->
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
    <?php
        if (!defined('BASE_URL')) {
             require_once __DIR__ . '/../config.php'; // Adjust path if this moves to 'views'
        }
    ?>
    <div class="container" style="max-width: 500px; margin: 30px auto;">
        <h2>Create Your Company Account</h2>

        <div id="register-message-placeholder">
            <?php
            // For messages passed via URL if redirected here by old PHP logic
            if (isset($_GET['error'])) {
                echo '<p class="message error-message">' . htmlspecialchars($_GET['error']) . '</p>';
            }
            if (isset($_GET['success'])) { // This will be primarily handled by JS now
                echo '<p class="message success-message">' . htmlspecialchars($_GET['success']) . '</p>';
            }
            ?>
        </div>
        <div id="register-error-message" class="message error-message" style="display: none;"></div>
        <div id="register-success-message" class="message success-message" style="display: none;"></div>

        <form id="registration-form">
            <fieldset>
                <legend>Your Details (Company Admin)</legend>
                <div>
                    <label for="firstName">First Name:</label>
                    <input type="text" id="firstName" name="firstName" required>
                </div>
                <div>
                    <label for="middleName">Middle Name (Optional):</label>
                    <input type="text" id="middleName" name="middleName">
                </div>
                <div>
                    <label for="lastName">Last Name:</label>
                    <input type="text" id="lastName" name="lastName" required>
                </div>
                <div>
                    <label for="userEmail">Your Email:</label>
                    <input type="email" id="userEmail" name="userEmail" required>
                </div>
                <div>
                    <label for="userMobile">Your Mobile:</label>
                    <input type="tel" id="userMobile" name="userMobile" required>
                </div>
                <div>
                    <label for="password">Password:</label>
                    <input type="password" id="password" name="password" required minlength="8">
                </div>
                <div>
                    <label for="confirmPassword">Confirm Password:</label>
                    <input type="password" id="confirmPassword" name="confirmPassword" required minlength="8">
                </div>
            </fieldset>

            <fieldset style="margin-top: 20px;">
                <legend>Company Details</legend>
                <div>
                    <label for="companyName">Company Name:</label>
                    <input type="text" id="companyName" name="companyName" required>
                </div>
                <div>
                    <label for="companyEmail">Company Email (Optional, if different from yours):</label>
                    <input type="email" id="companyEmail" name="companyEmail">
                </div>
                <div>
                    <label for="companyPhone">Company Phone (Optional):</label>
                    <input type="tel" id="companyPhone" name="companyPhone">
                </div>
                <div>
                    <label for="companyAddress">Company Address (Optional):</label>
                    <input type="text" id="companyAddress" name="companyAddress">
                </div>
            </fieldset>

            <div style="margin-top: 20px;">
                <button type="submit" id="register-button">Register Company</button>
            </div>
        </form>
        <div style="text-align: center; margin-top: 15px;">
            <p>Already have an account? <a href="<?php echo rtrim(BASE_URL, '/'); ?>/login">Login here</a></p>
        </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof APP_CONFIG === 'undefined' || !APP_CONFIG.baseApiUrl || !APP_CONFIG.baseUrl) {
        console.error('APP_CONFIG is not defined. Make sure header.php includes it.');
        const errorDiv = document.getElementById('register-error-message');
        if(errorDiv) errorDiv.textContent = 'Application configuration error. Please try again later.';
        if(errorDiv) errorDiv.style.display = 'block';
        return;
    }

    const registrationForm = document.getElementById('registration-form');
    const registerButton = document.getElementById('register-button');
    const errorMessageDiv = document.getElementById('register-error-message');
    const successMessageDiv = document.getElementById('register-success-message');
    const messagePlaceholderDiv = document.getElementById('register-message-placeholder');

    registrationForm.addEventListener('submit', function(event) {
        event.preventDefault();
        errorMessageDiv.textContent = '';
        errorMessageDiv.style.display = 'none';
        successMessageDiv.textContent = '';
        successMessageDiv.style.display = 'none';
        if(messagePlaceholderDiv) messagePlaceholderDiv.innerHTML = '';


        // Collect form data
        const firstName = document.getElementById('firstName').value.trim();
        const middleName = document.getElementById('middleName').value.trim();
        const lastName = document.getElementById('lastName').value.trim();
        const userEmail = document.getElementById('userEmail').value.trim();
        const userMobile = document.getElementById('userMobile').value.trim();
        const password = document.getElementById('password').value;
        const confirmPassword = document.getElementById('confirmPassword').value;
        const companyName = document.getElementById('companyName').value.trim();
        const companyEmail = document.getElementById('companyEmail').value.trim();
        const companyPhone = document.getElementById('companyPhone').value.trim();
        const companyAddress = document.getElementById('companyAddress').value.trim();

        // Client-side validation
        if (!firstName || !lastName || !userEmail || !userMobile || !password || !companyName) {
            errorMessageDiv.textContent = 'Please fill in all required fields.';
            errorMessageDiv.style.display = 'block';
            return;
        }
        if (password !== confirmPassword) {
            errorMessageDiv.textContent = 'Passwords do not match.';
            errorMessageDiv.style.display = 'block';
            return;
        }
        if (password.length < 8) { // Basic password length check
            errorMessageDiv.textContent = 'Password must be at least 8 characters long.';
            errorMessageDiv.style.display = 'block';
            return;
        }
        // Basic email validation
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(userEmail)) {
            errorMessageDiv.textContent = 'Please enter a valid email address for yourself.';
            errorMessageDiv.style.display = 'block';
            return;
        }
        if (companyEmail && !emailRegex.test(companyEmail)) {
            errorMessageDiv.textContent = 'Please enter a valid company email address or leave it blank.';
            errorMessageDiv.style.display = 'block';
            return;
        }


        registerButton.textContent = 'Registering...';
        registerButton.disabled = true;

        const payload = {
            firstName, middleName, lastName, userEmail, userMobile, password,
            companyName, companyEmail: companyEmail || userEmail, // Use userEmail if companyEmail is blank
            companyPhone, companyAddress
        };

        fetch(APP_CONFIG.baseApiUrl + '/auth/register', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify(payload)
        })
        .then(response => {
            if (!response.ok) {
                return response.json().then(errData => {
                    throw new Error(errData.error || errData.message || `Registration failed: ${response.status}`);
                }).catch(() => {
                    throw new Error(`Registration failed: ${response.status} ${response.statusText}`);
                });
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                successMessageDiv.textContent = data.message || 'Registration successful! You can now log in.';
                successMessageDiv.style.display = 'block';
                registrationForm.reset(); // Clear the form
                // Optionally redirect to login after a delay or provide a clear login link.
                // setTimeout(() => { window.location.href = APP_CONFIG.baseUrl + '/login'; }, 3000);
            } else {
                throw new Error(data.error || data.message || 'Registration failed: Invalid response from server.');
            }
        })
        .catch(error => {
            errorMessageDiv.textContent = error.message;
            errorMessageDiv.style.display = 'block';
        })
        .finally(() => {
            registerButton.textContent = 'Register Company';
            registerButton.disabled = false;
        });
    });
});
</script>

</body>
</html>
