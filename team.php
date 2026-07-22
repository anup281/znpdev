<?php
$pageTitle = 'Team | ZNP Development';
$activePage = 'team';

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

$team = db()->query(
    "SELECT *
     FROM team_members
     WHERE is_active = 1
     ORDER BY display_order ASC, full_name ASC"
)->fetchAll();

require __DIR__ . '/includes/header.php';
?>
<main>
  <?php render_public_page_header('team','Who We Are','Experienced professionals committed to creating lasting value through thoughtful development, disciplined execution, and long-term ownership.','projects-blueprint'); ?>

  <section class="section soft team-page-simple">
    <div class="container executive-list">
      <?php if (!$team): ?>
        <div class="team-empty-state">
          <h2>Leadership profiles are being updated.</h2>
          <p>Please check back soon.</p>
        </div>
      <?php endif; ?>

      <?php foreach ($team as $index => $member): ?>
        <?php $image = trim((string)($member['headshot'] ?? '')); ?>
        <article class="executive-profile">
          <div>
            <?php if ($image !== ''): ?>
              <img
                class="executive-photo"
                src="<?= e($image) ?>"
                alt="<?= e($member['full_name']) ?>"
                loading="<?= $index === 0 ? 'eager' : 'lazy' ?>"
                decoding="async"
              >
            <?php else: ?>
              <div class="portrait-placeholder" aria-hidden="true">
                <?= e(strtoupper(substr((string)$member['full_name'], 0, 1))) ?>
              </div>
            <?php endif; ?>
          </div>

          <div>
            <h2><?= e($member['full_name']) ?></h2>
            <div class="executive-title"><?= e($member['title']) ?></div>

            <?php if (!empty($member['specialty_line'])): ?>
              <div class="executive-specialty"><?= e($member['specialty_line']) ?></div>
            <?php endif; ?>

            <?php
              $paragraphs = preg_split('/\R{2,}/', trim((string)($member['bio'] ?? '')));
              foreach ($paragraphs as $paragraph):
                if (trim($paragraph) === '') continue;
            ?>
              <p><?= nl2br(e(trim($paragraph))) ?></p>
            <?php endforeach; ?>

            <?php if (trim((string)($member['hobbies'] ?? '')) !== ''): ?>
              <div class="executive-hobbies"><strong>Hobbies</strong><p><?= nl2br(e(trim((string)$member['hobbies']))) ?></p></div>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
