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
