<?php
require_once __DIR__ . '/../includes/auth.php';
require_admin();

function znp_clean_legal_html(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    $allowed = '<p><br><h2><h3><h4><strong><b><em><i><u><ul><ol><li><a><blockquote><hr>';
    $html = strip_tags($html, $allowed);

    // Remove event handlers and inline styling from all saved markup.
    $html = preg_replace('/\s(?:on\w+|style|class|id)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;
    $html = preg_replace('/\s(?:href)\s*=\s*("|\')\s*(?:javascript|data):[^"\']*\1/i', '', $html) ?? $html;

    // Restrict links to href, target and rel attributes.
    $html = preg_replace_callback('/<a\b([^>]*)>/i', static function (array $match): string {
        $attrs = $match[1];
        $href = '';
        if (preg_match('/href\s*=\s*("|\')([^"\']*)\1/i', $attrs, $hrefMatch)) {
            $candidate = trim($hrefMatch[2]);
            if (preg_match('#^(https?://|mailto:|tel:|/|#)#i', $candidate)) {
                $href = ' href="' . htmlspecialchars($candidate, ENT_QUOTES, 'UTF-8') . '"';
            }
        }
        $external = stripos($href, 'http') !== false ? ' target="_blank" rel="noopener"' : '';
        return '<a' . $href . $external . '>';
    }, $html) ?? $html;

    return $html;
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$error = '';

try {
    $stmt = db()->prepare('SELECT * FROM legal_pages WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $page = $stmt->fetch();
} catch (Throwable $exception) {
    $page = false;
    $error = 'The Legal Pages installer has not been run yet.';
}

if (!$page && $error === '') {
    header('Location: legal_pages.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page) {
    if (!csrf_check((string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Refresh the page and try again.';
    } else {
        $title = trim((string)($_POST['title'] ?? ''));
        $content = znp_clean_legal_html((string)($_POST['content'] ?? ''));

        if ($title === '' || mb_strlen($title) > 150) {
            $error = 'Enter a page title of 150 characters or fewer.';
        } elseif ($content === '') {
            $error = 'Page content is required.';
        } else {
            try {
                db()->prepare('UPDATE legal_pages SET title = ?, content = ?, updated_at = NOW() WHERE id = ?')->execute([$title, $content, $id]);
                header('Location: legal_pages.php?saved=1');
                exit;
            } catch (Throwable $exception) {
                error_log('Legal page save failed: ' . $exception->getMessage());
                $error = 'The legal page could not be saved. Please try again.';
            }
        }

        $page['title'] = $title;
        $page['content'] = $content;
    }
}

require __DIR__ . '/_header.php';
?>
<div class="admin-page-head">
  <div>
    <h1>Edit <?= e((string)($page['title'] ?? 'Legal Page')) ?></h1>
    <p>Changes appear on the public website immediately after saving.</p>
  </div>
  <a class="secondary" href="legal_pages.php">Back to Legal Pages</a>
</div>

<?php if ($error): ?><div class="status error"><?= e($error) ?></div><?php endif; ?>

<?php if ($page): ?>
<form method="post" class="admin-form admin-form-wide legal-editor-form" id="legal-editor-form">
  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="id" value="<?= (int)$page['id'] ?>">
  <input type="hidden" name="content" id="legal-content-field" value="">

  <label for="title">Page Title</label>
  <input id="title" name="title" maxlength="150" required value="<?= e((string)$page['title']) ?>">

  <label>Page Content</label>
  <div class="legal-editor-toolbar" role="toolbar" aria-label="Text formatting">
    <button type="button" data-command="formatBlock" data-value="h2">Heading</button>
    <button type="button" data-command="bold"><strong>Bold</strong></button>
    <button type="button" data-command="italic"><em>Italic</em></button>
    <button type="button" data-command="insertUnorderedList">Bullets</button>
    <button type="button" data-command="insertOrderedList">Numbers</button>
    <button type="button" id="legal-add-link">Link</button>
    <button type="button" data-command="removeFormat">Clear Format</button>
  </div>
  <div id="legal-rich-editor" class="legal-rich-editor" contenteditable="true" role="textbox" aria-multiline="true"><?= (string)$page['content'] ?></div>
  <p class="admin-help-text">Use headings, paragraphs, lists, bold text, italics, and links. Scripts and unsafe markup are removed when saved.</p>

  <button class="primary" type="submit">Save Legal Page</button>
</form>
<script>
(function(){
  const form=document.getElementById('legal-editor-form');
  const editor=document.getElementById('legal-rich-editor');
  const field=document.getElementById('legal-content-field');
  document.querySelectorAll('.legal-editor-toolbar [data-command]').forEach(function(button){
    button.addEventListener('click',function(){
      editor.focus();
      document.execCommand(button.dataset.command,false,button.dataset.value||null);
    });
  });
  document.getElementById('legal-add-link').addEventListener('click',function(){
    const url=window.prompt('Enter the full link address:','https://');
    if(url){editor.focus();document.execCommand('createLink',false,url);}
  });
  form.addEventListener('submit',function(){field.value=editor.innerHTML;});
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>
