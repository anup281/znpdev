<?php
declare(strict_types=1);

/**
 * ZNP shared UI component foundation.
 *
 * Components intentionally emit conservative markup and class names so pages
 * can adopt them incrementally without changing existing layouts.
 */

if (!function_exists('znp_component_escape')) {
    function znp_component_escape(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('znp_render_page_heading')) {
    function znp_render_page_heading(string $title, string $subtitle = '', string $actionsHtml = ''): void
    {
        echo '<div class="znp-page-heading">';
        echo '<div class="znp-page-heading-copy"><h1>' . znp_component_escape($title) . '</h1>';
        if (trim($subtitle) !== '') {
            echo '<p>' . znp_component_escape($subtitle) . '</p>';
        }
        echo '</div>';
        if (trim($actionsHtml) !== '') {
            echo '<div class="znp-page-heading-actions">' . $actionsHtml . '</div>';
        }
        echo '</div>';
    }
}

if (!function_exists('znp_render_card_start')) {
    function znp_render_card_start(string $title = '', string $subtitle = '', string $extraClass = ''): void
    {
        $class = trim('znp-ui-card ' . $extraClass);
        echo '<section class="' . znp_component_escape($class) . '">';
        if ($title !== '' || $subtitle !== '') {
            echo '<header class="znp-ui-card-header">';
            if ($title !== '') {
                echo '<h2>' . znp_component_escape($title) . '</h2>';
            }
            if ($subtitle !== '') {
                echo '<p>' . znp_component_escape($subtitle) . '</p>';
            }
            echo '</header>';
        }
        echo '<div class="znp-ui-card-body">';
    }
}

if (!function_exists('znp_render_card_end')) {
    function znp_render_card_end(): void
    {
        echo '</div></section>';
    }
}

if (!function_exists('znp_render_badge')) {
    function znp_render_badge(string $label, string $tone = 'neutral', string $extraClass = ''): void
    {
        $allowed = ['neutral', 'success', 'warning', 'danger', 'info'];
        $tone = in_array($tone, $allowed, true) ? $tone : 'neutral';
        $class = trim('znp-ui-badge znp-ui-badge-' . $tone . ' ' . $extraClass);
        echo '<span class="' . znp_component_escape($class) . '">' . znp_component_escape($label) . '</span>';
    }
}

if (!function_exists('znp_render_button')) {
    function znp_render_button(
        string $label,
        string $href = '',
        string $variant = 'primary',
        string $iconClass = '',
        array $attributes = []
    ): void {
        $allowed = ['primary', 'secondary', 'danger', 'text'];
        $variant = in_array($variant, $allowed, true) ? $variant : 'primary';
        $tag = $href !== '' ? 'a' : 'button';
        $attributes['class'] = trim('znp-ui-button znp-ui-button-' . $variant . ' ' . ($attributes['class'] ?? ''));
        if ($href !== '') {
            $attributes['href'] = $href;
        } elseif (!isset($attributes['type'])) {
            $attributes['type'] = 'button';
        }

        echo '<' . $tag;
        foreach ($attributes as $name => $value) {
            if ($value === null || $value === false) {
                continue;
            }
            if ($value === true) {
                echo ' ' . znp_component_escape($name);
                continue;
            }
            echo ' ' . znp_component_escape($name) . '="' . znp_component_escape($value) . '"';
        }
        echo '>';
        if ($iconClass !== '') {
            echo '<i class="' . znp_component_escape($iconClass) . '" aria-hidden="true"></i>';
        }
        echo '<span>' . znp_component_escape($label) . '</span></' . $tag . '>';
    }
}

if (!function_exists('znp_render_empty_state')) {
    function znp_render_empty_state(string $title, string $message = '', string $actionHtml = ''): void
    {
        echo '<div class="znp-ui-empty-state">';
        echo '<h3>' . znp_component_escape($title) . '</h3>';
        if ($message !== '') {
            echo '<p>' . znp_component_escape($message) . '</p>';
        }
        if ($actionHtml !== '') {
            echo '<div class="znp-ui-empty-action">' . $actionHtml . '</div>';
        }
        echo '</div>';
    }
}

if (!function_exists('znp_render_alert')) {
    function znp_render_alert(string $message, string $tone = 'info', string $title = ''): void
    {
        $allowed = ['success', 'warning', 'danger', 'info'];
        $tone = in_array($tone, $allowed, true) ? $tone : 'info';
        echo '<div class="znp-ui-alert znp-ui-alert-' . znp_component_escape($tone) . '" role="status">';
        if ($title !== '') {
            echo '<strong>' . znp_component_escape($title) . '</strong>';
        }
        echo '<span>' . znp_component_escape($message) . '</span></div>';
    }
}

if (!function_exists('znp_workspace_component_styles')) {
    function znp_workspace_component_styles(): void
    {
        static $rendered = false;
        if ($rendered) {
            return;
        }
        $rendered = true;
        echo <<<'HTML'
<style>
.znp-page-heading{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;margin:0 0 24px}.znp-page-heading h1{margin:0}.znp-page-heading p{margin:7px 0 0;color:#667487}.znp-page-heading-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.znp-ui-card{background:#fff;border:1px solid rgba(22,58,112,.12);border-radius:14px;overflow:hidden}.znp-ui-card-header{padding:20px 22px 0}.znp-ui-card-header h2{margin:0}.znp-ui-card-header p{margin:7px 0 0;color:#667487}.znp-ui-card-body{padding:20px 22px}
.znp-ui-badge{display:inline-flex;align-items:center;min-height:25px;padding:4px 9px;border-radius:999px;font-size:11px;font-weight:700;line-height:1.1}.znp-ui-badge-neutral{background:#eef2f7;color:#44546a}.znp-ui-badge-success{background:#e7f7ed;color:#176b39}.znp-ui-badge-warning{background:#fff4d6;color:#835d00}.znp-ui-badge-danger{background:#fde9e8;color:#a12622}.znp-ui-badge-info{background:#e8f1ff;color:#174f9b}
.znp-ui-button{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:40px;padding:9px 15px;border:1px solid transparent;border-radius:999px;font:inherit;font-weight:700;text-decoration:none;cursor:pointer}.znp-ui-button-primary{background:#163a70;color:#fff}.znp-ui-button-secondary{background:#fff;color:#163a70;border-color:rgba(22,58,112,.24)}.znp-ui-button-danger{background:#a12622;color:#fff}.znp-ui-button-text{background:transparent;color:#163a70;padding-left:5px;padding-right:5px}
.znp-ui-empty-state{text-align:center;padding:34px 22px;border:1px dashed rgba(22,58,112,.24);border-radius:14px;background:#fafbfd}.znp-ui-empty-state h3{margin:0}.znp-ui-empty-state p{max-width:560px;margin:8px auto 0;color:#667487}.znp-ui-empty-action{margin-top:17px}
.znp-ui-alert{display:flex;align-items:flex-start;gap:8px;padding:12px 14px;border-radius:10px;margin:0 0 16px}.znp-ui-alert-success{background:#e7f7ed;color:#176b39}.znp-ui-alert-warning{background:#fff4d6;color:#835d00}.znp-ui-alert-danger{background:#fde9e8;color:#a12622}.znp-ui-alert-info{background:#e8f1ff;color:#174f9b}
@media(max-width:700px){.znp-page-heading{align-items:flex-start;flex-direction:column}.znp-page-heading-actions{width:100%}.znp-ui-button{max-width:100%}}
</style>
HTML;
    }
}
