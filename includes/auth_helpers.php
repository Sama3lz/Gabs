<?php

if (!function_exists('require_login')) {
    function require_login(array $allowed_roles = []) {
        if (!isset($_SESSION['user_ID'])) {
            header("Location: index.php");
            exit();
        }

        if (!empty($allowed_roles)) {
            $role = $_SESSION['role'] ?? null;
            if (!$role || !in_array($role, $allowed_roles, true)) {
                header("Location: welcome.php");
                exit();
            }
        }
    }
}

if (!function_exists('get_nav_permissions')) {
    function get_nav_permissions($role) {
        return [
            'show_inventory_link' => in_array($role, ['Admin', 'Moderator', 'Branch'], true),
            'show_reports_link' => in_array($role, ['Admin', 'Moderator', 'Branch'], true),
            'show_accounts_link' => $role === 'Admin',
            'show_orders_link' => in_array($role, ['Admin', 'Moderator', 'Branch', 'Delivery'], true),
            'show_settings_link' => !empty($_SESSION['user_ID']),
        ];
    }
}

if (!function_exists('post_string')) {
    function post_string($key, $default = '') {
        return trim((string)($_POST[$key] ?? $default));
    }
}

if (!function_exists('post_int')) {
    function post_int($key, $default = 0) {
        return (int)($_POST[$key] ?? $default);
    }
}

if (!function_exists('get_string')) {
    function get_string($key, $default = '') {
        return trim((string)($_GET[$key] ?? $default));
    }
}

if (!function_exists('get_session_role')) {
    function get_session_role() {
        return $_SESSION['role'] ?? '';
    }
}

if (!function_exists('apply_nav_permissions')) {
    function apply_nav_permissions($role) {
        $nav = get_nav_permissions($role);
        $GLOBALS['show_inventory_link'] = $nav['show_inventory_link'];
        $GLOBALS['show_reports_link'] = $nav['show_reports_link'];
        $GLOBALS['show_accounts_link'] = $nav['show_accounts_link'];
        $GLOBALS['show_orders_link'] = $nav['show_orders_link'];
        $GLOBALS['show_settings_link'] = $nav['show_settings_link'];
        return $nav;
    }
}

if (!function_exists('include_role_navbar')) {
    function include_role_navbar() {
        $role = preg_replace('/[^A-Za-z]/', '', get_session_role());
        $path = __DIR__ . "/navbars/{$role}.php";
        if (!is_file($path)) {
            error_log("Navbar not found for role: {$role}");
            return;
        }
        include $path;
    }
}

if (!function_exists('include_role_view')) {
    function include_role_view($page) {
        $role = preg_replace('/[^A-Za-z]/', '', get_session_role());
        $path = __DIR__ . "/views/{$page}/{$role}.php";
        if (!is_file($path)) {
            header('HTTP/1.1 404 Not Found');
            exit('Page view not available for your role.');
        }
        include $path;
    }
}

if (!function_exists('include_hamburger_script')) {
    function include_hamburger_script() {
        include __DIR__ . '/layout/hamburger_script.php';
    }
}

