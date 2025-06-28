<?php
// router_index.php - Basic Front Controller

// Load essential configuration (like BASE_URL, DB credentials if needed here)
// __DIR__ ensures the path is correct regardless of where router_index.php is included from (though it's a top-level file)
require_once __DIR__ . '/config.php';
// db_connect.php is not strictly needed here unless the router itself makes DB calls,
// which it typically shouldn't. Individual routed scripts will include it.

// Get the requested URL path from the query parameter set by .htaccess
$request_uri = $_GET['url'] ?? '';

// Remove leading/trailing slashes and sanitize
$request_path = trim($request_uri, '/');
// $request_path = filter_var($request_path, FILTER_SANITIZE_URL); // Optional further sanitization

// Define routes. This is a very simple array-based router.
// Keys are the "clean" URL paths, values are the actual PHP files to include.
// More advanced routers would use regex, support HTTP methods, parameters in path, etc.
$routes = [
    // Static Pages / Root
    '' => 'index.php', // Homepage (current index.php is the public homepage)
    'about' => 'about.php',
    'features' => 'features.php',
    'pricing' => 'pricing.php',
    'contact' => 'contact.php',
    'privacy' => 'privacy.php', // Placeholder page
    'terms' => 'terms.php',     // Placeholder page

    // Auth - View files
    'login' => 'login.php',
    'register' => 'register.php',
    'forgot_password' => 'forgot_password.php',
    'reset_password' => 'reset_password.php', // View for entering new password, token in URL

    // Note: handle_*.php routes are removed as their logic is now via API calls from the view's JS.
    // The 'logout' path might be kept if we want a specific URL to trigger client-side logout logic,
    // or it can be removed if logout is only triggered by clicking the link in header.php (JS handled).
    // For now, let's assume logout is primarily JS driven by the link in header.php.
    // If a direct /logout URL is desired to ensure logout even if JS fails, it could call a simple
    // PHP script that just clears any local PHP session (if used for non-auth things) and redirects.
    // But for pure API driven auth, this route might not be strictly necessary.
    // Let's remove it for now to enforce client-side JS logout.
    // 'logout' => 'logout.php', // This script itself needs refactoring if kept.

    // User Area (prefixed with 'user/') - View files
    'user/dashboard' => 'user_area/dashboard.php',
    'user/clients' => 'user_area/clients.php',
    'user/company_users' => 'user_area/company_users.php',
    'user/new_calculation' => 'user_area/new_calculation.php',
    'user/account_settings' => 'user_area/account_settings.php',

    // View Report (publicly accessible, query params like ?id=...&token=... will be passed automatically)
    'view_report' => 'view_report.php',

    // API routes are handled by the external API server (xactload.hostboxindia.com/api/v1/)
    // So, no '/api/v1/...' routes are needed in this *frontend* router.
    // Old API-related file mappings are removed:
    // 'api/v1/getitemlist' => 'api/getitemlist.php',
    // 'api/v1/generatereport' => 'api/generateReport.php',
    // 'api/v1/subscription/create-order' => 'create_razorpay_order.php',
    // 'api/v1/subscription/verify-payment' => 'payment_verification.php',

    // The following payment related files were previously mapped via API routes.
    // If they are still needed as direct POST targets from an external service (like Razorpay callback)
    // and are NOT going through the xactload.hostboxindia.com/api/v1/ base URL, they might need
    // specific routes here. However, Razorpay callback should ideally point to an API endpoint.
    // For now, assuming all such backend processing, including payment verification, happens via the API domain.
    // If create_razorpay_order.php was meant to be called by frontend JS (which it was),
    // its functionality is now expected at BASE_API_URL + '/subscription/create-order'.
    // If payment_verification.php is a direct callback from Razorpay to *this* frontend domain,
    // it would need a route. But typically callbacks also go to backend API endpoints.
    // Let's assume these are not needed in frontend router for now.
];

// Check if the requested path exists in our routes
if (array_key_exists($request_path, $routes)) {
    $file_to_include = __DIR__ . '/' . $routes[$request_path];

    if (file_exists($file_to_include)) {
        // For API routes, we might want to set specific headers or perform checks earlier
        if (strpos($request_path, 'api/') === 0) {
            // Headers for API responses are typically set within the API scripts themselves (e.g., Content-Type: application/json)
            // Any global API setup could go here.
        }

        // Include the target file. The included file will handle its own logic,
        // including session management, DB connection, output, etc.
        require $file_to_include;
        exit; // Stop further processing by the router
    } else {
        // This case should ideally not happen if $routes array is correct
        http_response_code(500);
        echo "Error: Routed file '{$routes[$request_path]}' not found on server.";
        error_log("Router error: File {$file_to_include} defined in routes but not found for path '{$request_path}'.");
        exit;
    }
} else {
    // --- Handle dynamic routes or more complex patterns (optional advanced step) ---
    // Example: if you had routes like /user/profile/{id}
    // You would need to match this pattern and extract {id}.
    // For now, we only handle exact matches from the $routes array.

    // --- No exact route match found ---
    http_response_code(404);
    // You can include a custom 404 page here:
    // require __DIR__ . '/views/404.php';
    echo "<h1>404 Not Found</h1>";
    echo "<p>The page you requested ('" . htmlspecialchars($request_path) . "') could not be found.</p>";
    echo "<p><a href='" . rtrim(BASE_URL, '/') . "/'>Go to Homepage</a></p>";
    error_log("404 Not Found: Path '{$request_path}' (Original URI: '{$request_uri}')");
    exit;
}

?>
