<?php
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$message = '';
$error = '';

// Process destructive actions before rendering the shared Admin header so
// redirects can be sent successfully and never result in a blank page.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'delete_project') {
    if (!csrf_check((string)($_POST['csrf_token'] ?? ''))) {
        header('Location: projects.php?delete_error=session');
        exit;
    }

    $id = (int)($_POST['project_id'] ?? 0);
    if ($id <= 0) {
        header('Location: projects.php?delete_error=invalid');
        exit;
    }

    try {
        $projectStatement = db()->prepare('SELECT project_name,primary_image FROM projects WHERE id = ? LIMIT 1');
        $projectStatement->execute([$id]);
        $project = $projectStatement->fetch();

        if (!$project) {
            header('Location: projects.php?delete_error=missing');
            exit;
        }

        $deleteStatement = db()->prepare('DELETE FROM projects WHERE id = ?');
        $deleteStatement->execute([$id]);
        if (!empty($project['primary_image']) && !app_delete_managed_file((string)$project['primary_image'], ['assets/images/projects'])) {
            error_log('Deleted project image could not be removed: '.$project['primary_image']);
        }

        header('Location: projects.php?deleted=1');
        exit;
    } catch (Throwable $exception) {
        error_log('ZNP project delete failed for project ID ' . $id . ': ' . $exception->getMessage());
        header('Location: projects.php?delete_error=database');
        exit;
    }
}

require __DIR__ . '/_header.php';

if (isset($_GET['deleted'])) {
    $message = 'Project deleted successfully.';
} elseif (isset($_GET['saved'])) {
    $message = 'Project saved successfully.';
} elseif (isset($_GET['delete_error'])) {
    $deleteError = (string)$_GET['delete_error'];
    $error = match ($deleteError) {
        'session' => 'Your session expired. Refresh the page and try deleting the project again.',
        'invalid' => 'The selected project could not be identified.',
        'missing' => 'That project no longer exists.',
        default => 'The project could not be deleted. No project data was changed.',
    };
}

$projects = db()->query(
    'SELECT *
     FROM projects
     ORDER BY
       FIELD(portfolio_category, "under_development", "commercial", "residential"),
       year_completed_or_expected DESC,
       display_order ASC,
       project_name ASC'
)->fetchAll();

$categories = [
    'under_development' => [
        'label' => 'Under Development',
        'description' => 'Projects currently in planning, permitting, development, or construction.',
    ],
    'commercial' => [
        'label' => 'Commercial',
        'description' => 'Completed and operating hospitality, multifamily, and commercial projects.',
    ],
    'residential' => [
        'label' => 'Residential',
        'description' => 'Completed residential homes and neighborhood projects.',
    ],
];

$grouped = array_fill_keys(array_keys($categories), []);

foreach ($projects as $project) {
    $category = $project['portfolio_category'];
    if (!isset($grouped[$category])) {
        $grouped[$category] = [];
    }
    $grouped[$category][] = $project;
}

function admin_currency($value): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    return '$' . number_format((float)$value, 0);
}
?>
<div class="admin-page-head">
  <div>
    <h1>Projects</h1>
    
  </div>
  <a class="primary" href="project_edit.php">Add Project</a>
</div>

<?php if ($message): ?>
  <div class="status success"><?= e($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="status error"><?= e($error) ?></div>
<?php endif; ?>

<div class="admin-project-groups">
  <?php foreach ($categories as $categoryKey => $category): ?>
    <section class="admin-project-group">
      <header class="admin-project-group__header">
        <div>
          <span class="admin-category-kicker">Project Category</span>
          <h2><?= e($category['label']) ?></h2>
          <p><?= e($category['description']) ?></p>
        </div>
        <span class="admin-count-badge">
          <?= count($grouped[$categoryKey] ?? []) ?> Project<?= count($grouped[$categoryKey] ?? []) === 1 ? '' : 's' ?>
        </span>
      </header>

      <div class="admin-project-list">
        <?php foreach ($grouped[$categoryKey] ?? [] as $project): ?>
          <article class="admin-project-row">
            <div class="admin-project-row__image">
              <?php if (!empty($project['primary_image'])): ?>
                <img src="../<?= e($project['primary_image']) ?>" alt="">
              <?php else: ?>
                <span>No Image</span>
              <?php endif; ?>
            </div>

            <div class="admin-project-row__main">
              <h3><?= e($project['project_name']) ?></h3>
              <p><?= e($project['city'] . ', ' . $project['state']) ?></p>

              <div class="admin-project-meta">
                <span>
                  <small>Value</small>
                  <strong><?= e(admin_currency($project['project_value'])) ?></strong>
                </span>
                <span>
                  <small><?= $categoryKey === 'under_development' ? 'Expected Completion' : 'Completed Year' ?></small>
                  <strong><?= e((string)($project['year_completed_or_expected'] ?: '—')) ?></strong>
                </span>
                <?php if (!empty($project['asset_type'])): ?>
                  <span>
                    <small>Asset Type</small>
                    <strong><?= e($project['asset_type']) ?></strong>
                  </span>
                <?php endif; ?>
                <span>
                  <small>Status</small>
                  <strong><?= e((string)($project['status'] ?: '—')) ?></strong>
                </span>
                <span>
                  <small>Public</small>
                  <strong><?= $project['is_visible'] ? 'Visible' : 'Hidden' ?></strong>
                </span>
              </div>
            </div>

            <div class="admin-project-row__actions">
              <a class="secondary admin-small-button" href="project_edit.php?id=<?= (int)$project['id'] ?>">Edit</a>
              <form method="post" class="admin-project-delete-form" onsubmit="return confirm('Delete this project? This action cannot be undone.');">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete_project">
                <input type="hidden" name="project_id" value="<?= (int)$project['id'] ?>">
                <button type="submit" class="admin-danger-link">Delete</button>
              </form>
            </div>
          </article>
        <?php endforeach; ?>

        <?php if (empty($grouped[$categoryKey])): ?>
          <div class="admin-empty-state">
            No projects are assigned to <?= e($category['label']) ?>.
          </div>
        <?php endif; ?>
      </div>
    </section>
  <?php endforeach; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
