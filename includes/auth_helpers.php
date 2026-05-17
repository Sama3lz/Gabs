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

