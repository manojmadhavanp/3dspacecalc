<?php
$pageTitle = "Register Company";
require_once 'templates/header_website.php'; // Use website header
?>
<?php /* Body tag is opened in header_website.php, add class for styling */ ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Add login-page-body class if not already on body from other means
        if (!document.body.classList.contains('login-page-body')) {
            document.body.classList.add('login-page-body');
        }
    });
</script>

<div class="login-page-wrapper"> {/* For background effects, same as login.php */}
    <div class="animated-blob blob1"></div>
    <div class="animated-blob blob2"></div>
    <div class="animated-blob blob3"></div>

    <div class="container login-form-container" style="max-width: 550px; margin: 30px auto;"> {/* Wider for register */}
        <h2>Create Your Company Account</h2>

        <div id="register-message-placeholder">
            <?php
            if (isset($_GET['error'])) {
                echo '<p class="message error-message">' . htmlspecialchars($_GET['error']) . '</p>';
            }
            if (isset($_GET['success'])) {
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
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // APP_CONFIG is expected from header_website.php
    if (typeof APP_CONFIG === 'undefined' || !APP_CONFIG.baseApiUrl || !APP_CONFIG.baseUrl) {
        console.error('APP_CONFIG is not defined. Registration functionality might be impaired.');
        const errorDiv = document.getElementById('register-error-message');
        if(errorDiv) {
            errorDiv.textContent = 'Application configuration error. Please try again later.';
            errorDiv.className = 'message error-message'; // Ensure class for styling
            errorDiv.style.display = 'block';
        }
        const regBtn = document.getElementById('register-button');
        if(regBtn) regBtn.disabled = true;
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
            errorMessageDiv.className = 'message error-message';
            errorMessageDiv.style.display = 'block';
            return;
        }
        if (password !== confirmPassword) {
            errorMessageDiv.textContent = 'Passwords do not match.';
            errorMessageDiv.className = 'message error-message';
            errorMessageDiv.style.display = 'block';
            return;
        }
        if (password.length < 8) {
            errorMessageDiv.textContent = 'Password must be at least 8 characters long.';
            errorMessageDiv.className = 'message error-message';
            errorMessageDiv.style.display = 'block';
            return;
        }
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(userEmail)) {
            errorMessageDiv.textContent = 'Please enter a valid email address for yourself.';
            errorMessageDiv.className = 'message error-message';
            errorMessageDiv.style.display = 'block';
            return;
        }
        if (companyEmail && !emailRegex.test(companyEmail)) {
            errorMessageDiv.textContent = 'Please enter a valid company email address or leave it blank.';
            errorMessageDiv.className = 'message error-message';
            errorMessageDiv.style.display = 'block';
            return;
        }


        registerButton.textContent = 'Registering...';
        registerButton.disabled = true;

        const payload = {
            firstName, middleName, lastName, userEmail, userMobile, password,
            companyName, companyEmail: companyEmail || userEmail,
            companyPhone, companyAddress
        };

        // Using raw fetch for public registration endpoint
        fetch(APP_CONFIG.baseApiUrl + '/auth/register', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify(payload)
        })
        .then(response => response.json().then(data => ({ ok: response.ok, status: response.status, data })))
        .then(({ok, status, data}) => {
            if (ok && data.status === 'success') {
                successMessageDiv.textContent = data.message || 'Registration successful! You can now log in.';
                successMessageDiv.className = 'message success-message';
                successMessageDiv.style.display = 'block';
                registrationForm.reset();
            } else {
                let errorMsg = data.message || data.error || `Registration failed: Server responded with status ${status}`;
                if (data.errors) {
                    const fieldErrors = Object.entries(data.errors).map(([field, messages]) => {
                        const capField = field.charAt(0).toUpperCase() + field.slice(1);
                        return `${capField}: ${messages.join(', ')}`;
                    }).join('; ');
                    errorMsg += ` Details: ${fieldErrors}`;
                }
                throw new Error(errorMsg);
            }
        })
        .catch(error => {
            console.error('Registration Error:', error);
            errorMessageDiv.textContent = error.message;
            errorMessageDiv.className = 'message error-message';
            errorMessageDiv.style.display = 'block';
        })
        .finally(() => {
            registerButton.textContent = 'Register Company';
            registerButton.disabled = false;
        });
    });
});
</script>

<?php require_once 'templates/footer_website.php'; ?>
