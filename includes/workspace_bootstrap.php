<?php
declare(strict_types=1);

/**
 * ZNP shared workspace bootstrap foundation.
 *
 * Centralizes the safe, repeated setup used by protected workspaces while
 * leaving portal-specific business logic in each portal. Requiring this file
 * alone does not redirect or output markup; call znp_workspace_bootstrap().
 */

require_once __DIR__ . '/auth.php';

if (!function_exists('znp_application_version')) {
    function znp_application_version(): string
    {
        $fallback = '5.2.0';
        try {
            $value = trim((string)setting('application_version', $fallback));
        } catch (Throwable $e) {
            $value = $fallback;
        }
        return preg_match('/^[0-9]+(?:\.[0-9A-Za-z_-]+)*$/', $value) ? $value : $fallback;
    }
}

if (!defined('ZNP_APPLICATION_VERSION')) {
    define('ZNP_APPLICATION_VERSION', znp_application_version());
}

if (!function_exists('znp_application_version_label')) {
    function znp_application_version_label(string $unused = ''): string
    {
        return 'ZNP Development Platform | Version ' . znp_application_version();
    }
}

if (!function_exists('znp_asset_version')) {
    function znp_asset_version(): string
    {
        return preg_replace('/[^0-9A-Za-z_-]/', '', znp_application_version()) ?: '531';
    }
}

if (!function_exists('znp_flash_set')) {
    function znp_flash_set(string $type, string $message): void
    {
        $_SESSION['_znp_workspace_flash'] = [
            'type' => $type,
            'message' => $message,
        ];
    }
}

if (!function_exists('znp_flash_pull')) {
    function znp_flash_pull(): ?array
    {
        $flash = $_SESSION['_znp_workspace_flash'] ?? null;
        unset($_SESSION['_znp_workspace_flash']);

        if (!is_array($flash) || !isset($flash['message'])) {
            return null;
        }

        return [
            'type' => (string)($flash['type'] ?? 'info'),
            'message' => (string)$flash['message'],
        ];
    }
}

if (!function_exists('znp_workspace_redirect_to_login')) {
    function znp_workspace_redirect_to_login(string $returnKey, string $loginUrl): void
    {
        $_SESSION[$returnKey] = $_SERVER['REQUEST_URI'] ?? '/';
        header('Location: ' . app_url($loginUrl));
        exit;
    }
}

if (!function_exists('znp_workspace_bootstrap')) {
    /**
     * Initialize a protected ZNP workspace and return normalized request data.
     *
 * Supported workspaces: admin, construction, management.
     * Options:
     * - login_url: override the authentication redirect URL.
     * - return_key: override the session key storing the requested URL.
     * - load_ui: load shared header/footer helpers (default true).
     */
    function znp_workspace_bootstrap(string $workspace, array $options = []): array
    {
        $workspace = strtolower(trim($workspace));
        if (!in_array($workspace, ['admin', 'construction', 'management'], true)) {
            throw new InvalidArgumentException('Unknown ZNP workspace: ' . $workspace);
        }

        $defaults = [
            'admin' => [
                'login_url' => '/login.php',
                'return_key' => 'login_return',
            ],
            'construction' => [
                'login_url' => '/admin/login.php',
                'return_key' => 'dev_return',
            ],
            'management' => [
                'login_url' => '/login.php',
                'return_key' => 'management_return',
            ],
        ];

        $loginUrl = (string)($options['login_url'] ?? $defaults[$workspace]['login_url']);
        $returnKey = (string)($options['return_key'] ?? $defaults[$workspace]['return_key']);
        $loadUi = !array_key_exists('load_ui', $options) || (bool)$options['load_ui'];

        $staff = admin_user();
        if ($workspace === 'admin') {
            if (!$staff) {
                znp_workspace_redirect_to_login($returnKey, $loginUrl);
            }
            if (!admin_portal_role($staff)) {
                header('Location: '.app_url('/dev/'));
                exit;
            }
            $user = $staff;
        } elseif ($workspace === 'construction') {
            if (!$staff) {
                znp_workspace_redirect_to_login($returnKey, $loginUrl);
            }
            $user = $staff;
        } elseif ($workspace === 'management') {
            if (!$staff) {
                znp_workspace_redirect_to_login($returnKey, $loginUrl);
            }
            $normalizedRole = function_exists('normalized_role') ? normalized_role((string)($staff['role'] ?? '')) : strtolower(str_replace('_', ' ', (string)($staff['role'] ?? '')));
            if (!in_array($normalizedRole, ['super admin', 'super administrator'], true)) {
                header('Location: '.app_url('/admin/'));
                exit;
            }
            $user = $staff;
        }

        if ($loadUi) {
            require_once __DIR__ . '/workspace_header.php';
            require_once __DIR__ . '/workspace_footer.php';
            require_once __DIR__ . '/workspace_components.php';
        }

        return [
            'workspace' => $workspace,
            'user' => is_array($user) ? $user : [],
            'staff_user' => is_array($staff) ? $staff : null,
            'current_page' => basename((string)($_SERVER['PHP_SELF'] ?? 'index.php')),
            'request_uri' => (string)($_SERVER['REQUEST_URI'] ?? '/'),
            'application_version' => znp_application_version(),
            'flash' => znp_flash_pull(),
        ];
    }
}
