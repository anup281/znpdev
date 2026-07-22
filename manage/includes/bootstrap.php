<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/workspace_bootstrap.php';
$managementContext = znp_workspace_bootstrap('management');
$managementUser = $managementContext['user'];
$currentManagementPage = $managementContext['current_page'];

if (!function_exists('manage_e')) {
    function manage_e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('manage_nav_active')) {
    function manage_nav_active(array $pages): string {
        global $currentManagementPage;
        return in_array($currentManagementPage, $pages, true) ? 'active' : '';
    }
}
