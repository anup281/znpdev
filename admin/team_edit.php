<?php
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$id = (int) ($_GET['id'] ?? 0);
$error = '';

$member = [
    'full_name' => '',
    'title' => '',
    'specialty_line' => '',
    'bio' => '',
    'hobbies' => '',
    'headshot' => '',
    'display_order' => 10,
    'is_active' => 1,
];

if ($id > 0) {
    $stmt = db()->prepare('SELECT * FROM team_members WHERE id = ?');
    $stmt->execute([$id]);
    $member = $stmt->fetch() ?: $member;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Refresh the page and try again.';
    } else {
        $fullName = trim($_POST['full_name'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $specialty = trim($_POST['specialty_line'] ?? '');
        $bio = trim($_POST['bio'] ?? '');
        $hobbies = trim($_POST['hobbies'] ?? '');
        $displayOrder = (int) ($_POST['display_order'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $headshot = trim($_POST['existing_headshot'] ?? '');

        if ($fullName === '' || $title === '') {
            $error = 'Name and title are required.';
        }

        if (!$error && isset($_FILES['headshot']) && $_FILES['headshot']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['headshot']['error'] !== UPLOAD_ERR_OK) {
                $error = 'The image upload failed.';
            } elseif ($_FILES['headshot']['size'] > 5 * 1024 * 1024) {
                $error = 'The image must be smaller than 5 MB.';
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($_FILES['headshot']['tmp_name']);
                $extensions = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                ];

                if (!isset($extensions[$mime])) {
                    $error = 'Upload a JPG, PNG, or WebP image.';
                } else {
                    $uploadDir = __DIR__ . '/../assets/images/team';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }

                    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $fullName), '-'));
                    $filename = $slug . '-' . time() . '.' . $extensions[$mime];
                    $destination = $uploadDir . '/' . $filename;

                    if (!move_uploaded_file($_FILES['headshot']['tmp_name'], $destination)) {
                        $error = 'The image could not be saved.';
                    } else {
                        $headshot = 'assets/images/team/' . $filename;
                    }
                }
            }
        }

        if (!$error) {
            $values = [
                $fullName,
                $title,
                $specialty ?: null,
                $bio ?: null,
                $hobbies ?: null,
                $headshot ?: null,
                $displayOrder,
                $isActive,
            ];

            if ($id > 0) {
                $values[] = $id;
                $sql = 'UPDATE team_members
                        SET full_name=?, title=?, specialty_line=?, bio=?, hobbies=?,
                            headshot=?, display_order=?, is_active=?
                        WHERE id=?';
            } else {
                $sql = 'INSERT INTO team_members
                        (full_name,title,specialty_line,bio,hobbies,headshot,display_order,is_active)
                        VALUES (?,?,?,?,?,?,?,?)';
            }

            try {
                db()->prepare($sql)->execute($values);
                header('Location: team.php?saved=1');
                exit;
            } catch (Throwable $exception) {
                error_log('Team member save failed: ' . $exception->getMessage());
                $error = 'The team member could not be saved. Please try again.';
            }
        }

        $member = array_merge($member, [
            'full_name' => $fullName,
            'title' => $title,
            'specialty_line' => $specialty,
            'bio' => $bio,
            'hobbies' => $hobbies,
            'headshot' => $headshot,
            'display_order' => $displayOrder,
            'is_active' => $isActive,
        ]);
    }
}
?>
<?php require __DIR__ . '/_header.php'; ?>
<div class="admin-page-head">
  <div>
    <h1><?= $id ? 'Edit' : 'Add' ?> Team Member</h1>
    <p>Changes appear on the public Team page immediately.</p>
  </div>
  <a class="secondary" href="team.php">Back to Team</a>
</div>

<?php if ($error): ?>
  <div class="status error"><?= e($error) ?></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="admin-form admin-form-wide">
  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="existing_headshot" value="<?= e((string)$member['headshot']) ?>">

  <div class="admin-form-grid">
    <div>
      <label for="full_name">Full Name *</label>
      <input id="full_name" name="full_name" required value="<?= e((string)$member['full_name']) ?>">
    </div>
    <div>
      <label for="title">Title *</label>
      <input id="title" name="title" required value="<?= e((string)$member['title']) ?>">
    </div>
  </div>

  <label for="specialty_line">Specialty Line</label>
  <input id="specialty_line" name="specialty_line"
         value="<?= e((string)$member['specialty_line']) ?>"
         placeholder="Development Leadership • Acquisitions • Project Execution">

  <label for="bio">Full Biography</label>
  <textarea id="bio" name="bio" class="admin-bio-field"
            placeholder="Separate paragraphs with a blank line."><?= e((string)$member['bio']) ?></textarea>

  <label for="hobbies">Hobbies</label>
  <textarea id="hobbies" name="hobbies" rows="3" placeholder="Travel, golf, architecture, and spending time with family."><?= e((string)($member['hobbies'] ?? '')) ?></textarea>

  <div class="admin-form-grid">
    <div>
      <label for="headshot">Headshot</label>
      <input id="headshot" type="file" name="headshot" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
      <small>JPG, PNG, or WebP. Maximum 5 MB.</small>
    </div>
    <div>
      <label for="display_order">Display Order</label>
      <input id="display_order" type="number" name="display_order"
             value="<?= e((string)$member['display_order']) ?>">
    </div>
  </div>

  <?php if (!empty($member['headshot'])): ?>
    <div class="admin-current-photo">
      <span>Current Photo</span>
      <img src="../<?= e((string)$member['headshot']) ?>" alt="">
    </div>
  <?php endif; ?>

  <label class="admin-checkbox">
    <input type="checkbox" name="is_active" <?= $member['is_active'] ? 'checked' : '' ?>>
    Show this person on the public Team page
  </label>

  <button class="primary" type="submit">Save Team Member</button>
</form>

<?php require __DIR__ . '/_footer.php'; ?>
