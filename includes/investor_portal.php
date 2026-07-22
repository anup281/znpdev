<?php
declare(strict_types=1);

function investor_portal_all_projects(): array
{
    return db()->query("SELECT id,project_name,city,state,'opportunity' entity_type FROM investment_opportunities WHERE LOWER(TRIM(COALESCE(status,''))) IN ('draft','raising_capital','funded','closed') AND COALESCE(is_visible,0)=1 ORDER BY display_order,project_name")->fetchAll();
}

function investor_portal_accessible_projects(array $workspaceContext): array
{
    $staff = $workspaceContext['staff_user'] ?? null;
    if (is_array($staff) && admin_portal_role($staff)) {
        return investor_portal_all_projects();
    }

    $investor = $workspaceContext['investor_user'] ?? null;
    if (!is_array($investor)) {
        return [];
    }

    $contactType = (string)($investor['contact_type'] ?? '');
    $contactId = (int)($investor['contact_id'] ?? 0);
    if (!in_array($contactType, ['lead', 'inquiry'], true) || $contactId < 1) {
        return [];
    }

    $sql = "SELECT DISTINCT COALESCE(d.investment_opportunity_id,dt.investment_opportunity_id) opportunity_id
            FROM document_deliveries d
            LEFT JOIN document_templates dt ON dt.id=d.template_id
            WHERE d.status='accepted'
              AND LOWER(COALESCE(NULLIF(d.document_type,''),dt.document_type,''))='nda'
              AND ".($contactType === 'lead' ? 'd.lead_id=?' : 'd.inquiry_id=?');
    $statement = db()->prepare($sql);
    $statement->execute([$contactId]);
    $allowed = [];
    foreach ($statement->fetchAll() as $row) {
        if ((int)($row['opportunity_id'] ?? 0) > 0) $allowed['opportunity:'.(int)$row['opportunity_id']] = true;
    }

    return array_values(array_filter(
        investor_portal_all_projects(),
        static fn(array $project): bool => isset($allowed[$project['entity_type'].':'.$project['id']])
    ));
}

function investor_portal_project(array $workspaceContext, string $entityType, int $entityId): ?array
{
    if ($entityType !== 'opportunity' || $entityId < 1) return null;
    foreach (investor_portal_accessible_projects($workspaceContext) as $project) {
        if ($project['entity_type'] === $entityType && (int)$project['id'] === $entityId) return $project;
    }
    return null;
}
