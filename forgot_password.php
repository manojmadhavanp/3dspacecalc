<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - SaaS Platform</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background-color: #f4f4f4; }
        .container { background-color: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); max-width: 400px; margin: auto; margin-top: 50px; }
        h2 { text-align: center; color: #333; }
        label { display: block; margin-bottom: 8px; font-weight: bold; }
        input[type="email"] {
            width: calc(100% - 22px);
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        input[type="submit"] {
            background-color: #007bff;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            width: 100%;
        }
        input[type="submit"]:hover { background-color: #0056b3; }
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
        <h2>Forgot Your Password?</h2>
        <p style="text-align:center; color: #555; margin-bottom:20px;">Enter your email address and we will send you a link to reset your password.</p>
        <?php
        if (isset($_GET['error'])) {
            echo '<p class="message error-message">' . htmlspecialchars($_GET['error']) . '</p>';
        }
        if (isset($_GET['success'])) {
            echo '<p class="message success-message">' . htmlspecialchars($_GET['success']) . '</p>';
        }
        // This is for simulation - in a real app, the token/link would be emailed.
        if (isset($_GET['token_info'])) {
            echo '<p class="message success-message" style="font-size:0.9em; word-wrap:break-word;"><strong>Dev Info (Email Simulation):</strong><br> Reset Link: <a href="reset_password.php?token=' . htmlspecialchars(urlencode($_GET['token_info'])) . '">reset_password.php?token=' . htmlspecialchars($_GET['token_info']) . '</a></p>';
        }
        ?>
        <form action="handle_forgot_password.php" method="POST">
            <label for="email">Your Email Address:</label>
            <input type="email" id="email" name="email" required>
            <input type="submit" value="Send Reset Link">
        </form>
        <div class="login-link">
            <p><a href="login.php">Back to Login</a></p>
        </div>
    </div>
</body>
</html>
