<?php
declare(strict_types=1);

/**
 * Shared workspace footer foundation.
 * Keeps portal-specific scripts and controls in their existing footer files,
 * while centralizing footer markup, escaping, version labels, and document close.
 */

if (!function_exists('znp_workspace_escape')) {
    function znp_workspace_escape(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('znp_render_workspace_status')) {
    /**
     * Render a safely escaped success/error notice using the existing .status UI.
     * This is a foundation helper; existing pages can migrate to it incrementally.
     */
    function znp_render_workspace_status(?string $message, string $type = 'success'): void
    {
        $message = trim((string)$message);
        if ($message === '') {
            return;
        }

        $allowedTypes = ['success', 'error', 'warning', 'info'];
        $type = in_array($type, $allowedTypes, true) ? $type : 'info';
        echo '<div class="status ' . znp_workspace_escape($type) . '" role="status">'
            . znp_workspace_escape($message)
            . '</div>';
    }
}


if (!function_exists('znp_application_version_label')) {
    function znp_application_version_label(string $unused = ''): string
    {
        $version = function_exists('znp_application_version') ? znp_application_version() : (defined('ZNP_APPLICATION_VERSION') ? ZNP_APPLICATION_VERSION : 'Unknown');
        return 'ZNP Development Platform | Version ' . $version;
    }
}

if (!function_exists('znp_render_workspace_footer')) {
    /** Render a consistent three-part footer for every workspace. */
    function znp_render_workspace_footer(
        string $workspaceLabel,
        string $versionLabel = '',
        string $userLabel = '',
        string $extraClass = ''
    ): void {
        $class = trim('znp-workspace-footer ' . $extraClass);
        if (trim($versionLabel) === '') {
            $versionLabel = znp_application_version_label();
        }
        echo '<footer class="' . znp_workspace_escape($class) . '">';
        echo '<span>&copy; ' . date('Y') . ' ZNP Development</span>';
        echo '<span>' . znp_workspace_escape(znp_application_version_label()) . '</span>'; 
        if ($userLabel !== '') {
            echo '<span>' . znp_workspace_escape($userLabel) . '</span>';
        }
        echo '</footer>';
    }
}

if (!function_exists('znp_workspace_footer_styles')) {
    function znp_workspace_footer_styles(): void
    {
        echo <<<'HTML'
<style>
.znp-workspace-footer{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:18px;
    width:100%;
    min-height:54px;
    padding:14px 3%;
    border-top:1px solid rgba(22,58,112,.12);
    background:#fff;
    color:#667487;
    font-family:Arial,sans-serif;
    font-size:12px;
    line-height:1.35;
}
.znp-workspace-footer span:nth-child(2){text-align:center}
.znp-workspace-footer span:last-child{text-align:right}
@media(max-width:700px){
    .znp-workspace-footer{
        flex-direction:column;
        align-items:flex-start;
        gap:4px;
        padding-top:13px;
        padding-bottom:13px;
    }
    .znp-workspace-footer span:nth-child(2),
    .znp-workspace-footer span:last-child{text-align:left}
}
</style>
HTML;
    }
}

if (!function_exists('znp_workspace_document_end')) {
    /** Close the shared workspace document after portal-specific scripts run. */
    function znp_workspace_document_end(): void
    {
        echo '</body></html>';
    }
}
