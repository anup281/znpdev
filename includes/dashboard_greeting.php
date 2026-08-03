<?php
declare(strict_types=1);

if (!function_exists('znp_render_dashboard_greeting')) {
    /**
     * Render the shared portal dashboard greeting, live clock, and date.
     */
    function znp_render_dashboard_greeting(
        string $userName,
        string $headingId,
        string $timezone = 'America/Chicago'
    ): void {
        $userName = trim($userName) !== '' ? trim($userName) : 'Team Member';

        try {
            $now = new DateTimeImmutable('now', new DateTimeZone($timezone));
        } catch (Throwable $exception) {
            $timezone = 'America/Chicago';
            $now = new DateTimeImmutable('now', new DateTimeZone($timezone));
        }

        $hour = (int)$now->format('G');
        $greeting = $hour < 12 ? 'Good Morning' : ($hour < 17 ? 'Good Afternoon' : 'Good Evening');
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        echo '<div class="admin-portal-hero znp-dashboard-greeting" data-znp-dashboard-greeting data-timezone="' . $escape($timezone) . '">';
        echo '<h1 id="' . $escape($headingId) . '">' . $escape($greeting . ' ' . $userName) . '</h1>';
        echo '<div class="admin-portal-time" data-znp-dashboard-time>' . $escape($now->format('g:i:s A')) . '</div>';
        echo '<div class="admin-portal-date" data-znp-dashboard-date>' . $escape($now->format('l, F j, Y')) . '</div>';
        echo '</div>';
    }
}
