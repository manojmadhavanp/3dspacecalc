<?php
// Determine the active page to highlight in navigation
$current_page = basename($_SERVER['PHP_SELF']);

// Start session if not already started, for potential user info display in header later
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? htmlspecialchars($pageTitle) : "SaaS Platform"; ?> - FreightCalc</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <header>
        <h1>FreightCalc Solutions</h1>
        <nav>
            <ul>
                <li><a href="index.php" class="<?php echo ($current_page == 'index.php') ? 'active' : ''; ?>">Home</a></li>
                <li><a href="about.php" class="<?php echo ($current_page == 'about.php') ? 'active' : ''; ?>">About Us</a></li>
                <li><a href="features.php" class="<?php echo ($current_page == 'features.php') ? 'active' : ''; ?>">Features</a></li>
                <li><a href="pricing.php" class="<?php echo ($current_page == 'pricing.php') ? 'active' : ''; ?>">Pricing</a></li>
                <li><a href="contact.php" class="<?php echo ($current_page == 'contact.php') ? 'active' : ''; ?>">Contact Us</a></li>
                <?php if (isset($_SESSION['user_uuid'])): ?>
                    <li><a href="user_area/dashboard.php" class="<?php echo (strpos($current_page, 'dashboard.php') !== false) ? 'active' : ''; ?>">Dashboard</a></li>
                    <li><a href="logout.php">Logout</a></li>
                <?php else: ?>
                    <li><a href="login.php" class="<?php echo ($current_page == 'login.php') ? 'active' : ''; ?>">Login</a></li>
                    <li><a href="register.php" class="<?php echo ($current_page == 'register.php') ? 'active' : ''; ?>">Register</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </header>
    <div class="container">
