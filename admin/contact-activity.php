<?php
declare(strict_types=1);
ob_start();
require_once __DIR__ . '/../includes/auth.php';
require_admin();

function activity_json(array $payload, int $status = 200): void
{
    app_json_response($payload,$status,true);
}

try {
    $recordType = trim((string)($_GET['record_type'] ?? ''));
    $recordId = (int)($_GET['record_id'] ?? 0);
    if (!in_array($recordType, ['lead', 'inquiry'], true) || $recordId < 1) {
        activity_json(['ok' => false, 'error' => 'Invalid contact activity request.'], 422);
    }

    $activities = contact_activity_rows($recordType, $recordId);
    ob_start();
    ?>
    <section class="admin-activity-timeline">
        <h3>Contact History</h3>
        <?php if (!$activities): ?>
            <p class="admin-help-text">No activity recorded yet.</p>
        <?php else: ?>
            <ol>
                <?php foreach ($activities as $activity): ?>
                    <li>
                        <span class="admin-activity-dot"></span>
                        <div>
                            <strong><?=e((string)$activity['title'])?></strong>
                            <time><?=e(date('M j, Y g:i A', strtotime((string)$activity['occurred_at'])))?> CT<?php if (!empty($activity['admin_name'])): ?> · <?=e((string)$activity['admin_name'])?><?php endif; ?></time>
                            <?php if (!empty($activity['details'])): ?><p><?=nl2br(e((string)$activity['details']))?></p><?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>
    <?php
    $html = (string)ob_get_clean();
    activity_json(['ok' => true, 'html' => $html]);
} catch (Throwable $e) {
    activity_json(['ok' => false, 'error' => 'Could not load the latest activity.'], 500);
}
