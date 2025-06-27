<?php
// This will start session if not already started, and check if user is logged in.
// It also makes $user_first_name available.
require_once 'check_session.php';

$pageTitle = "Dashboard";
// We need to adjust paths for header/footer as we are in a subdirectory
require_once __DIR__ . '/../templates/header.php';
?>

<h2 class="page-title">Welcome to Your Dashboard, <?php echo $user_first_name; ?>!</h2>

<?php
// Display payment status messages if redirected from payment_verification.php
if (isset($_GET['payment_status'])) {
    $payment_status = $_GET['payment_status'];
    $message = $_GET['message'] ?? 'An unknown error occurred with your payment.';
    $payment_id = $_GET['payment_id'] ?? null;
    $order_id = $_GET['order_id'] ?? null;

    $alert_class = ($payment_status === 'success') ? 'success-message' : 'error-message';
    echo "<div class='message {$alert_class}'>";
    echo "<p>" . htmlspecialchars(urldecode($message)) . "</p>";
    if ($payment_id) echo "<p>Payment ID: " . htmlspecialchars($payment_id) . "</p>";
    if ($order_id) echo "<p>Order ID: " . htmlspecialchars($order_id) . "</p>";
    echo "</div>";
}
?>

<p class="text-center">This is your central hub for managing calculations, clients, and your account.</p>

<div class="user-dashboard" style="margin-top:20px;">
    <div style="display: flex; flex-wrap: wrap; justify-content: space-around;">

        <div class="metric" style="flex-basis: 45%; margin-bottom: 20px; background-color: #e9ecef; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <h4>Recent Calculations</h4>
            <p id="recent-calculations-placeholder"><em>(Placeholder: List of recent calculations will appear here)</em></p>
            <a href="new_calculation.php" class="button" style="text-decoration: none; background-color: #007bff; color:white; padding:10px 15px; border-radius:4px;">Start New Calculation</a>
        </div>

        <div class="metric" style="flex-basis: 45%; margin-bottom: 20px; background-color: #e9ecef; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <h4>Active Clients</h4>
            <p id="active-clients-placeholder"><em>(Placeholder: Overview of active clients will appear here)</em></p>
            <a href="clients.php" class="button" style="text-decoration: none; background-color: #17a2b8; color:white; padding:10px 15px; border-radius:4px;">Manage Clients</a>
        </div>

        <div class="metric" style="flex-basis: 45%; margin-bottom: 20px; background-color: #e9ecef; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <h4>Subscription Status</h4>
            <p id="subscription-status-placeholder"><em>(Placeholder: Current subscription details - e.g., Trial, Basic - Ends on YYYY-MM-DD)</em></p>
            <a href="../pricing.php" class="button" style="text-decoration: none; background-color: #28a745; color:white; padding:10px 15px; border-radius:4px;">View Plans / Upgrade</a>
        </div>

        <div class="metric" style="flex-basis: 45%; margin-bottom: 20px; background-color: #e9ecef; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <h4>Account Settings</h4>
            <p id="account-settings-placeholder"><em>(Placeholder: Link to update profile, change password)</em></p>
            <a href="account_settings.php" class="button" style="text-decoration: none; background-color: #ffc107; color:black; padding:10px 15px; border-radius:4px;">My Account</a>
        </div>

    </div>
</div>

<?php
// Adjust path for footer
require_once __DIR__ . '/../templates/footer.php';
?>
