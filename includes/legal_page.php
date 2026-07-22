<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

function znp_render_legal_page(string $slug, string $fallbackTitle): void
{
    try {
        $stmt = db()->prepare('SELECT title, content, updated_at FROM legal_pages WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $page = $stmt->fetch();
    } catch (Throwable $exception) {
        $page = false;
    }

    if (!$page) {
        http_response_code(503);
        $page = [
            'title' => $fallbackTitle,
            'content' => '<p>This page is temporarily unavailable. Please check back soon.</p>',
            'updated_at' => null,
        ];
    }

    $pageTitle = (string)$page['title'] . ' | ZNP Development';
    $plainText = trim(preg_replace('/\s+/', ' ', strip_tags((string)$page['content'])) ?? '');
    $description = mb_substr($plainText, 0, 155);
    $pageSeo = [
        'title' => $pageTitle,
        'description' => $description,
        'og_title' => (string)$page['title'] . ' | ZNP Development',
        'og_description' => $description,
    ];
    $subtitles = [
        'privacy' => 'Learn how ZNP Development collects, uses, and protects your information.',
        'terms' => 'The terms governing the use of the ZNP Development website and services.',
        'cookies' => 'Information about how cookies are used to support and improve your browsing experience.',
        'accessibility' => 'Our commitment to providing an accessible and inclusive website experience.',
    ];
    $subtitle = $subtitles[$slug] ?? '';

    $activePage = 'legal';
    $bodyClass = 'legal-page';
    require __DIR__ . '/header.php';
    ?>
    <main>
      <section class="blueprint-banner legal-page-banner">
        <div class="container">
          <h1><?= e((string)$page['title']) ?></h1>
          <?php if ($subtitle !== ''): ?>
            <p><?= e($subtitle) ?></p>
          <?php endif; ?>
        </div>
      </section>
      <section class="section soft legal-page-section">
        <div class="container">
          <article class="legal-page-card">
            <?php if (!empty($page['updated_at'])): ?>
              <p class="legal-page-updated">Last updated <?= e(date('F j, Y', strtotime((string)$page['updated_at']))) ?></p>
            <?php endif; ?>
            <div class="legal-page-content"><?= (string)$page['content'] ?></div>
          </article>
        </div>
      </section>
    </main>
    <?php
    require __DIR__ . '/footer.php';
}
