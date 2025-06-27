<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - SaaS Platform</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background-color: #f4f4f4; }
        .container { background-color: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); max-width: 500px; margin: auto; }
        h2 { text-align: center; color: #333; }
        label { display: block; margin-bottom: 8px; font-weight: bold; }
        input[type="text"], input[type="email"], input[type="password"], input[type="tel"] {
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
        .error-message { color: red; margin-bottom: 15px; text-align: center; }
        .success-message { color: green; margin-bottom: 15px; text-align: center; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Create Your Company Account</h2>
        <?php
        if (isset($_GET['error'])) {
            echo '<p class="error-message">' . htmlspecialchars($_GET['error']) . '</p>';
        }
        if (isset($_GET['success'])) {
            echo '<p class="success-message">' . htmlspecialchars($_GET['success']) . '</p>';
        }
        ?>
        <form action="handle_registration.php" method="POST">
            <fieldset>
                <legend>Your Details (Company Admin)</legend>
                <label for="firstName">First Name:</label>
                <input type="text" id="firstName" name="firstName" required>

                <label for="middleName">Middle Name (Optional):</label>
                <input type="text" id="middleName" name="middleName">

                <label for="lastName">Last Name:</label>
                <input type="text" id="lastName" name="lastName" required>

                <label for="userEmail">Your Email:</label>
                <input type="email" id="userEmail" name="userEmail" required>

                <label for="userMobile">Your Mobile:</label>
                <input type="tel" id="userMobile" name="userMobile" required>

                <label for="password">Password:</label>
                <input type="password" id="password" name="password" required minlength="8">

                <label for="confirmPassword">Confirm Password:</label>
                <input type="password" id="confirmPassword" name="confirmPassword" required minlength="8">
            </fieldset>

            <fieldset style="margin-top: 20px;">
                <legend>Company Details</legend>
                <label for="companyName">Company Name:</label>
                <input type="text" id="companyName" name="companyName" required>

                <label for="companyEmail">Company Email (Optional, if different from yours):</label>
                <input type="email" id="companyEmail" name="companyEmail">

                <label for="companyPhone">Company Phone (Optional):</label>
                <input type="tel" id="companyPhone" name="companyPhone">

                <label for="companyAddress">Company Address (Optional):</label>
                <input type="text" id="companyAddress" name="companyAddress">
            </fieldset>

            <input type="submit" value="Register Company">
        </form>
    </div>
</body>
</html>
