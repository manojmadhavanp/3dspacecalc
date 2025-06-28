</div> <!-- Closing .container (opened in header_app.php) -->

    <nav class="footer-nav" id="app-footer-nav">
        <ul>
            <li>
                <button type="button" id="footer-menu-btn" class="footer-nav-item">
                    <span class="icon">&#9776;</span> <!-- Hamburger icon -->
                    <span class="label">Menu</span>
                </button>
            </li>
            <li id="footer-calculator-item" class="footer-nav-item-logged-in" style="display: none;">
                <a href="<?php echo rtrim(BASE_URL, '/'); ?>/user/new_calculation" id="footer-calculator-link" class="footer-nav-item">
                    <span class="icon">🧮</span> <!-- Calculator Icon Placeholder -->
                    <span class="label">Calculator</span>
                </a>
            </li>
            <li id="footer-auth-item" class="footer-nav-item">
                {-- Login/Signout button will be dynamically inserted here by checkLoginState() in header_app.php's JS --}
                {-- Initial placeholder to ensure the li exists for JS to target --}
                <a href="<?php echo rtrim(BASE_URL, '/'); ?>/login" class="footer-nav-item footer-nav-item-logged-out" style="display:flex;">
                    <span class="icon">🔑</span> <span class="label">Login</span>
                </a>
            </li>
        </ul>
    </nav>

    <!-- Mobile Menu Structure (hidden by default) -->
    <div id="mobile-menu-overlay" class="mobile-menu-overlay-hidden">
        <nav id="mobile-menu" class="mobile-menu-hidden">
            <div id="mobile-menu-header">
                <span>MENU</span>
                <button type="button" id="close-mobile-menu-btn">&times;</button>
            </div>
            <ul>
                <li><a href="<?php echo rtrim(BASE_URL, '/'); ?>/">Home (Public)</a></li>
                <hr class="nav-item-logged-in" style="display:none;">
                <li class="nav-item-logged-in" style="display:none;"><a href="<?php echo rtrim(BASE_URL, '/'); ?>/user/dashboard">Dashboard</a></li>
                <li class="nav-item-logged-in" style="display:none;"><a href="<?php echo rtrim(BASE_URL, '/'); ?>/user/new_calculation">New Calculation</a></li>
                <li class="nav-item-logged-in" style="display:none;"><a href="<?php echo rtrim(BASE_URL, '/'); ?>/user/clients">Clients</a></li>
                <li class="nav-item-logged-in" style="display:none;"><a href="<?php echo rtrim(BASE_URL, '/'); ?>/user/company_users">Company Users</a></li>
                <li class="nav-item-logged-in" style="display:none;"><a href="<?php echo rtrim(BASE_URL, '/'); ?>/user/account_settings">Account Settings</a></li>
                <hr>
                <li><a href="<?php echo rtrim(BASE_URL, '/'); ?>/about">About Us</a></li>
                <li><a href="<?php echo rtrim(BASE_URL, '/'); ?>/features">Features</a></li>
                <li><a href="<?php echo rtrim(BASE_URL, '/'); ?>/pricing">Pricing</a></li>
                <li><a href="<?php echo rtrim(BASE_URL, '/'); ?>/contact">Contact Us</a></li>
                <hr>
                {-- Login/Register or Signout links can also be added here, controlled by JS --}
                <li class="nav-item-logged-out" style="display:none;"><a href="<?php echo rtrim(BASE_URL, '/'); ?>/login">Sign In</a></li>
                <li class="nav-item-logged-out" style="display:none;"><a href="<?php echo rtrim(BASE_URL, '/'); ?>/register">Register</a></li>
                <li class="nav-item-logged-in" style="display:none;"><a href="#" id="mobile-menu-logout-link">Sign Out</a></li>

            </ul>
        </nav>
    </div>

    {-- Note: The main JavaScript block with APP_CONFIG, checkLoginState, handleLogout, authenticatedFetch
        is expected to be in header_app.php so it's loaded before this footer's elements are processed
        by DOMContentLoaded listeners, especially for checkLoginState updating footer-auth-item. --}

</body>
</html>
