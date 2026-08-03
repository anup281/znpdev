<?php
ob_start();
require_once __DIR__ . '/../includes/auth.php';
require_admin();

function autosave_json(array $payload, int $status = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function autosave_extended_ready(): bool
{
    try { return (bool)db()->query("SHOW TABLES LIKE 'contact_extended_fields'")->fetchColumn(); }
    catch (Throwable $e) { return false; }
}

function autosave_projects_ready(): bool
{
    try { return (bool)db()->query("SHOW TABLES LIKE 'contact_project_interests'")->fetchColumn(); }
    catch (Throwable $e) { return false; }
}

function autosave_extended_row(string $recordType, int $recordId): array
{
    if (!autosave_extended_ready()) return [];
    $stmt = db()->prepare('SELECT company_name,inquiry_type,prospective_investor_type,investment_amount,message FROM contact_extended_fields WHERE record_type=? AND record_id=?');
    $stmt->execute([$recordType, $recordId]);
    return $stmt->fetch() ?: [];
}

function autosave_extended_fields(string $recordType, int $recordId, array $fields): void
{
    if (!autosave_extended_ready()) throw new RuntimeException('Contact fields are unavailable because the required database structure is missing. Contact the system administrator.');
    $stmt = db()->prepare('INSERT INTO contact_extended_fields(record_type,record_id,company_name,inquiry_type,prospective_investor_type,investment_amount,message) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE company_name=VALUES(company_name),inquiry_type=VALUES(inquiry_type),prospective_investor_type=VALUES(prospective_investor_type),investment_amount=VALUES(investment_amount),message=VALUES(message)');
    $stmt->execute([
        $recordType, $recordId,
        $fields['company_name'] !== '' ? $fields['company_name'] : null,
        $fields['inquiry_type'] !== '' ? $fields['inquiry_type'] : null,
        $fields['prospective_investor_type'] !== '' ? $fields['prospective_investor_type'] : null,
        $fields['investment_amount'] !== '' ? $fields['investment_amount'] : null,
        $fields['message'] !== '' ? $fields['message'] : null,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') autosave_json(['ok'=>false,'error'=>'POST requests only.'], 405);
if (!csrf_check($_POST['csrf_token'] ?? '')) autosave_json(['ok'=>false,'error'=>'Your session expired. Refresh the page and try again.'], 419);

$recordType = ($_POST['record_type'] ?? '') === 'inquiry' ? 'inquiry' : 'lead';
$recordId = (int)($_POST['record_id'] ?? 0);
$action = (string)($_POST['action'] ?? '');
if ($recordId < 1) autosave_json(['ok'=>false,'error'=>'The selected contact could not be found.'], 422);

$leadStatusOptions = ['new','contacted','voicemail','call_back','wants_more_info','ready_for_nda','not_interested','never_call_back'];
$typeOptions = ['general_inquiry','development_opportunity','investment_opportunity','joint_venture','land_acquisition','media_press','careers'];

try {
    if ($action === 'auto_save_contact') {
        $name = trim((string)($_POST['full_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $company = trim((string)($_POST['company_name'] ?? ''));
        $type = trim((string)($_POST['inquiry_type'] ?? ''));
        $status = (string)($_POST['status'] ?? 'new');
        $investorType = trim((string)($_POST['prospective_investor_type'] ?? ''));
        $investmentAmount = preg_replace('/[^0-9]/', '', (string)($_POST['investment_amount'] ?? ''));

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid name and email address.');
        if (!in_array($status, $leadStatusOptions, true)) throw new RuntimeException('Select a valid status.');
        if ($type !== '' && !in_array($type, $typeOptions, true)) throw new RuntimeException('Select a valid inquiry type.');

        if ($recordType === 'lead') {
            $beforeStmt = db()->prepare('SELECT * FROM leads WHERE id=?');
            $beforeStmt->execute([$recordId]);
            $before = $beforeStmt->fetch();
            if (!$before) throw new RuntimeException('The selected contact could not be found.');
            $beforeExtended = autosave_extended_row('lead', $recordId);
            $source = trim((string)($_POST['source_name'] ?? ''));
            db()->prepare('UPDATE leads SET full_name=?,email=?,phone=?,source_name=?,status=? WHERE id=?')->execute([$name,$email,$phone,$source,$status,$recordId]);
            autosave_extended_fields('lead',$recordId,[
                'company_name'=>$company,'inquiry_type'=>$type,'prospective_investor_type'=>$investorType,
                'investment_amount'=>$investmentAmount,'message'=>(string)($beforeExtended['message'] ?? ''),
            ]);
        } else {
            if ($type === '') throw new RuntimeException('Select a valid inquiry type.');
            $beforeStmt = db()->prepare('SELECT id FROM contact_inquiries WHERE id=?');
            $beforeStmt->execute([$recordId]);
            if (!$beforeStmt->fetchColumn()) throw new RuntimeException('The selected contact could not be found.');
            db()->prepare('UPDATE contact_inquiries SET full_name=?,email=?,phone=?,company_name=?,inquiry_type=?,prospective_investor_type=?,investment_amount=?,status=? WHERE id=?')
                ->execute([$name,$email,$phone,$company,$type,$investorType!==''?$investorType:null,$investmentAmount!==''?$investmentAmount:null,$status,$recordId]);
        }
        contact_activity_log($recordType,$recordId,'contact_updated','Contact information updated','Saved automatically.',admin_user()['id']??null);
        autosave_json(['ok'=>true,'message'=>'Saved','status'=>$status,'name'=>$name,'email'=>$email,'phone'=>$phone]);
    }

    if ($action === 'sync_project_interests') {
        if (!autosave_projects_ready()) throw new RuntimeException('The project-interest database table is not installed.');
        $selected = array_values(array_unique(array_filter(array_map('intval',(array)($_POST['project_ids'] ?? [])), static fn($v)=>$v>0)));
        $stmt = db()->prepare('SELECT investment_opportunity_id FROM contact_project_interests WHERE record_type=? AND record_id=?');
        $stmt->execute([$recordType,$recordId]);
        $existing = array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN));
        $toAdd = array_diff($selected,$existing);
        $toRemove = array_diff($existing,$selected);
        if(partner_role())$toRemove=[];
        db()->beginTransaction();
        foreach ($toAdd as $projectId) {
            db()->prepare('INSERT IGNORE INTO contact_project_interests(record_type,record_id,investment_opportunity_id,status,interest_level,created_by_admin_id) VALUES(?,?,?,?,?,?)')
                ->execute([$recordType,$recordId,$projectId,'new','warm',admin_user()['id']??null]);
        }
        if ($toRemove) {
            $marks = implode(',',array_fill(0,count($toRemove),'?'));
            db()->prepare("DELETE FROM contact_project_interests WHERE record_type=? AND record_id=? AND investment_opportunity_id IN ($marks)")
                ->execute(array_merge([$recordType,$recordId],array_values($toRemove)));
        }
        db()->commit();
        contact_activity_log($recordType,$recordId,'project_interests_updated','Projects of interest updated',count($selected).' project(s) selected',admin_user()['id']??null);
        autosave_json(['ok'=>true,'message'=>'Saved','count'=>count($selected)]);
    }

    autosave_json(['ok'=>false,'error'=>'Unsupported save request.'], 422);
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    autosave_json(['ok'=>false,'error'=>$e->getMessage()], 422);
}
