<?php
$pageTitle = "Contact Us";
require_once 'templates/header_website.php';

// All PHP form processing logic removed. This will be handled by client-side JavaScript.
$prefill_subject = isset($_GET['subject']) ? htmlspecialchars($_GET['subject']) : '';
?>

<h2 class="page-title">Contact Us</h2>
<p class="text-center">Have questions or need support? Fill out the form below, and our team will get back to you as soon as possible.</p>

<div id="contact-feedback-message" class="message" style="display:none;"></div>

<form id="contact-us-form" style="max-width: 600px; margin: 20px auto; padding:20px; background:#f9f9f9; border-radius:5px;">
    <div>
        <label for="contact-name">Full Name:</label>
        <input type="text" id="contact-name" name="name" required>
    </div>
    <div>
        <label for="contact-email">Email Address:</label>
        <input type="email" id="contact-email" name="email" required>
    </div>
    <div>
        <label for="contact-subject">Subject:</label>
        <input type="text" id="contact-subject" name="subject" value="<?php echo $prefill_subject; ?>" required>
    </div>
    <div>
        <label for="contact-message">Message:</label>
        <textarea id="contact-message" name="message" rows="6" required></textarea>
    </div>
    <div>
        <button type="submit" id="contact-submit-btn">Send Message</button>
    </div>
</form>

<div style="text-align: center; margin-top: 30px;">
    <h3>Other Ways to Reach Us:</h3>
    <p><strong>Email:</strong> support@xactload.com (Example)</p>
    <p><strong>Phone:</strong> +91-123-456-7890 (Mon-Fri, 9 AM - 6 PM IST) (Example)</p>
    <p><strong>Address:</strong> 123 Logistics Lane, Tech Park, Bangalore, India (Example)</p>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof APP_CONFIG === 'undefined' || !APP_CONFIG.baseApiUrl) {
        console.error('APP_CONFIG (baseApiUrl) is not defined for contact form.');
        const feedbackDiv = document.getElementById('contact-feedback-message');
        if(feedbackDiv) {
            feedbackDiv.textContent = 'Application configuration error. Cannot send message.';
            feedbackDiv.className = 'message error-message';
            feedbackDiv.style.display = 'block';
        }
        // Potentially disable form
        const submitBtn = document.getElementById('contact-submit-btn');
        if(submitBtn) submitBtn.disabled = true;
        return;
    }

    const contactForm = document.getElementById('contact-us-form');
    const submitButton = document.getElementById('contact-submit-btn');
    const feedbackDiv = document.getElementById('contact-feedback-message');

    contactForm.addEventListener('submit', function(event) {
        event.preventDefault();
        feedbackDiv.textContent = '';
        feedbackDiv.style.display = 'none';

        const name = document.getElementById('contact-name').value.trim();
        const email = document.getElementById('contact-email').value.trim();
        const subject = document.getElementById('contact-subject').value.trim();
        const message = document.getElementById('contact-message').value.trim();

        if (!name || !email || !subject || !message) {
            feedbackDiv.textContent = 'Please fill in all fields.';
            feedbackDiv.className = 'message error-message';
            feedbackDiv.style.display = 'block';
            return;
        }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            feedbackDiv.textContent = 'Please enter a valid email address.';
            feedbackDiv.className = 'message error-message';
            feedbackDiv.style.display = 'block';
            return;
        }

        const originalButtonText = submitButton.textContent;
        submitButton.textContent = 'Sending...';
        submitButton.disabled = true;

        const payload = { name, email, subject, message };

        // Using raw fetch as this is a public form, no auth token needed typically
        // If your contact API is protected, you'd use authenticatedFetch (but that implies user is logged in)
        fetch(APP_CONFIG.baseApiUrl + '/contact-submission', { // Example endpoint
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
                feedbackDiv.textContent = data.message || 'Thank you for your message! We will get back to you shortly.';
                feedbackDiv.className = 'message success-message';
                contactForm.reset();
            } else {
                // Prioritize error message from API response body
                throw new Error(data.message || data.error || `Failed to send message: Server responded with status ${status}`);
            }
        })
        .catch(error => {
            console.error('Contact form submission error:', error);
            feedbackDiv.textContent = `Error: ${error.message}`;
            feedbackDiv.className = 'message error-message';
        })
        .finally(() => {
            submitButton.textContent = originalButtonText;
            submitButton.disabled = false;
            feedbackDiv.style.display = 'block';
        });
    });
});
</script>

<?php require_once 'templates/footer_website.php'; ?>
