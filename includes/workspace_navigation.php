<?php
declare(strict_types=1);

/**
 * Shared workspace navigation and permission foundation.
 *
 * This file owns only the cross-workspace icon rail and reusable access checks.
 * Portal-specific menus remain in their existing Admin, Construction, and
 * Investor header files.
 */

if (!function_exists('znp_workspace_navigation_config')) {
    function znp_workspace_navigation_config(): array
    {
        return [
            'admin' => [
                'label' => 'Admin Portal',
                'href' => '/admin/',
                'icon' => 'fa-solid fa-shield-halved',
                'permission' => 'workspace.admin',
            ],
            'construction' => [
                'label' => 'Construction Portal',
                'href' => '/dev/',
                'icon' => 'fa-solid fa-hammer',
                'permission' => 'workspace.construction',
            ],
            'investor' => [
                'label' => 'Investor Portal',
                'href' => '/portal/',
                'icon' => 'fa-solid fa-people-group',
                'permission' => 'workspace.investor_shortcut',
            ],
            'management' => [
                'label' => 'Management Portal',
                'href' => '/manage/',
                'icon' => 'fa-solid fa-briefcase',
                'permission' => 'workspace.management',
            ],
            'public' => [
                'label' => 'Public Website',
                'href' => '/',
                'icon' => 'fa-solid fa-house',
                'permission' => 'workspace.public',
            ],
            'logout' => [
                'label' => 'Logout',
                'href' => '/logout.php',
                'icon' => 'fa-solid fa-right-from-bracket',
                'permission' => 'workspace.logout',
                'class' => 'znp-logout-icon',
            ],
        ];
    }
}

if (!function_exists('znp_workspace_base_path')) {
    /**
     * Return the directory in which this copy of the platform is installed.
     * Examples: /dev/index.php => "", /test/dev/index.php => "/test".
     */
    function znp_workspace_base_path(): string
    {
        if (function_exists('app_base_path')) {
            return app_base_path();
        }
        $script = '/' . ltrim(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        if (preg_match('#^(.*?)/(?:admin|dev|portal|manage)(?:/|$)#', $script, $matches)) {
            return rtrim((string)$matches[1], '/');
        }
        return '';
    }
}

if (!function_exists('znp_workspace_url')) {
    function znp_workspace_url(string $path): string
    {
        if (function_exists('app_url')) {
            return app_url($path);
        }
        if ($path === '' || $path[0] !== '/') {
            return $path;
        }
        $base = znp_workspace_base_path();
        return $base . $path;
    }
}

if (!function_exists('znp_workspace_identity')) {
    function znp_workspace_identity(): array
    {
        $staff = function_exists('admin_user') ? admin_user() : null;
        $investor = function_exists('investor_user') ? investor_user() : null;

        return [
            'staff' => is_array($staff),
            'admin' => is_array($staff)
                && function_exists('admin_portal_role')
                && admin_portal_role($staff),
            'investor' => is_array($investor),
        ];
    }
}

if (!function_exists('znp_can_access')) {
    /**
     * Reusable permission check for shared workspace capabilities.
     * Additional module permissions can be added later without changing
     * the portal headers that render the icon rail.
     */
    function znp_can_access(string $permission): bool
    {
        $identity = znp_workspace_identity();

        switch ($permission) {
            case 'workspace.admin':
                return $identity['admin'];

            case 'workspace.construction':
                return $identity['staff'];

            case 'workspace.investor_shortcut':
                // Investor-only lead/inquiry sessions intentionally do not
                // see internal workspace-switching shortcuts.
                return $identity['staff'];

            case 'workspace.management':
                if (!$identity['staff'] || !function_exists('admin_user')) {
                    return false;
                }
                $staff = admin_user();
                $role = function_exists('normalized_role')
                    ? normalized_role((string)($staff['role'] ?? ''))
                    : strtolower(str_replace('_', ' ', (string)($staff['role'] ?? '')));
                return in_array($role, ['super admin', 'super administrator'], true);

            case 'workspace.public':
            case 'workspace.logout':
                return $identity['staff'] || $identity['investor'];

            default:
                return false;
        }
    }
}

if (!function_exists('znp_workspace_access')) {
    /**
     * Backward-compatible access summary retained for existing code.
     */
    function znp_workspace_access(): array
    {
        $identity = znp_workspace_identity();

        return [
            'staff' => $identity['staff'],
            'admin' => znp_can_access('workspace.admin'),
            'construction' => znp_can_access('workspace.construction'),
            'investor' => $identity['investor'],
        ];
    }
}

if (!function_exists('znp_render_workspace_icons')) {
    function znp_render_workspace_icons(string $activePortal): void
    {
        $config = znp_workspace_navigation_config();
        echo '<div class="znp-portal-icons" aria-label="Portal navigation">';

        foreach ($config as $key => $item) {
            if (!znp_can_access((string)$item['permission'])) {
                continue;
            }

            $classes = ['znp-portal-icon'];
            if ($key === $activePortal && in_array($key, ['admin', 'construction', 'investor', 'management'], true)) {
                $classes[] = 'is-active';
            }
            if (!empty($item['class'])) {
                $classes[] = (string)$item['class'];
            }

            $attributes = [
                'class="' . htmlspecialchars(implode(' ', $classes), ENT_QUOTES, 'UTF-8') . '"',
                'href="' . htmlspecialchars(znp_workspace_url((string)$item['href']), ENT_QUOTES, 'UTF-8') . '"',
                'data-label="' . htmlspecialchars((string)$item['label'], ENT_QUOTES, 'UTF-8') . '"',
                'aria-label="' . htmlspecialchars((string)$item['label'], ENT_QUOTES, 'UTF-8') . '"',
            ];

            if ($key === $activePortal && in_array($key, ['admin', 'construction', 'investor', 'management'], true)) {
                $attributes[] = 'aria-current="page"';
            }
            if (!empty($item['target'])) {
                $attributes[] = 'target="' . htmlspecialchars((string)$item['target'], ENT_QUOTES, 'UTF-8') . '"';
            }
            if (!empty($item['rel'])) {
                $attributes[] = 'rel="' . htmlspecialchars((string)$item['rel'], ENT_QUOTES, 'UTF-8') . '"';
            }

            echo '<a ' . implode(' ', $attributes) . '>';
            echo '<i class="' . htmlspecialchars((string)$item['icon'], ENT_QUOTES, 'UTF-8') . '" aria-hidden="true"></i>';
            echo '</a>';
        }

        echo '</div>';
    }
}
