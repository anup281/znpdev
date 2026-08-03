<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/workspace_bootstrap.php';
require_once __DIR__ . '/../../includes/storage.php';
$workspaceContext = znp_workspace_bootstrap('construction', [
    'login_url' => '/admin/login.php',
    'return_key' => 'dev_return',
    'load_ui' => false,
]);
require_once __DIR__ . '/functions.php';
$user = $workspaceContext['user'];
$currentDevPage = $workspaceContext['current_page'];
if ((int)($user['must_change_password'] ?? 0) === 1 && !in_array($currentDevPage, ['change_password.php'], true)) {
    header('Location: change_password.php?required=1');
    exit;
}
function dev_require_project(int $projectId): array
{
    $p = dev_project($projectId);
    if (!$p || !dev_can_access($projectId)) {
        http_response_code(403);
        exit('Project access denied.');
    }
    return $p;
}
