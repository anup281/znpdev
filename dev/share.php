<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/storage.php';

$token = (string)($_GET['token'] ?? '');
$download = (int)($_GET['download'] ?? 0);

$statement = db()->prepare(
    'SELECT ds.*, cp.project_name
     FROM construction_document_shares ds
     JOIN construction_projects cp ON cp.id = ds.construction_project_id
     WHERE ds.token = ? AND ds.revoked_at IS NULL AND ds.expires_at > NOW()'
);
$statement->execute([$token]);
$share = $statement->fetch();

if (!$share) {
    http_response_code(404);
    exit('This document link is invalid or has expired.');
}

db()->prepare(
    'UPDATE construction_document_shares
     SET first_opened_at = COALESCE(first_opened_at, NOW()), last_opened_at = NOW()
     WHERE id = ?'
)->execute([$share['id']]);

$query = db()->prepare(
    'SELECT d.*
     FROM construction_document_share_items si
     JOIN construction_documents d ON d.id = si.document_id
     WHERE si.share_id = ?'
);
$query->execute([$share['id']]);
$documents = $query->fetchAll();

if ($download) {
    foreach ($documents as $document) {
        if ((int)$document['id'] !== $download) {
            continue;
        }

        db()->prepare(
            'UPDATE construction_document_shares
             SET download_count = download_count + 1, last_opened_at = NOW()
             WHERE id = ?'
        )->execute([$share['id']]);

        znp_storage_send_file(
            (string)$document['file_path'],
            (string)$document['original_name'],
            (string)($document['mime_type'] ?: 'application/octet-stream'),
            'attachment'
        );
    }

    http_response_code(404);
    exit('File not found.');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Shared Documents</title>
    <link rel="stylesheet" href="assets/dev.css?v=20260729-537">
    <link rel="stylesheet" href="../assets/design-system.css?v=20260729-1">
</head>
<body class="shared-documents-body">
<main class="shared-documents-wrap">
    <section class="shared-documents-card">
        <h1><?=htmlspecialchars($share['project_name'])?></h1>
        <p><?=nl2br(htmlspecialchars($share['message_text'] ?: 'Project documents have been shared with you.'))?></p>
        <p class="shared-documents-muted">This secure link expires <?=date('M j, Y g:i A', strtotime($share['expires_at']))?>.</p>
    </section>
    <?php foreach ($documents as $document): ?>
        <article class="shared-documents-card">
            <h2><?=htmlspecialchars($document['document_name'])?></h2>
            <p class="shared-documents-muted"><?=htmlspecialchars($document['original_name'])?> · <?=number_format(((int)$document['file_size']) / 1048576, 2)?> MB</p>
            <a class="shared-documents-button" href="?token=<?=urlencode($token)?>&amp;download=<?=(int)$document['id']?>">Download</a>
        </article>
    <?php endforeach; ?>
</main>
</body>
</html>
