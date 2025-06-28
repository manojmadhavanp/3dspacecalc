<?php
// header_app.php - Minimal header for the logged-in application area
// Ensures config is loaded for JavaScript, as this header is included by files in subdirectories.
if (!defined('BASE_URL') || !defined('BASE_API_URL')) {
    // Adjust path if header_app.php itself moves or if config isn't loaded by router_index.php first
    require_once __DIR__ . '/../config.php';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo isset($pageTitle) ? htmlspecialchars($pageTitle) : "App"; ?> - XACTLOAD</title>
    <?php
        // Path to CSS relative to the root, assuming router_index.php is at the root.
        echo '<link rel="stylesheet" href="/css/style.css">';
    ?>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/build/three.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js"></script>
    <style>
        /* Minimal styles for app header, if any, beyond what style.css provides for .site-header-app */
        .site-header-app {
            padding: 10px 20px; /* Minimal padding */
            background-color: #f8f9fa; /* Light background, can be themed */
            border-bottom: 1px solid #dee2e6;
            text-align: center; /* Default to center, can be overridden */
        }
        .site-header-app .logo-container-app h1 {
            margin: 0;
            font-size: 1.5rem; /* Slightly smaller for app header */
            color: #007bff; /* Example app header logo color */
        }
         /* Ensure main container takes up space below app header and above app footer */
        body.app-layout .container {
            padding-top: 60px; /* Adjust based on actual app header height */
            padding-bottom: 70px; /* Adjust based on actual app footer height */
        }
    </style>
</head>
<body class="app-layout"> {/* Class to help scope styles for app layout vs website layout */}
    <script>
        // Global JS CONFIG and Functions (same as in header_website.php)
        // This ensures they are available for all app pages.
        window.APP_CONFIG = {
            baseUrl: '<?php echo rtrim(BASE_URL, '/'); ?>',
            baseApiUrl: '<?php echo rtrim(BASE_API_URL, '/'); ?>'
        };

        function checkLoginState() {
            const token = localStorage.getItem('authToken');
            // For App: Target elements specific to footer_app.php
            const loggedOutFooterItems = document.querySelectorAll('.footer-nav-item-logged-out'); // Example class
            const loggedInFooterItems = document.querySelectorAll('.footer-nav-item-logged-in');   // Example class
            const calculatorFooterLink = document.getElementById('footer-calculator-link'); // Specific ID for calculator
            const authFooterItem = document.getElementById('footer-auth-item'); // Container for login/logout button

            if (token) {
                if(loggedOutFooterItems) loggedOutFooterItems.forEach(item => item.style.display = 'none');
                if(loggedInFooterItems) loggedInFooterItems.forEach(item => item.style.display = 'flex'); // Assuming flex for footer items
                if(calculatorFooterLink) calculatorFooterLink.style.display = 'flex';
                if(authFooterItem) authFooterItem.innerHTML = `<button type="button" id="footer-logout-btn" class="footer-nav-item footer-nav-item-logged-in"><span class="icon">🚪</span> <span class="label">Sign Out</span></button>`;

                const logoutBtn = document.getElementById('footer-logout-btn');
                if(logoutBtn) logoutBtn.addEventListener('click', handleLogout);

            } else {
                if(loggedOutFooterItems) loggedOutFooterItems.forEach(item => item.style.display = 'flex');
                if(loggedInFooterItems) loggedInFooterItems.forEach(item => item.style.display = 'none');
                if(calculatorFooterLink) calculatorFooterLink.style.display = 'none';
                if(authFooterItem) authFooterItem.innerHTML = `<a href="${APP_CONFIG.baseUrl}/login" class="footer-nav-item footer-nav-item-logged-out"><span class="icon">🔑</span> <span class="label">Login</span></a>`;
            }
        }

        function handleLogout() {
            localStorage.removeItem('authToken');
            // Optional: Call backend logout API
            // authenticatedFetch(APP_CONFIG.baseApiUrl + '/auth/logout', { method: 'POST' })
            // .finally(() => {
            //     checkLoginState();
            //     window.location.href = APP_CONFIG.baseUrl + '/login';
            // });
            checkLoginState();
            window.location.href = APP_CONFIG.baseUrl + '/login';
        }

        document.addEventListener('DOMContentLoaded', function() {
            // checkLoginState for app footer will be called here if footer elements are static.
            // If footer nav is complex/dynamic, its own script might call checkLoginState.
            // For now, assume it can be called here.
             if (typeof checkLoginState === "function") checkLoginState();

            // Event listener for mobile menu toggle (will be in footer_app.js or here)
            // const menuToggleBtn = document.getElementById('footer-menu-btn');
            // const mobileMenuOverlay = document.getElementById('mobile-menu-overlay');
            // if(menuToggleBtn && mobileMenuOverlay) { ... }

            window.addEventListener('authChange', function() {
                if (typeof checkLoginState === "function") checkLoginState();
            });
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
    <header class="site-header-app">
        <div class="logo-container-app">
            <a href="<?php echo rtrim(BASE_URL, '/'); ?>/user/dashboard"> {/* App logo might link to app dashboard */}
                <h1>XACTLOAD</h1>
            </a>
        </div>
    </header>
    <div class="container"> {/* This container is for the main page content below the header */}
