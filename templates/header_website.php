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
    <?php
        // Determine base path for CSS/JS assets if using clean URLs and router is at root
        // If router_index.php is at root, CSS path needs to be absolute or have correct base.
        // For now, assuming css/style.css is relative to where PHP files are included from by router.
        // If BASE_URL is properly set, it can be used: echo '<link rel="stylesheet" href="' . BASE_URL . 'css/style.css">';
        // However, simple relative paths often work if the router includes files from their original locations.
        // Let's assume for now router_index.php includes files that are siblings to css/, templates/ etc.
        // So, direct relative paths from the *included file's perspective* might be an issue.
        // The safest is to use absolute paths from web root, or use BASE_URL.

        // Let's construct paths relative to BASE_URL for assets if router is at root.
        // If router_index.php is in a subfolder, BASE_URL must reflect that.
        // For now, simple relative path, assuming router includes files keeping their relative context.
        // This might need adjustment based on how router_index.php includes files.
        // A common approach is for router_index.php to define a constant like ASSET_PATH.
        // For now, let's assume the current CSS path works or will be adjusted with link updates.
        echo '<link rel="stylesheet" href="/css/style.css">'; // Assuming CSS is at root/css/
    ?>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/build/three.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js"></script>
    <style>
        body { background-color: #f4f8fc; }
        .report-container {
            max-width: 900px;
            margin: 20px auto;
            padding: 25px;
            background-color: #fff;
            border: 1px solid #e0e0e0;
            box-shadow: 0 0 15px rgba(0,0,0,0.05);
            border-radius: 8px;
        }
        .report-header { text-align: center; margin-bottom: 25px; padding-bottom:15px; border-bottom:1px solid #eee; }
        .report-header h1 { color: #333; font-size: 1.8rem; }
        .report-section { margin-bottom: 30px; }
        .report-section h3 {
            font-size: 1.4rem;
            color: #007bff;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid #007bff;
            display: inline-block;
        }
        .error-message-box {
            border: 1px solid #dc3545;
            color: #721c24;
            background-color: #f8d7da;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            text-align: center;
        }
        .visualization-placeholder {
            min-height: 200px;
            background-color: #f0f2f5;
            border: 1px dashed #ccc;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 20px;
            border-radius: 5px;
        }
        .report-footer { text-align: center; margin-top: 30px; font-size: 0.9em; color: #777; padding-top:15px; border-top:1px solid #eee;}
    </style>
</head>
<body>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? htmlspecialchars($pageTitle) : "SaaS Platform"; ?> - XACTLOAD</title>
    <?php
        // This path assumes router_index.php is at the root and css folder is also at the root.
        echo '<link rel="stylesheet" href="/css/style.css">';
    ?>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/build/three.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js"></script>
    <!-- Removed inline styles from here, should be in style.css -->
</head>
<body>
    <script>
        // Make config available to JavaScript
        window.APP_CONFIG = {
            baseUrl: '<?php echo rtrim(BASE_URL, '/'); ?>',
            baseApiUrl: '<?php echo rtrim(BASE_API_URL, '/'); ?>'
        };

        function checkLoginState() {
            const token = localStorage.getItem('authToken');
            // Target elements specific to header_website
            const loggedOutItems = document.querySelectorAll('.website-nav-logged-out');
            const loggedInItems = document.querySelectorAll('.website-nav-logged-in');

            if (token) {
                loggedOutItems.forEach(item => item.style.display = 'none');
                loggedInItems.forEach(item => item.style.display = 'list-item'); // Or 'inline-block' depending on CSS
            } else {
                loggedOutItems.forEach(item => item.style.display = 'list-item');
                loggedInItems.forEach(item => item.style.display = 'none');
            }
        }

        function handleLogout() {
            localStorage.removeItem('authToken');
            // Optional: Call backend logout API via authenticatedFetch if needed
            // authenticatedFetch(APP_CONFIG.baseApiUrl + '/auth/logout', { method: 'POST' })
            // .finally(() => { // Ensure redirection happens even if API call fails for some reason
            //     checkLoginState();
            //     window.location.href = APP_CONFIG.baseUrl + '/login';
            // });
            checkLoginState();
            window.location.href = APP_CONFIG.baseUrl + '/login';
        }

        document.addEventListener('DOMContentLoaded', function() {
            checkLoginState();

            const logoutLink = document.getElementById('website-logout-link');
            if (logoutLink) {
                logoutLink.addEventListener('click', function(event) {
                    event.preventDefault();
                    handleLogout();
                });
            }
            window.addEventListener('authChange', checkLoginState);
        });

        async function authenticatedFetch(apiUrl, options = {}) {
            const token = localStorage.getItem('authToken');
            const defaultHeaders = { 'Accept': 'application/json' };
            if (options.body && typeof options.body === 'string' && (!options.headers || !options.headers['Content-Type'])) {
                defaultHeaders['Content-Type'] = 'application/json';
            }
            options.headers = { ...defaultHeaders, ...(options.headers || {}) };
            if (token) {
                options.headers['Authorization'] = `Bearer ${token}`;
            }
            try {
                const response = await fetch(apiUrl, options);
                if (response.status === 401) {
                    console.warn('401 Unauthorized by authenticatedFetch. Logging out.');
                    localStorage.removeItem('authToken');
                    window.dispatchEvent(new CustomEvent('authChange'));
                    window.location.href = `${APP_CONFIG.baseUrl}/login?session_expired=true&return_to=${encodeURIComponent(window.location.pathname + window.location.search)}`;
                    throw new Error('Session expired or invalid. Please login again.');
                }
                return response;
            } catch (error) {
                console.error('Error in authenticatedFetch:', error);
                throw error;
            }
        }
    </script>
    <header class="site-header-website">
        <div class="header-website-content">
            <div class="logo-container-website">
                <a href="<?php echo rtrim(BASE_URL, '/'); ?>/">
                    <h1>XACTLOAD</h1>
                </a>
            </div>
            <nav class="main-navigation-website">
                <button id="mobile-nav-toggle" aria-label="Toggle navigation" aria-expanded="false">&#9776;</button>
                <ul id="website-nav-ul">
                    <li><a href="<?php echo rtrim(BASE_URL, '/'); ?>/">Home</a></li>
                    <li><a href="<?php echo rtrim(BASE_URL, '/'); ?>/about">About</a></li>
                    <li><a href="<?php echo rtrim(BASE_URL, '/'); ?>/features">Features</a></li>
                    <li><a href="<?php echo rtrim(BASE_URL, '/'); ?>/pricing">Pricing</a></li>
                    <li><a href="<?php echo rtrim(BASE_URL, '/'); ?>/contact">Contact Us</a></li>
                    <li class="website-nav-logged-out" style="display: none;"><a href="<?php echo rtrim(BASE_URL, '/'); ?>/login" class="nav-button">Sign In</a></li>
                    <li class="website-nav-logged-in" style="display: none;"><a href="<?php echo rtrim(BASE_URL, '/'); ?>/user/dashboard" class="nav-button">Dashboard</a></li>
                    <li class="website-nav-logged-in" style="display: none;"><a href="#" id="website-logout-link" class="nav-button">Sign Out</a></li>
                </ul>
            </nav>
        </div>
    </header>
    <div class="container"> {/* This container is for the main page content below the header */}
