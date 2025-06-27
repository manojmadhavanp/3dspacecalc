<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - SaaS Platform</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background-color: #f4f4f4; }
        .container { background-color: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); max-width: 400px; margin: auto; margin-top: 50px; }
        h2 { text-align: center; color: #333; }
        label { display: block; margin-bottom: 8px; font-weight: bold; }
        input[type="password"], input[type="hidden"] {
            width: calc(100% - 22px);
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        input[type="submit"] {
            background-color: #28a745; /* Green for reset */
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            width: 100%;
        }
        input[type="submit"]:hover { background-color: #218838; }
        .message { margin-bottom: 15px; text-align: center; padding: 10px; border-radius: 4px; }
        .error-message { color: #721c24; background-color: #f8d7da; border: 1px solid #f5c6cb; }
        .success-message { color: #155724; background-color: #d4edda; border: 1px solid #c3e6cb; }
        .login-link { text-align: center; margin-top: 15px; }
        .login-link a { color: #007bff; text-decoration: none; }
        .login-link a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Reset Your Password</h2>
        <?php
        if (isset($_GET['error'])) {
            echo '<p class="message error-message">' . htmlspecialchars($_GET['error']) . '</p>';
        }
        if (isset($_GET['success'])) {
            echo '<p class="message success-message">' . htmlspecialchars($_GET['success']) . '</p>';
            echo '<div class="login-link"><p><a href="login.php">Proceed to Login</a></p></div>';
        }

        $token = isset($_GET['token']) ? htmlspecialchars($_GET['token']) : '';

        if (empty($token) && !isset($_GET['success'])) {
            echo '<p class="message error-message">Invalid or missing reset token. Please request a new reset link.</p>';
        } elseif (!isset($_GET['success'])) { // Only show form if no success message and token exists
        ?>
            <form action="handle_reset_password.php" method="POST">
                <input type="hidden" name="token" value="<?php echo $token; ?>">

                <label for="new_password">New Password:</label>
                <input type="password" id="new_password" name="new_password" required minlength="8">

                <label for="confirm_password">Confirm New Password:</label>
                <input type="password" id="confirm_password" name="confirm_password" required minlength="8">

                <input type="submit" value="Reset Password">
            </form>
        <?php
        } // End of form display condition

        if (empty($token) && !isset($_GET['success'])) {
             echo '<div class="login-link"><p><a href="forgot_password.php">Request a new link</a></p></div>';
        }
        ?>
         <div class="login-link" style="margin-top: 20px;">
            <p><a href="login.php">Back to Login</a></p>
        </div>
    </div>
</body>
</html>
