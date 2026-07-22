<?php
declare(strict_types=1);

session_start();

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/functions.php';

$success = '';
$error = '';
$values = [
    'name' => '',
    'email' => '',
    'phone' => '',
    'source_name' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values = [
        'name' => trim((string)($_POST['name'] ?? '')),
        'email' => strtolower(trim((string)($_POST['email'] ?? ''))),
        'phone' => trim((string)($_POST['phone'] ?? '')),
        'source_name' => trim((string)($_POST['source_name'] ?? '')),
    ];

    if (!csrf_check((string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Refresh the page and try again.';
    } elseif (!empty($_POST['website'] ?? '')) {
        // Honeypot: respond like a successful submission without storing spam.
        $success = 'Thank you. The lead has been submitted.';
        $values = ['name' => '', 'email' => '', 'phone' => '', 'source_name' => ''];
    } elseif ($values['name'] === '') {
        $error = 'Enter the lead name.';
    } elseif (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif ($values['phone'] === '') {
        $error = 'Enter a phone number.';
    } elseif ($values['source_name'] === '') {
        $error = 'Enter the source name.';
    } else {
        try {
            $reference = 'LEAD-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

            $statement = db()->prepare(
                'INSERT INTO leads
                 (reference_number, full_name, email, phone, source_name, status, source_page, ip_address, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            $statement->execute([
                $reference,
                $values['name'],
                $values['email'],
                $values['phone'],
                $values['source_name'],
                'new',
                '/lead',
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);

            $leadId=(int)db()->lastInsertId();contact_activity_log('lead',$leadId,'submitted','Contact submitted','Internal Lead Tracking form',null,null,date('Y-m-d H:i:s'));
            $success = 'Thank you. The lead has been submitted.';
            $values = ['name' => '', 'email' => '', 'phone' => '', 'source_name' => ''];
        } catch (Throwable $exception) {
            $error = 'The lead could not be saved. Please try again.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <meta name="theme-color" content="#0b1f3a">
  <title>Lead Tracking | ZNP Development</title>
  <link rel="stylesheet" href="../assets/site.css">
</head>
<body class="lead-entry-page">
<main class="lead-entry-shell">
  <section class="lead-entry-card">
    <div class="lead-entry-brand">ZNP Development</div>
    <span class="lead-entry-kicker">Internal Lead Tracking</span>
    <h1>Submit a Lead</h1>
    <p class="lead-entry-intro">
      Enter the lead’s contact information and the person, campaign, or relationship that generated the lead.
    </p>

    <?php if ($success): ?>
      <div class="status success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="status error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" class="lead-entry-form" autocomplete="on">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

      <div class="hp" aria-hidden="true">
        <label for="website">Website</label>
        <input id="website" name="website" tabindex="-1" autocomplete="off">
      </div>

      <div class="field">
        <label for="name">Name *</label>
        <input
          id="name"
          name="name"
          required
          autocomplete="name"
          value="<?= e($values['name']) ?>"
        >
      </div>

      <div class="form-grid">
        <div class="field">
          <label for="email">Email *</label>
          <input
            id="email"
            type="email"
            name="email"
            required
            autocomplete="email"
            value="<?= e($values['email']) ?>"
          >
        </div>

        <div class="field">
          <label for="phone">Phone *</label>
          <input
            id="phone"
            type="tel"
            name="phone"
            required
            autocomplete="tel"
            value="<?= e($values['phone']) ?>"
          >
        </div>
      </div>

      <div class="field">
        <label for="source_name">Source Name *</label>
        <input
          id="source_name"
          name="source_name"
          required
          placeholder="Example: Rahim Gangwani, Broker Referral, LinkedIn"
          value="<?= e($values['source_name']) ?>"
        >
      </div>

      <button class="primary lead-submit-button" type="submit">
        Submit Lead →
      </button>
    </form>

    <p class="lead-entry-note">
      This page is not linked from the public website. Anyone with the URL can access the form.
    </p>
  </section>
</main>
</body>
</html>
