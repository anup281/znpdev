<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('Method not allowed.');
    }
    if (!csrf_check((string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        throw new RuntimeException('Session expired. Refresh the page and try again.');
    }

    $projectId = (int)($_POST['project_id'] ?? 0);
    $assignmentId = (int)($_POST['assignment_id'] ?? 0);
    if ($projectId < 1 || $assignmentId < 1) {
        http_response_code(422);
        throw new RuntimeException('Project Team member was not found.');
    }
    dev_require_project($projectId);

    $check = db()->prepare('SELECT id FROM construction_project_companies WHERE id=? AND construction_project_id=? LIMIT 1');
    $check->execute([$assignmentId, $projectId]);
    if (!$check->fetchColumn()) {
        http_response_code(404);
        throw new RuntimeException('Project Team member was not found.');
    }

    $notes = (string)($_POST['notes'] ?? '');
    db()->prepare('UPDATE construction_project_companies SET notes=?,updated_at=NOW() WHERE id=? AND construction_project_id=?')
        ->execute([$notes, $assignmentId, $projectId]);

    app_json_response(['ok'=>true,'saved_at'=>date('g:i A')]);
} catch (Throwable $exception) {
    $status=http_response_code();
    app_json_response(['ok'=>false,'message'=>$exception->getMessage()],$status>=400?$status:500);
}
