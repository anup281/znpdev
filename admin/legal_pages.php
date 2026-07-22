<?php
require __DIR__ . '/_header.php';

$message = isset($_GET['saved']) ? 'Legal page saved successfully.' : '';
$error = '';

try {
    $pages = db()->query("SELECT id, slug, title, updated_at FROM legal_pages ORDER BY FIELD(slug,'privacy','terms','cookies','accessibility'), title")->fetchAll();
} catch (Throwable $exception) {
    $pages = [];
    $error = 'The Legal Pages installer has not been run yet. Upload the patch and run /install_v5_2_7.php while signed in as an administrator.';
}

$urls = [
    'privacy' => '/privacy',
    'terms' => '/terms',
    'cookies' => '/cookies',
    'accessibility' => '/accessibility',
];
?>
<div class="admin-page-head">
  <div>
    <h1>Legal Pages</h1>
    <p>Edit the legal information shown in the public website footer.</p>
  </div>
</div>

<?php if ($message): ?><div class="status success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="status error"><?= e($error) ?></div><?php endif; ?>

<div class="admin-legal-list">
  <?php foreach ($pages as $page): ?>
    <?php $url = $urls[$page['slug']] ?? '/' . $page['slug']; ?>
    <article class="admin-legal-card">
      <div>
        <h2><?= e((string)$page['title']) ?></h2>
        <p><?= !empty($page['updated_at']) ? 'Last updated ' . e(date('F j, Y', strtotime((string)$page['updated_at']))) : 'Not updated yet' ?></p>
      </div>
      <div class="admin-legal-actions">
        <a class="secondary admin-small-button" href="<?= e($url) ?>" target="_blank" rel="noopener">View</a>
        <a class="primary admin-small-button" href="legal_page_edit.php?id=<?= (int)$page['id'] ?>">Edit</a>
      </div>
    </article>
  <?php endforeach; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
