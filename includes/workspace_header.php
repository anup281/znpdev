<?php
declare(strict_types=1);

require_once __DIR__.'/workspace_navigation.php';

if (!function_exists('znp_workspace_document_start')) {
    /**
     * Open a workspace HTML document using one shared, production-safe shell.
     * Portal-specific styles remain separate; this only consolidates the
     * repeated document/head boilerplate and shared workspace header CSS.
     */
    function znp_workspace_document_start(string $title, string $bodyClass, string $stylesheet, string $stylesheetVersion = ''): void {
        if (str_starts_with($stylesheet, '/') && function_exists('app_url')) $stylesheet = app_url($stylesheet);
        $href = $stylesheet . ($stylesheetVersion !== '' ? '?v=' . rawurlencode($stylesheetVersion) : '');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
        echo '<link rel="stylesheet" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">';
        $designSystemHref = function_exists('app_url') ? app_url('/assets/design-system.css') : '/assets/design-system.css';
        $designSystemVersion = (function_exists('znp_asset_version') ? znp_asset_version() : '1') . '-shell2';
        echo '<link rel="stylesheet" href="' . htmlspecialchars($designSystemHref, ENT_QUOTES, 'UTF-8') . '?v=' . rawurlencode($designSystemVersion) . '">';
        echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">';
        $bodyClass = trim('znp-workspace-shell ' . $bodyClass);
        echo '</head><body class="' . htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8') . '">';
        if(isset($_SESSION['super_admin_user'])&&is_array($_SESSION['super_admin_user'])){
            $viewed=admin_user();
            $returnTo=(string)($_SERVER['REQUEST_URI']??app_url('/admin/users.php'));
            echo '<div style="position:relative;z-index:9999;background:#8a4b08;color:#fff;padding:10px 18px;display:flex;gap:14px;align-items:center;justify-content:center;font:600 14px Arial,sans-serif">Viewing as '.htmlspecialchars((string)($viewed['name']??$viewed['email']??'user'),ENT_QUOTES,'UTF-8').'<form method="post" action="'.htmlspecialchars(app_url('/admin/impersonate.php'),ENT_QUOTES,'UTF-8').'" style="margin:0"><input type="hidden" name="csrf_token" value="'.htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8').'"><input type="hidden" name="stop" value="1"><input type="hidden" name="return_to" value="'.htmlspecialchars($returnTo,ENT_QUOTES,'UTF-8').'"><button type="submit" style="border:0;border-radius:5px;padding:6px 10px;font-weight:700;cursor:pointer">Return to Super Admin</button></form></div>';
        }
    }
}

if (!function_exists('znp_render_workspace_brand')) {
    /** Render the identical white workspace brand badge in every portal. */
    function znp_render_workspace_brand(string $portalTitle, string $href, string $ariaLabel, string $extraClass = ''): void {
        $class = trim($extraClass . ' znp-workspace-brand');
        if (str_starts_with($href, '/') && function_exists('app_url')) $href = app_url($href);
        echo '<a class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" aria-label="' . htmlspecialchars($ariaLabel, ENT_QUOTES, 'UTF-8') . '">';
        echo '<span>ZNP</span><strong>' . htmlspecialchars(strtoupper($portalTitle), ENT_QUOTES, 'UTF-8') . '</strong></a>';
    }
}
