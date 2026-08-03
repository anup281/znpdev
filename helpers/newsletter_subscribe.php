<?php
declare(strict_types=1);
require __DIR__.'/../includes/db.php';
require __DIR__.'/../includes/functions.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$isJson = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
    || str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');

function newsletter_signup_response(bool $ok, string $message, string $code = '', int $status = 200): never
{
    global $isJson;
    if ($isJson) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'message' => $message, 'code' => $code], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $back = (string)($_SERVER['HTTP_REFERER'] ?? app_url('/'));
    $fragment = '';
    if (str_contains($back, '#')) {
        [$back, $fragment] = explode('#', $back, 2);
        $fragment = '#'.$fragment;
    }
    $separator = str_contains($back, '?') ? '&' : '?';
    header('Location: '.$back.$separator.'newsletter='.rawurlencode($ok ? 'ok' : 'error').'&newsletter_message='.rawurlencode($message).$fragment);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    newsletter_signup_response(false, 'Invalid request.', 'invalid_request', 405);
}

if (!csrf_check((string)($_POST['csrf_token'] ?? ''))) {
    newsletter_signup_response(false, 'Your session expired. Refresh the page and try again.', 'csrf', 419);
}

// Honeypot: return a neutral success so automated submissions receive no useful signal.
if (trim((string)($_POST['website'] ?? '')) !== '') {
    newsletter_signup_response(true, 'Thank you for subscribing.', 'ok');
}

$name = trim((string)($_POST['full_name'] ?? ''));
$email = strtolower(trim((string)($_POST['email'] ?? '')));

if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    newsletter_signup_response(false, 'Please enter a valid email address.', 'invalid_email', 422);
}

try {
    $existing = db()->prepare('SELECT id, status FROM newsletter_subscribers WHERE LOWER(email) = ? LIMIT 1');
    $existing->execute([$email]);
    $row = $existing->fetch();

    if ($row && (string)$row['status'] === 'active') {
        newsletter_signup_response(true, "You're already subscribed to our newsletter.", 'already_subscribed');
    }

    $result = newsletter_subscribe($email, $name, 'footer');
    if (!$result['ok']) {
        newsletter_signup_response(false, (string)($result['message'] ?? 'Unable to subscribe right now.'), 'failed', 422);
    }

    newsletter_signup_response(true, 'Thank you for subscribing!', 'subscribed');
} catch (Throwable $e) {
    error_log('Newsletter signup failed: '.$e->getMessage());
    newsletter_signup_response(false, 'We could not complete your subscription right now. Please try again.', 'server_error', 500);
}
