<?php
$pageTitle = "Contact Us";
require_once 'templates/header.php';

$form_submitted = false;
$submit_error = '';
$submit_success = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Basic validation and sanitation
    $name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $subject_form = filter_input(INPUT_POST, 'subject', FILTER_SANITIZE_STRING);
    $message = filter_input(INPUT_POST, 'message', FILTER_SANITIZE_STRING);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $submit_error = "Please enter a valid email address.";
    } elseif (empty($name) || empty($subject_form) || empty($message)) {
        $submit_error = "Please fill in all required fields.";
    } else {
        // Simulate sending email
        // In a real app, you would use PHPMailer or a similar library
        // mail("your-support-email@example.com", "Contact Form: " . $subject_form, $message, "From: " . $email);
        $submit_success = "Thank you for contacting us, " . htmlspecialchars($name) . "! We will get back to you shortly.";
        $form_submitted = true;
    }
}

// Pre-fill subject if passed via GET parameter (e.g., from pricing page)
$prefill_subject = isset($_GET['subject']) ? htmlspecialchars($_GET['subject']) : '';

?>

<h2 class="page-title">Contact Us</h2>
<p class="text-center">Have questions or need support? Fill out the form below, and our team will get back to you as soon as possible.</p>

<?php if ($submit_error): ?>
    <p class="message error-message"><?php echo $submit_error; ?></p>
<?php endif; ?>

<?php if ($submit_success): ?>
    <p class="message success-message"><?php echo $submit_success; ?></p>
<?php endif; ?>

<?php if (!$form_submitted || $submit_error): // Show form if not submitted successfully or if there was an error ?>
<form action="contact.php" method="POST" style="max-width: 600px; margin: 20px auto; padding:20px; background:#f9f9f9; border-radius:5px;">
    <div>
        <label for="name">Full Name:</label>
        <input type="text" id="name" name="name" value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>" required>
    </div>
    <div>
        <label for="email">Email Address:</label>
        <input type="email" id="email" name="email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required>
    </div>
    <div>
        <label for="subject">Subject:</label>
        <input type="text" id="subject" name="subject" value="<?php echo isset($_POST['subject']) ? htmlspecialchars($_POST['subject']) : $prefill_subject; ?>" required>
    </div>
    <div>
        <label for="message">Message:</label>
        <textarea id="message" name="message" rows="6" required><?php echo isset($_POST['message']) ? htmlspecialchars($_POST['message']) : ''; ?></textarea>
    </div>
    <div>
        <input type="submit" value="Send Message">
    </div>
</form>
<?php endif; ?>

<div style="text-align: center; margin-top: 30px;">
    <h3>Other Ways to Reach Us:</h3>
    <p><strong>Email:</strong> support@freightcalc.example.com</p>
    <p><strong>Phone:</strong> +91-123-456-7890 (Mon-Fri, 9 AM - 6 PM IST)</p>
    <p><strong>Address:</strong> 123 Logistics Lane, Tech Park, Bangalore, India</p>
    <p>(Please note: Email and Phone are placeholders)</p>
</div>


<?php require_once 'templates/footer.php'; ?>
