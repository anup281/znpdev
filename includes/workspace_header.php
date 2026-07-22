<?php
declare(strict_types=1);

require_once __DIR__ . '/workspace_navigation.php';

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
        echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">';
        znp_workspace_header_styles();
        echo '</head><body' . ($bodyClass !== '' ? ' class="' . htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8') . '"' : '') . '>';
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

if (!function_exists('znp_workspace_header_styles')) {
    function znp_workspace_header_styles(): void { ?>
<style>
/* Shared workspace header v4.3.4
   Construction is the visual master for Admin, Construction and Investors. */
.znp-workspace-top,
.admin-top.znp-workspace-top,
.dev-top.znp-workspace-top,
.znp-investor-top.znp-workspace-top{
    display:flex!important;
    align-items:center!important;
    flex-direction:row!important;
    flex-wrap:nowrap!important;
    min-height:54px!important;
    height:54px!important;
    padding:0 3%!important;
    gap:14px!important;
    background:#0b1f3a!important;
    color:#fff!important;
    box-shadow:0 2px 12px rgba(8,28,47,.16)!important;
    position:relative;
    z-index:1000;
}
.znp-workspace-brand,
.admin-brand.znp-workspace-brand,
.dev-brand.znp-workspace-brand,
.znp-investor-brand.znp-workspace-brand{
    display:inline-flex!important;
    align-items:center!important;
    justify-content:flex-start!important;
    flex:0 0 auto!important;
    gap:7px!important;
    width:auto!important;
    min-width:0!important;
    min-height:36px!important;
    height:36px!important;
    margin:0!important;
    padding:0 13px!important;
    border:0!important;
    border-radius:7px!important;
    background:#fff!important;
    color:#163a70!important;
    text-decoration:none!important;
    white-space:nowrap!important;
    line-height:1!important;
    box-shadow:none!important;
    font-family:Arial,sans-serif!important;
    font-size:15px!important;
    letter-spacing:0!important;
}
.znp-workspace-brand span,
.znp-workspace-brand strong,
.admin-brand.znp-workspace-brand span,
.admin-brand.znp-workspace-brand strong,
.dev-brand.znp-workspace-brand span,
.dev-brand.znp-workspace-brand strong,
.znp-investor-brand.znp-workspace-brand span,
.znp-investor-brand.znp-workspace-brand strong{
    display:inline!important;
    width:auto!important;
    height:auto!important;
    margin:0!important;
    padding:0!important;
    border:0!important;
    border-radius:0!important;
    background:transparent!important;
    color:#163a70!important;
    font-family:Arial,sans-serif!important;
    font-size:15px!important;
    line-height:1!important;
    letter-spacing:0!important;
    text-transform:none!important;
}
.znp-workspace-brand span{font-weight:900!important}
.znp-workspace-brand strong{font-weight:400!important;letter-spacing:.06em!important;text-transform:uppercase!important}

/* Existing workspace navigation keeps Construction's compact treatment. */
.admin-top .admin-top-link,
.admin-top .admin-hover-group>button{font:inherit!important;font-size:13px!important;font-weight:750!important;line-height:1!important;}
.znp-workspace-nav>a:not(.znp-portal-icon),
.znp-investor-nav>a:not(.znp-portal-icon){
    display:inline-flex!important;
    align-items:center!important;
    justify-content:center!important;
    min-height:40px!important;
    padding:0 12px!important;
    border:0!important;
    border-radius:7px!important;
    color:#fff!important;
    background:transparent!important;
    text-decoration:none!important;
    font-family:Arial,sans-serif!important;
    font-size:13px!important;
    font-weight:700!important;
    letter-spacing:0!important;
}
.znp-workspace-nav>a:not(.znp-portal-icon):hover,
.znp-workspace-nav>a.active:not(.znp-portal-icon),
.znp-investor-nav>a:not(.znp-portal-icon):hover,
.znp-investor-nav>a.active:not(.znp-portal-icon){background:rgba(255,255,255,.14)!important;color:#fff!important}

.znp-portal-icons{display:flex!important;align-items:center!important;gap:7px!important;margin-left:auto!important;flex:0 0 auto!important}
.znp-portal-icon{display:inline-flex!important;align-items:center!important;justify-content:center!important;width:36px!important;height:36px!important;min-width:36px!important;min-height:36px!important;padding:0!important;border:1px solid rgba(22,58,112,.18)!important;border-radius:9px!important;background:#fff!important;color:#7b8794!important;text-decoration:none!important;box-shadow:0 1px 2px rgba(15,35,55,.05)!important;transition:background .15s,color .15s,border-color .15s,transform .15s!important}
.znp-portal-icon:hover{background:#eef5fb!important;color:#163a70!important;border-color:#8fb3d1!important;transform:translateY(-1px)}
.znp-portal-icon.is-active{background:#163a70!important;color:#fff!important;border-color:#163a70!important}
.znp-portal-icon i{font-size:15px!important;line-height:1!important}
.znp-portal-icon[data-label]{position:relative}
.znp-portal-icon[data-label]:after{content:attr(data-label);position:absolute;right:0;top:44px;background:#102f52;color:#fff;padding:5px 7px;border-radius:5px;font-size:11px;line-height:1;white-space:nowrap;opacity:0;pointer-events:none;transform:translateY(-3px);transition:.15s;z-index:1000}
.znp-portal-icon[data-label]:hover:after{opacity:1;transform:none}
.znp-logout-icon:hover{background:#fff1f1!important;color:#a12626!important;border-color:#d9a0a0!important}

/* Investor currently has no page navigation; icons remain aligned to the right. */
.znp-investor-actions{display:flex!important;align-items:center!important;margin-left:auto!important}

@media(max-width:900px){
    .znp-workspace-top,
    .admin-top.znp-workspace-top,
    .dev-top.znp-workspace-top,
    .znp-investor-top.znp-workspace-top{
        min-height:54px!important;
        height:54px!important;
        padding:0 3%!important;
        gap:10px!important;
        flex-wrap:nowrap!important;
    }
    .znp-workspace-brand,
    .admin-brand.znp-workspace-brand,
    .dev-brand.znp-workspace-brand,
    .znp-investor-brand.znp-workspace-brand{
        min-height:34px!important;
        height:34px!important;
        padding:0 11px!important;
        font-size:14px!important;
    }
    .znp-workspace-brand span,.znp-workspace-brand strong{font-size:14px!important}
    .znp-portal-icons{margin-left:auto!important;padding:0!important;gap:5px!important}
    .znp-portal-icon{width:34px!important;height:34px!important;min-width:34px!important;min-height:34px!important}
    .znp-portal-icon[data-label]:after{display:none!important}
}
@media(max-width:420px){
    .znp-workspace-brand strong{display:inline!important}
}
</style>
<?php }
}
