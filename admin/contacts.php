<?php
ob_start();
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$leadStatusOptions = [
    'new' => 'New',
    'contacted' => 'Contacted',
    'voicemail' => 'Contacted – Voice Mail',
    'call_back' => 'Call Back',
    'wants_more_info' => 'Wants More Info',
    'ready_for_nda' => 'Ready for NDA',
    'not_interested' => 'Not Interested',
    'never_call_back' => 'Never Call Back',
];
$inquiryStatusOptions = $leadStatusOptions;
$projectStatusOptions = $leadStatusOptions;
$interestLevelOptions = [
    'hot' => 'Hot',
    'warm' => 'Warm',
    'cold' => 'Cold',
];
$typeLabels = [
    'general_inquiry' => 'General Inquiry',
    'development_opportunity' => 'Development Opportunity',
    'investment_opportunity' => 'Investment Opportunity',
    'joint_venture' => 'Joint Venture',
    'land_acquisition' => 'Land Acquisition',
    'media_press' => 'Media & Press',
    'careers' => 'Careers',
];
$error = '';

function contacts_json_response(array $payload, int $status = 200): void
{
    app_json_response($payload,$status,true);
}

function contacts_redirect(string $query = ''): void
{
    $suffix = $query !== '' ? '?' . ltrim($query, '?') : '';
    header('Location: contacts.php' . $suffix);
    exit;
}

function contact_project_interests_ready(): bool
{
    return db_schema_ready(['contact_project_interests']);
}

function contact_newsletter_table_ready(): bool
{
    return db_schema_ready(['newsletter_subscribers']);
}

function contact_record_for_newsletter(string $recordType, int $recordId): ?array
{
    if ($recordType === 'inquiry') {
        $stmt = db()->prepare('SELECT id,full_name,email FROM contact_inquiries WHERE id=? LIMIT 1');
    } else {
        $stmt = db()->prepare('SELECT id,full_name,email FROM leads WHERE id=? LIMIT 1');
    }
    $stmt->execute([$recordId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function contact_newsletter_subscriber(string $email): ?array
{
    if (!contact_newsletter_table_ready() || !filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
    $stmt = db()->prepare('SELECT id,full_name,email,status,source,consent_at,unsubscribed_at,created_at,updated_at FROM newsletter_subscribers WHERE LOWER(email)=LOWER(?) LIMIT 1');
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function contact_extended_fields_ready(): bool
{
    return db_schema_ready(['contact_extended_fields']);
}

function contact_extended_fields_row(string $recordType, int $recordId): array
{
    if (!contact_extended_fields_ready()) return [];
    $stmt = db()->prepare('SELECT company_name,inquiry_type,prospective_investor_type,investment_amount,message FROM contact_extended_fields WHERE record_type=? AND record_id=?');
    $stmt->execute([$recordType,$recordId]);
    return $stmt->fetch() ?: [];
}

function save_contact_extended_fields(string $recordType, int $recordId, array $fields): void
{
    if (!contact_extended_fields_ready()) {
        throw new RuntimeException('Contact fields are unavailable because the required database structure is missing. Contact the system administrator.');
    }
    $stmt = db()->prepare('INSERT INTO contact_extended_fields(record_type,record_id,company_name,inquiry_type,prospective_investor_type,investment_amount,message) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE company_name=VALUES(company_name),inquiry_type=VALUES(inquiry_type),prospective_investor_type=VALUES(prospective_investor_type),investment_amount=VALUES(investment_amount),message=VALUES(message)');
    $stmt->execute([
        $recordType,
        $recordId,
        $fields['company_name'] !== '' ? $fields['company_name'] : null,
        $fields['inquiry_type'] !== '' ? $fields['inquiry_type'] : null,
        $fields['prospective_investor_type'] !== '' ? $fields['prospective_investor_type'] : null,
        $fields['investment_amount'] !== '' ? $fields['investment_amount'] : null,
        $fields['message'] !== '' ? $fields['message'] : null,
    ]);
}

function contact_project_interest_rows(string $recordType, int $recordId): array
{
    if (!contact_project_interests_ready()) return [];
    $stmt = db()->prepare("SELECT cpi.*,o.project_name,a.full_name AS owner_name
        FROM contact_project_interests cpi
        JOIN investment_opportunities o ON o.id=cpi.investment_opportunity_id
        LEFT JOIN admin_users a ON a.id=cpi.assigned_admin_id
        WHERE cpi.record_type=? AND cpi.record_id=?
        ORDER BY FIELD(cpi.interest_level,'hot','warm','cold'),o.display_order,o.project_name");
    $stmt->execute([$recordType,$recordId]);
    return $stmt->fetchAll();
}

function send_contact_template(array $contact, string $recordType, int $contactId, array $template, string $activityEvent = ''): array
{
    $entity = agreement_entity((string)$template['entity_type'], (int)$template['entity_id']);
    if (!$entity) return ['ok'=>false,'error'=>'This document is not linked to an eligible project or investment opportunity.'];
    $requires = (int)($template['requires_acceptance'] ?? 0) === 1;
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $acceptLink = $requires ? app_public_url('helpers/accept-agreement.php?token='.rawurlencode($token)) : '';
    $subject = str_replace(['{{name}}','{{project}}'], [$contact['full_name'],$entity['name']], (string)$template['email_subject']);
    $text = str_replace(['{{name}}','{{project}}','{{accept_link}}'], [$contact['full_name'],$entity['name'],$acceptLink], (string)$template['email_body']);
    $html = nl2br(e($text));
    if ($requires) $html .= '<p style="margin:24px 0"><a href="'.e($acceptLink).'" style="display:inline-block;background:#173d63;color:#fff;padding:13px 22px;text-decoration:none;border-radius:5px;font-weight:700">Accept Agreement</a></p>';
    $attachments=[];
    $path=(string)($template['template_path']??'');
    if($path&&is_file(__DIR__.'/../'.$path)) $attachments[]=['name'=>basename($path),'type'=>(string)($template['attachment_mime']?:'application/octet-stream'),'data'=>file_get_contents(__DIR__.'/../'.$path)];
    elseif($requires) $attachments[]=['name'=>preg_replace('/[^A-Za-z0-9._-]/','-',$entity['name']).'-NDA.pdf','type'=>'application/pdf','data'=>generic_nda_pdf($contact['full_name'].' regarding '.$entity['name'])];
    $mailResult=app_send_mail_detailed($contact['email'],$subject,$html,$attachments);
    if(!$mailResult['ok']) return $mailResult;
    $leadId=$recordType==='lead'?$contactId:null;
    $inquiryId=$recordType==='inquiry'?$contactId:null;
    $projectId=$template['entity_type']==='project'?(int)$template['entity_id']:null;
    $oppId=$template['entity_type']==='opportunity'?(int)$template['entity_id']:null;
    $ins=db()->prepare('INSERT INTO document_deliveries(lead_id,inquiry_id,project_id,investment_opportunity_id,template_id,document_type,document_name,template_version,recipient_name,recipient_email,token_hash,status,sent_by_admin_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $ins->execute([$leadId,$inquiryId,$projectId,$oppId,$template['id'],$template['document_type'],$template['document_name'],$template['template_version'],$contact['full_name'],$contact['email'],$tokenHash,'sent',admin_user()['id']??null]);
    $deliveryId=(int)db()->lastInsertId();
    $isResend = $activityEvent === 'document_resent';
    $title = $isResend ? ($requires?'Agreement resent':'Document resent') : ($requires?'Agreement sent':'Document sent');
    $event = $activityEvent ?: ($requires?'agreement_sent':'document_sent');
    contact_activity_log($recordType,$contactId,$event,$title,$entity['name'].' · '.$template['document_name'].' · Version '.$template['template_version'].' · '.$contact['email'],admin_user()['id']??null,$deliveryId);
    return ['ok'=>true,'delivery_id'=>$deliveryId];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestIsAjax = (($_POST['ajax'] ?? '') === '1') || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    if (!csrf_check($_POST['csrf_token'] ?? '')) {
        if ($requestIsAjax) {
            contacts_json_response(['ok'=>false,'error'=>'Your session expired. Refresh the page and try again.'], 419);
        }
        $error = 'Your session expired. Refresh the page and try again.';
    } else {
        $recordType = ($_POST['record_type'] ?? '') === 'inquiry' ? 'inquiry' : 'lead';
        $action = (string)($_POST['action'] ?? 'status');
        $id = (int)($_POST['record_id'] ?? 0);
        $expand = 'contact-' . $recordType . '-' . $id;

        if ($action === 'add_contact') {
            $name = trim((string)($_POST['full_name'] ?? ''));
            $company = trim((string)($_POST['company_name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $type = (string)($_POST['inquiry_type'] ?? 'general_inquiry');
            $projectId = (int)($_POST['investment_opportunity_id'] ?? 0);
            $investorType = trim((string)($_POST['prospective_investor_type'] ?? ''));
            $investmentAmount = trim((string)($_POST['investment_amount'] ?? ''));
            $messageText = trim((string)($_POST['message'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));
            $status = (string)($_POST['status'] ?? 'new');

            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !isset($typeLabels[$type]) || !isset($inquiryStatusOptions[$status])) {
                $error = 'Enter a valid name, email address, inquiry type, and status.';
            } else {
                $reference = 'ZNP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
                $stmt = db()->prepare('INSERT INTO contact_inquiries(reference_number,inquiry_type,investment_opportunity_id,full_name,company_name,email,phone,prospective_investor_type,investment_amount,message,source_page,ip_address,user_agent,notes,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $stmt->execute([
                    $reference,
                    $type,
                    $projectId > 0 ? $projectId : null,
                    $name,
                    $company,
                    $email,
                    $phone,
                    $investorType !== '' ? $investorType : null,
                    $investmentAmount !== '' ? $investmentAmount : null,
                    $messageText,
                    'admin_manual',
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null,
                    $notes,
                    $status,
                ]);
                $newId = (int)db()->lastInsertId();
                contact_activity_log('inquiry', $newId, 'contact_created', 'Contact manually created', 'Created from the Admin Portal.', admin_user()['id'] ?? null);
                if ($projectId > 0 && contact_project_interests_ready()) {
                    $targetValue=$investmentAmount!==''?(float)$investmentAmount:null;
                    db()->prepare("INSERT IGNORE INTO contact_project_interests(record_type,record_id,investment_opportunity_id,status,interest_level,target_amount,created_by_admin_id) VALUES('inquiry',?,?,?,?,?,?)")
                        ->execute([$newId,$projectId,$status,'warm',$targetValue,admin_user()['id']??null]);
                }
                contacts_redirect('contact_added=1&expand=' . rawurlencode('contact-inquiry-' . $newId));
            }
        } elseif ($id < 1) {
            $error = 'The selected contact could not be found.';
        } elseif ($action === 'add_to_newsletter' || $action === 'enable_newsletter') {
            try {
                if (!contact_newsletter_table_ready()) throw new RuntimeException('The Newsletter Subscribers table is not installed.');
                $contact = contact_record_for_newsletter($recordType, $id);
                if (!$contact) throw new RuntimeException('The selected contact could not be found.');
                $email = trim((string)($contact['email'] ?? ''));
                $name = trim((string)($contact['full_name'] ?? ''));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('This contact does not have a valid email address.');

                $existing = contact_newsletter_subscriber($email);
                if ($existing) {
                    if ((string)$existing['status'] !== 'active') {
                        db()->prepare("UPDATE newsletter_subscribers SET full_name=?,status='active',source='crm_contact',consent_at=COALESCE(consent_at,NOW()),unsubscribed_at=NULL WHERE id=?")
                            ->execute([$name !== '' ? $name : null, (int)$existing['id']]);
                        contact_activity_log($recordType,$id,'newsletter_enabled','Newsletter subscription enabled','Subscriber enabled from the CRM contact profile.',admin_user()['id']??null);
                        contacts_redirect('newsletter_enabled=1&expand='.rawurlencode($expand));
                    }
                    contacts_redirect('newsletter_exists=1&expand='.rawurlencode($expand));
                }

                $token = bin2hex(random_bytes(32));
                $stmt = db()->prepare("INSERT INTO newsletter_subscribers(full_name,email,status,source,consent_ip,consent_at,unsubscribe_token,notes) VALUES(?,?,'active','crm_contact',?,NOW(),?,?)");
                $stmt->execute([
                    $name !== '' ? $name : null,
                    $email,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $token,
                    'Added from CRM contact profile by '.((string)(admin_user()['full_name'] ?? 'Admin')).'.',
                ]);
                contact_activity_log($recordType,$id,'newsletter_added','Added to Newsletter Subscribers','Newsletter subscriber created from the CRM contact profile. CRM contact status was not changed.',admin_user()['id']??null);
                contacts_redirect('newsletter_added=1&expand='.rawurlencode($expand));
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        } elseif ($action === 'auto_save_contact') {
            try {
                $name = trim((string)($_POST['full_name'] ?? ''));
                $email = trim((string)($_POST['email'] ?? ''));
                $phone = trim((string)($_POST['phone'] ?? ''));
                $company = trim((string)($_POST['company_name'] ?? ''));
                $type = trim((string)($_POST['inquiry_type'] ?? ''));
                $status = (string)($_POST['status'] ?? 'new');
                $investorType = trim((string)($_POST['prospective_investor_type'] ?? ''));
                $investmentAmount = preg_replace('/[^0-9]/', '', (string)($_POST['investment_amount'] ?? ''));
                if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Enter a valid name and email address.');
                }
                if ($recordType === 'lead') {
                    if (!isset($leadStatusOptions[$status]) || ($type !== '' && !isset($typeLabels[$type]))) {
                        throw new RuntimeException('Select a valid inquiry type and status.');
                    }
                    $source = trim((string)($_POST['source_name'] ?? ''));
                    $beforeStmt=db()->prepare('SELECT * FROM leads WHERE id=?');
                    $beforeStmt->execute([$id]);
                    $before=$beforeStmt->fetch();
                    if (!$before) throw new RuntimeException('The selected contact could not be found.');
                    $beforeExtended=contact_extended_fields_row('lead',$id);
                    db()->prepare('UPDATE leads SET full_name=?,email=?,phone=?,source_name=?,status=? WHERE id=?')->execute([$name,$email,$phone,$source,$status,$id]);
                    save_contact_extended_fields('lead',$id,[
                        'company_name'=>$company,
                        'inquiry_type'=>$type,
                        'prospective_investor_type'=>$investorType,
                        'investment_amount'=>$investmentAmount,
                        'message'=>(string)($beforeExtended['message'] ?? ''),
                    ]);
                    $changes=contact_changed_fields(array_merge($before,$beforeExtended),[
                        'full_name'=>$name,'email'=>$email,'phone'=>$phone,'source_name'=>$source,'status'=>$status,
                        'company_name'=>$company,'inquiry_type'=>$type,'prospective_investor_type'=>$investorType,'investment_amount'=>$investmentAmount,
                    ],['full_name'=>'Name','email'=>'Email','phone'=>'Phone','source_name'=>'Source','status'=>'Status','company_name'=>'Company','inquiry_type'=>'Inquiry Type','prospective_investor_type'=>'Prospective Investor Type','investment_amount'=>'Investment Amount']);
                } else {
                    if (!isset($inquiryStatusOptions[$status]) || !isset($typeLabels[$type])) {
                        throw new RuntimeException('Select a valid inquiry type and status.');
                    }
                    $beforeStmt=db()->prepare('SELECT * FROM contact_inquiries WHERE id=?');
                    $beforeStmt->execute([$id]);
                    $before=$beforeStmt->fetch();
                    if (!$before) throw new RuntimeException('The selected contact could not be found.');
                    db()->prepare('UPDATE contact_inquiries SET full_name=?,email=?,phone=?,company_name=?,inquiry_type=?,prospective_investor_type=?,investment_amount=?,status=? WHERE id=?')->execute([$name,$email,$phone,$company,$type,$investorType?:null,$investmentAmount?:null,$status,$id]);
                    $changes=contact_changed_fields($before,[
                        'full_name'=>$name,'email'=>$email,'phone'=>$phone,'company_name'=>$company,'inquiry_type'=>$type,
                        'prospective_investor_type'=>$investorType,'investment_amount'=>$investmentAmount,'status'=>$status,
                    ],['full_name'=>'Name','email'=>'Email','phone'=>'Phone','company_name'=>'Company','inquiry_type'=>'Inquiry Type','prospective_investor_type'=>'Prospective Investor Type','investment_amount'=>'Investment Amount','status'=>'Status']);
                }
                try {
                    if ($changes) contact_activity_log($recordType,$id,'contact_updated','Contact information updated',implode("\n",$changes),admin_user()['id']??null);
                } catch (Throwable $activityError) {
                    // The contact is already saved; activity logging must not fail auto-save.
                }
                contacts_json_response(['ok'=>true,'message'=>'Saved','status'=>$status,'name'=>$name,'email'=>$email,'phone'=>$phone]);
            } catch (Throwable $saveError) {
                contacts_json_response(['ok'=>false,'error'=>$saveError->getMessage()], 422);
            }
        } elseif ($action === 'revoke_agreement') {
            $deliveryId=(int)($_POST['delivery_id']??0);
            $deliveryStmt=db()->prepare("SELECT d.*,COALESCE(p.project_name,o.project_name) AS project_name FROM document_deliveries d LEFT JOIN projects p ON p.id=d.project_id LEFT JOIN investment_opportunities o ON o.id=d.investment_opportunity_id WHERE d.id=? AND ((?='lead' AND d.lead_id=?) OR (?='inquiry' AND d.inquiry_id=?)) LIMIT 1");
            $deliveryStmt->execute([$deliveryId,$recordType,$id,$recordType,$id]);
            $delivery=$deliveryStmt->fetch();
            if(!$delivery){
                $error='The selected agreement could not be found.';
            } elseif(strtolower((string)$delivery['status'])!=='accepted'){
                $error='Only accepted agreements can be revoked.';
            } else {
                db()->prepare("UPDATE document_deliveries SET status='revoked',accepted_at=NULL,accepted_name=NULL,accepted_email=NULL,accepted_ip=NULL,accepted_user_agent=NULL WHERE id=?")->execute([$deliveryId]);
                contact_activity_log($recordType,$id,'agreement_revoked','Agreement acceptance revoked',((string)($delivery['project_name']??'Project')).' · '.((string)$delivery['document_name']),admin_user()['id']??null,$deliveryId);
                contacts_redirect('agreement_revoked=1&expand='.rawurlencode($expand));
            }
        } elseif ($action === 'sync_project_interests') {
            $isAjax = $requestIsAjax;
            try {
                if (!contact_project_interests_ready()) {
                    throw new RuntimeException('The project-interest database table is not installed.');
                }
                $selectedProjects=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['project_ids']??[])),static fn(int $v):bool=>$v>0)));
                $existingStmt=db()->prepare('SELECT investment_opportunity_id FROM contact_project_interests WHERE record_type=? AND record_id=?');
                $existingStmt->execute([$recordType,$id]);
                $existing=array_map('intval',$existingStmt->fetchAll(PDO::FETCH_COLUMN));
                $toAdd=array_diff($selectedProjects,$existing);
                $toRemove=array_diff($existing,$selectedProjects);
                if(partner_role())$toRemove=[];
                db()->beginTransaction();
                foreach($toAdd as $opportunityId){
                    db()->prepare('INSERT IGNORE INTO contact_project_interests(record_type,record_id,investment_opportunity_id,status,interest_level,created_by_admin_id) VALUES(?,?,?,?,?,?)')->execute([$recordType,$id,$opportunityId,'new','warm',admin_user()['id']??null]);
                }
                if($toRemove){
                    $marks=implode(',',array_fill(0,count($toRemove),'?'));
                    $params=array_merge([$recordType,$id],array_values($toRemove));
                    db()->prepare("DELETE FROM contact_project_interests WHERE record_type=? AND record_id=? AND investment_opportunity_id IN ($marks)")->execute($params);
                }
                db()->commit();
                try {
                    contact_activity_log($recordType,$id,'project_interests_updated','Projects of interest updated',count($selectedProjects).' project(s) selected',admin_user()['id']??null);
                } catch (Throwable $activityError) {
                    // Project selections are already saved; activity logging must not make auto-save appear to fail.
                }
                if ($isAjax) {
                    contacts_json_response(['ok'=>true,'count'=>count($selectedProjects)]);
                }
                contacts_redirect('interest_saved=1&expand='.rawurlencode($expand));
            } catch (Throwable $saveError) {
                if (db()->inTransaction()) db()->rollBack();
                if ($isAjax) {
                    contacts_json_response(['ok'=>false,'error'=>$saveError->getMessage()], 422);
                }
                $error = $saveError->getMessage();
            }
        } elseif (in_array($action, ['add_project_interest','update_project_interest','remove_project_interest','send_interest_nda'], true)) {
            if (!contact_project_interests_ready()) {
                $error = 'Project interests are unavailable because the required database structure is missing. Contact the system administrator.';
            } elseif ($action === 'remove_project_interest') {
                $interestId=(int)($_POST['interest_id']??0);
                $stmt=db()->prepare('SELECT cpi.*,o.project_name FROM contact_project_interests cpi JOIN investment_opportunities o ON o.id=cpi.investment_opportunity_id WHERE cpi.id=? AND cpi.record_type=? AND cpi.record_id=?');
                $stmt->execute([$interestId,$recordType,$id]);$interest=$stmt->fetch();
                if(!$interest)$error='The selected project interest could not be found.';
                else{
                    db()->prepare('DELETE FROM contact_project_interests WHERE id=?')->execute([$interestId]);
                    contact_activity_log($recordType,$id,'project_interest_removed','Project interest removed',(string)$interest['project_name'],admin_user()['id']??null);
                    contacts_redirect('interest_removed=1&expand='.rawurlencode($expand));
                }
            } elseif ($action === 'send_interest_nda') {
                $opportunityId=(int)($_POST['investment_opportunity_id']??0);
                $table=$recordType==='lead'?'leads':'contact_inquiries';$stmt=db()->prepare("SELECT * FROM {$table} WHERE id=?");$stmt->execute([$id]);$contact=$stmt->fetch();
                $check=db()->prepare('SELECT id FROM contact_project_interests WHERE record_type=? AND record_id=? AND investment_opportunity_id=?');$check->execute([$recordType,$id,$opportunityId]);
                $tpl=db()->prepare("SELECT id FROM document_templates WHERE investment_opportunity_id=? AND is_active=1 AND requires_acceptance=1 ORDER BY updated_at DESC,id DESC LIMIT 1");$tpl->execute([$opportunityId]);$templateId=(int)$tpl->fetchColumn();
                $template=$templateId?document_template_by_id($templateId,true):null;
                if(!$contact||!filter_var($contact['email'],FILTER_VALIDATE_EMAIL))$error='This contact does not have a valid email address.';
                elseif(!$check->fetchColumn())$error='This project is not linked to the contact.';
                elseif(!$template)$error='No active acceptance-required document is configured for this project.';
                else{$result=send_contact_template($contact,$recordType,$id,$template);if($result['ok'])contacts_redirect('document_sent=1&expand='.rawurlencode($expand));$error='The document email could not be sent: '.$result['error'];}
            } else {
                $interestId=(int)($_POST['interest_id']??0);
                $opportunityId=(int)($_POST['investment_opportunity_id']??0);
                $status=(string)($_POST['project_status']??'new');
                $level=(string)($_POST['interest_level']??'warm');
                $targetRaw=preg_replace('/[^0-9.]/','',(string)($_POST['target_amount']??''));
                $target=$targetRaw===''?null:(float)$targetRaw;
                $owner=(int)($_POST['assigned_admin_id']??0);
                $projectNotes=trim((string)($_POST['project_notes']??''));
                if($opportunityId<1||!isset($projectStatusOptions[$status])||!isset($interestLevelOptions[$level]))$error='Select a valid project, status, and interest level.';
                elseif($action==='add_project_interest'){
                    try{
                        $stmt=db()->prepare('INSERT INTO contact_project_interests(record_type,record_id,investment_opportunity_id,status,interest_level,target_amount,assigned_admin_id,notes,created_by_admin_id) VALUES(?,?,?,?,?,?,?,?,?)');
                        $stmt->execute([$recordType,$id,$opportunityId,$status,$level,$target,$owner?:null,$projectNotes,admin_user()['id']??null]);
                        $projectName=(string)(db()->query('SELECT project_name FROM investment_opportunities WHERE id='.(int)$opportunityId)->fetchColumn()?:'Project');
                        contact_activity_log($recordType,$id,'project_interest_added','Project interest added',$projectName.' · '.$interestLevelOptions[$level].' · '.$projectStatusOptions[$status],admin_user()['id']??null);
                        contacts_redirect('interest_saved=1&expand='.rawurlencode($expand));
                    }catch(PDOException $e){$error=(string)$e->getCode()==='23000'?'This project is already attached to the contact.':'The project interest could not be saved.';}
                }else{
                    $stmt=db()->prepare('UPDATE contact_project_interests SET investment_opportunity_id=?,status=?,interest_level=?,target_amount=?,assigned_admin_id=?,notes=? WHERE id=? AND record_type=? AND record_id=?');
                    $stmt->execute([$opportunityId,$status,$level,$target,$owner?:null,$projectNotes,$interestId,$recordType,$id]);
                    contact_activity_log($recordType,$id,'project_interest_updated','Project interest updated',$interestLevelOptions[$level].' · '.$projectStatusOptions[$status],admin_user()['id']??null);
                    contacts_redirect('interest_saved=1&expand='.rawurlencode($expand));
                }
            }
        } elseif ($action === 'send_document') {
            $templateId=(int)($_POST['template_id']??0);$table=$recordType==='lead'?'leads':'contact_inquiries';$stmt=db()->prepare("SELECT * FROM {$table} WHERE id=?");$stmt->execute([$id]);$contact=$stmt->fetch();$template=document_template_by_id($templateId,true);
            if(!$contact||!filter_var($contact['email'],FILTER_VALIDATE_EMAIL))$error='This contact does not have a valid email address.';
            elseif(!$template)$error='Select an active project document.';
            else{$result=send_contact_template($contact,$recordType,$id,$template);if($result['ok'])contacts_redirect('document_sent=1&expand='.rawurlencode($expand));$error='The document email could not be sent: '.$result['error'];}
        } elseif ($action === 'send_email') {
            $table=$recordType==='lead'?'leads':'contact_inquiries';$stmt=db()->prepare("SELECT * FROM {$table} WHERE id=?");$stmt->execute([$id]);$contact=$stmt->fetch();$subject=trim((string)($_POST['email_subject']??''));$body=trim((string)($_POST['email_body']??''));$upload=uploaded_attachment('email_attachment');
            if(!$contact||!filter_var($contact['email'],FILTER_VALIDATE_EMAIL))$error='This contact does not have a valid email address.';elseif($subject===''||$body==='')$error='Email subject and message are required.';elseif(!$upload['ok'])$error=$upload['error'];else{$attachments=$upload['attachment']?[$upload['attachment']]:[];$result=app_send_mail_detailed($contact['email'],$subject,nl2br(e($body)),$attachments);if($result['ok']){$details='To: '.$contact['email']."\nSubject: ".$subject;if($upload['attachment'])$details.="\nAttachment: ".$upload['attachment']['name'];contact_activity_log($recordType,$id,'email_sent','Email sent',$details,admin_user()['id']??null);contacts_redirect('email_sent=1&expand='.rawurlencode($expand));}$error='The email could not be sent: '.$result['error'];}
        } elseif ($recordType === 'lead') {
            if ($action === 'delete') {
                contact_activity_log('lead',$id,'deleted','Contact deleted',null,admin_user()['id']??null);db()->prepare('DELETE FROM leads WHERE id = ?')->execute([$id]);
                contacts_redirect('deleted=lead');
            }
            if ($action === 'edit') {
                $name = trim((string)($_POST['full_name'] ?? ''));
                $email = trim((string)($_POST['email'] ?? ''));
                $phone = trim((string)($_POST['phone'] ?? ''));
                $source = trim((string)($_POST['source_name'] ?? ''));
                $status = (string)($_POST['status'] ?? 'new');
                $company = trim((string)($_POST['company_name'] ?? ''));
                $type = trim((string)($_POST['inquiry_type'] ?? ''));
                $investorType = trim((string)($_POST['prospective_investor_type'] ?? ''));
                $investmentAmount = preg_replace('/[^0-9]/','',(string)($_POST['investment_amount'] ?? ''));
                $messageText = trim((string)($_POST['message'] ?? ''));
                if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !isset($leadStatusOptions[$status]) || ($type !== '' && !isset($typeLabels[$type]))) {
                    $error = 'Enter a valid name, email address, inquiry type, and status.';
                } else {
                    try {
                        $beforeStmt=db()->prepare('SELECT * FROM leads WHERE id=?');$beforeStmt->execute([$id]);$before=$beforeStmt->fetch()?:[];
                        $beforeExtended = contact_extended_fields_row('lead',$id);
                        db()->prepare('UPDATE leads SET full_name=?, email=?, phone=?, source_name=?, status=? WHERE id=?')->execute([$name,$email,$phone,$source,$status,$id]);
                        save_contact_extended_fields('lead',$id,[
                            'company_name'=>$company,
                            'inquiry_type'=>$type,
                            'prospective_investor_type'=>$investorType,
                            'investment_amount'=>$investmentAmount,
                            'message'=>$messageText,
                        ]);
                        $beforeCombined=array_merge($before,$beforeExtended);
                        $afterCombined=['full_name'=>$name,'email'=>$email,'phone'=>$phone,'source_name'=>$source,'status'=>$status,'company_name'=>$company,'inquiry_type'=>$type,'prospective_investor_type'=>$investorType,'investment_amount'=>$investmentAmount,'message'=>$messageText];
                        $changes=contact_changed_fields($beforeCombined,$afterCombined,['full_name'=>'Name','email'=>'Email','phone'=>'Phone','source_name'=>'Source','status'=>'Status','company_name'=>'Company','inquiry_type'=>'Inquiry Type','prospective_investor_type'=>'Prospective Investor Type','investment_amount'=>'Investment Amount','message'=>'Original Message']);
                        if($changes)contact_activity_log('lead',$id,'contact_updated','Contact information updated',implode("
",$changes),admin_user()['id']??null);
                        contacts_redirect('saved=lead&expand=' . rawurlencode($expand));
                    } catch (Throwable $e) {
                        $error = $e->getMessage();
                    }
                }
            } else {
                $status = (string)($_POST['status'] ?? '');
                if (isset($leadStatusOptions[$status])) {
                    $old=(string)(db()->query('SELECT status FROM leads WHERE id='.(int)$id)->fetchColumn()?:'');db()->prepare('UPDATE leads SET status=? WHERE id=?')->execute([$status,$id]);if($old!==$status)contact_activity_log('lead',$id,'status_changed','Status changed',ucwords(str_replace('_',' ',$old)).' → '.ucwords(str_replace('_',' ',$status)),admin_user()['id']??null);
                    contacts_redirect('status_saved=1&expand=' . rawurlencode($expand));
                }
            }
        } else {
            if ($action === 'delete') {
                contact_activity_log('inquiry',$id,'deleted','Contact deleted',null,admin_user()['id']??null);db()->prepare('DELETE FROM contact_inquiries WHERE id=?')->execute([$id]);
                contacts_redirect('deleted=inquiry');
            }
            if ($action === 'edit') {
                $name = trim((string)($_POST['full_name'] ?? ''));
                $email = trim((string)($_POST['email'] ?? ''));
                $phone = trim((string)($_POST['phone'] ?? ''));
                $company = trim((string)($_POST['company_name'] ?? ''));
                $type = (string)($_POST['inquiry_type'] ?? 'general_inquiry');
                $status = (string)($_POST['status'] ?? 'new');
                $investorType=trim((string)($_POST['prospective_investor_type']??''));
                $investmentAmount=preg_replace('/[^0-9]/','',(string)($_POST['investment_amount']??''));
                $messageText = trim((string)($_POST['message'] ?? ''));
                if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !isset($typeLabels[$type]) || !isset($inquiryStatusOptions[$status])) {
                    $error = 'Enter valid inquiry information.';
                } else {
                    $beforeStmt=db()->prepare('SELECT * FROM contact_inquiries WHERE id=?');$beforeStmt->execute([$id]);$before=$beforeStmt->fetch()?:[];db()->prepare('UPDATE contact_inquiries SET full_name=?,email=?,phone=?,company_name=?,inquiry_type=?,prospective_investor_type=?,investment_amount=?,status=?,message=? WHERE id=?')->execute([$name,$email,$phone,$company,$type,$investorType?:null,$investmentAmount?:null,$status,$messageText,$id]);$changes=contact_changed_fields($before,['full_name'=>$name,'email'=>$email,'phone'=>$phone,'company_name'=>$company,'inquiry_type'=>$type,'prospective_investor_type'=>$investorType,'investment_amount'=>$investmentAmount,'status'=>$status,'message'=>$messageText],['full_name'=>'Name','email'=>'Email','phone'=>'Phone','company_name'=>'Company','inquiry_type'=>'Inquiry Type','prospective_investor_type'=>'Prospective Investor Type','investment_amount'=>'Investment Amount','status'=>'Status','message'=>'Message']);if($changes)contact_activity_log('inquiry',$id,'contact_updated','Contact information updated',implode("
",$changes),admin_user()['id']??null);
                    contacts_redirect('saved=inquiry&expand=' . rawurlencode($expand));
                }
            } else {
                $status = (string)($_POST['status'] ?? '');
                if (isset($inquiryStatusOptions[$status])) {
                    $old=(string)(db()->query('SELECT status FROM contact_inquiries WHERE id='.(int)$id)->fetchColumn()?:'');db()->prepare('UPDATE contact_inquiries SET status=? WHERE id=?')->execute([$status,$id]);if($old!==$status)contact_activity_log('inquiry',$id,'status_changed','Status changed',ucwords(str_replace('_',' ',$old)).' → '.ucwords(str_replace('_',' ',$status)),admin_user()['id']??null);
                    contacts_redirect('status_saved=1&expand=' . rawurlencode($expand));
                }
            }
        }
    }
}

require __DIR__ . '/_header.php';
$message = '';
if (isset($_GET['saved'])) {
    $message = ucfirst((string)$_GET['saved']) . ' updated successfully.';
} elseif (isset($_GET['deleted'])) {
    $message = ucfirst((string)$_GET['deleted']) . ' deleted.';
} elseif (isset($_GET['document_sent'])) {
    $message = 'Document emailed successfully.';
} elseif (isset($_GET['document_resent'])) {
    $message = 'Document resent successfully.';
} elseif (isset($_GET['email_sent'])) {
    $message = 'Email sent successfully.';
} elseif (isset($_GET['status_saved'])) {
    $message = 'Contact status updated.';
} elseif (isset($_GET['contact_added'])) {
    $message = 'Contact added successfully.';
} elseif (isset($_GET['newsletter_added'])) {
    $message = 'Contact added to Newsletter Subscribers.';
} elseif (isset($_GET['newsletter_enabled'])) {
    $message = 'Newsletter subscriber enabled.';
} elseif (isset($_GET['newsletter_exists'])) {
    $message = 'This contact is already an active newsletter subscriber.';
} elseif (isset($_GET['interest_saved'])) {
    $message = 'Project interest saved successfully.';
} elseif (isset($_GET['interest_removed'])) {
    $message = 'Project interest removed.';
}

// Present all records as one unified Inquiry list. The legacy record type is retained
// internally so existing activity, document, and database relationships remain intact.
$contacts = [];
foreach (db()->query('SELECT l.* FROM leads l ORDER BY submitted_at DESC')->fetchAll() as $row) {
    $row['_record_type'] = 'lead';
    $contacts[] = $row;
}
$sql = 'SELECT i.*,o.project_name AS opportunity_name FROM contact_inquiries i LEFT JOIN investment_opportunities o ON o.id=i.investment_opportunity_id ORDER BY i.submitted_at DESC';
foreach (db()->query($sql)->fetchAll() as $row) {
    $row['_record_type'] = 'inquiry';
    $contacts[] = $row;
}
usort($contacts, static fn(array $a, array $b): int => strcmp((string)$b['submitted_at'], (string)$a['submitted_at']));

$inquiryCount = (int)db()->query('SELECT COUNT(*) FROM leads')->fetchColumn()
    + (int)db()->query('SELECT COUNT(*) FROM contact_inquiries')->fetchColumn();
$documentTemplates = eligible_document_templates();
$requestedProjectId=(int)($_GET['project_id']??0);$contactOpportunityStatement=db()->prepare("SELECT id,project_name FROM investment_opportunities WHERE is_visible=1 OR id=? ORDER BY display_order,project_name");$contactOpportunityStatement->execute([$requestedProjectId]);$contactOpportunities=$contactOpportunityStatement->fetchAll();
$relationshipOwners = db()->query("SELECT id,full_name FROM admin_users WHERE is_active=1 ORDER BY full_name")->fetchAll();
$interestsInstalled = contact_project_interests_ready();
$extendedFieldsInstalled = contact_extended_fields_ready();
$projectFilter=(int)($_GET['project']??0);
$deliveryStmt = db()->prepare("SELECT d.*, COALESCE(d.investment_opportunity_id, dt.investment_opportunity_id) AS delivery_opportunity_id, COALESCE(o.project_name, dto.project_name, p.project_name) AS project_name FROM document_deliveries d LEFT JOIN document_templates dt ON dt.id=d.template_id LEFT JOIN projects p ON p.id=d.project_id LEFT JOIN investment_opportunities o ON o.id=d.investment_opportunity_id LEFT JOIN investment_opportunities dto ON dto.id=dt.investment_opportunity_id WHERE ((?='lead' AND d.lead_id=?) OR (?='inquiry' AND d.inquiry_id=?)) ORDER BY d.sent_at DESC");
?>
<div class="admin-page-head"><div><h1>CRM</h1><p>Manage relationship information, investment details, and projects of interest.</p></div><button type="button" class="primary" data-admin-modal-open="add-contact-modal">Add CRM Record</button></div>
<?php if ($message): ?><div class="status success"><?=e($message)?></div><?php endif; ?>
<?php if ($error): ?><div class="status error"><?=e($error)?></div><?php endif; ?>
<?php if(!$interestsInstalled):?><div class="status error">Projects of Interest is unavailable because the required database table is missing. Contact the Super Admin to run the current database installer.</div><?php endif;?>
<?php if(!$extendedFieldsInstalled):?><div class="status error">Additional contact fields are unavailable because the required database table is missing. Contact the Super Admin to run the current database installer.</div><?php endif;?>
<div class="admin-lead-toolbar admin-contact-toolbar">
    <div class="admin-search-form"><input id="contact-search" type="search" placeholder="Search CRM records as you type…" autocomplete="off"></div>
    <select id="contact-status-filter" aria-label="Filter contacts by status"><option value="">Status:</option><?php foreach($leadStatusOptions as $key=>$label):?><option value="<?=e($key)?>"><?=e($label)?></option><?php endforeach;?></select>
    <select id="contact-project-filter" aria-label="Filter contacts by project"><option value="">Project Interested In:</option><?php foreach($contactOpportunities as $opportunity):?><option value="<?=(int)$opportunity['id']?>"><?=e($opportunity['project_name'])?></option><?php endforeach;?></select>
</div>
<div class="admin-compact-list" id="contact-list">
<?php foreach ($contacts as $r):
    $isLead = $r['_record_type'] === 'lead';
    $recordType = $isLead ? 'lead' : 'inquiry';
    $recordId = (int)$r['id'];
    if ($isLead) {
        $r = array_merge($r, [
            'company_name'=>'',
            'inquiry_type'=>'',
            'prospective_investor_type'=>'',
            'investment_amount'=>'',
            'message'=>'',
        ], contact_extended_fields_row('lead',$recordId));
    }
    $panelId = 'contact-' . $recordType . '-' . $recordId;
    $newsletterSubscriber = contact_newsletter_subscriber((string)($r['email'] ?? ''));
    $projectInterests=contact_project_interest_rows($recordType,$recordId);
    $recordProjectIds=array_map('intval',array_column($projectInterests,'investment_opportunity_id'));if(!$isLead&&(int)($r['investment_opportunity_id']??0)>0)$recordProjectIds[]=(int)$r['investment_opportunity_id'];$recordProjectIds=array_values(array_unique($recordProjectIds));
    $searchFields = [$r['full_name'] ?? '', $r['email'] ?? '', $r['phone'] ?? '', $r['status'] ?? '', 'inquiry'];
    if ($isLead) {
        $searchFields[] = $r['source_name'] ?? '';
        $searchFields[] = $r['reference_number'] ?? '';
        $searchFields[] = $r['company_name'] ?? '';
        $searchFields[] = $r['inquiry_type'] ?? '';
        $searchFields[] = $r['prospective_investor_type'] ?? '';
        $searchFields[] = $r['investment_amount'] ?? '';
        $statusOptions = $leadStatusOptions;
    } else {
        $searchFields[] = $r['company_name'] ?? '';
        $searchFields[] = $r['inquiry_type'] ?? '';
        $searchFields[] = $r['opportunity_name'] ?? '';
        $searchFields[] = $r['prospective_investor_type'] ?? '';
        $searchFields[] = $r['investment_amount'] ?? '';
        $statusOptions = $inquiryStatusOptions;
    }
    foreach($projectInterests as $interestRow)$searchFields[]=$interestRow['project_name'].' '.$interestRow['status'].' '.$interestRow['interest_level'];
?>
<article class="admin-compact-record" data-contact-key="<?=e($recordType.'-'.$recordId)?>" data-status="<?=e((string)$r['status'])?>" data-project-ids="<?=e(implode(',',$recordProjectIds))?>" data-search-text="<?=e(strtolower(implode(' ', array_map('strval', $searchFields))))?>">
    <header class="admin-compact-record__summary admin-contact-summary">
        <button class="admin-expand-button" type="button" aria-expanded="false" aria-controls="<?=e($panelId)?>" data-accordion-button><span class="admin-expand-icon">+</span><span>Details</span></button>
        <div class="admin-project-badges" data-project-summary title="<?=e(implode(', ',array_column($projectInterests,'project_name')))?>">
            <?php if(count($projectInterests)>3):?><span class="admin-project-count-summary">3+ Projects</span>
            <?php elseif($projectInterests):?><?php foreach($projectInterests as $interestRow):?><span><?=e($interestRow['project_name'])?></span><?php endforeach;?>
            <?php else:?><span class="admin-project-empty">No Projects</span><?php endif;?>
        </div>
        <div class="admin-contact-name"><strong><?=e($r['full_name'])?></strong></div>
        <div class="admin-contact-email"><?=email_link((string)$r['email'])?></div>
        <div class="admin-contact-phone"><?=phone_link((string)$r['phone'])?></div>
        <form method="post" class="admin-status-form admin-status-form-compact admin-contact-status-autosave">
            <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="record_type" value="<?=$recordType?>"><input type="hidden" name="record_id" value="<?=$recordId?>"><input type="hidden" name="action" value="status">
            <select name="status"><?php foreach ($statusOptions as $key => $label): ?><option value="<?=e($key)?>" <?=$r['status'] === $key ? 'selected' : ''?>><?=e($label)?></option><?php endforeach; ?></select>
        </form>
    </header>
    <div class="admin-compact-record__details" id="<?=e($panelId)?>" hidden>
        <form method="post" class="admin-inline-edit-form admin-contact-autosave-form">
            <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="record_type" value="<?=$recordType?>"><input type="hidden" name="record_id" value="<?=$recordId?>"><input type="hidden" name="action" value="auto_save_contact"><input type="hidden" name="ajax" value="1">
            <label>Name<input name="full_name" value="<?=e($r['full_name'])?>" required></label>
            <label>Email<input type="email" name="email" value="<?=e($r['email'])?>" required></label>
            <label>Phone<input name="phone" value="<?=e(format_phone((string)$r['phone']))?>"></label>
            <?php if ($isLead): ?>
                <label>Source<input name="source_name" value="<?=e((string)$r['source_name'])?>"></label>
            <?php endif; ?>
            <label>Company<input name="company_name" value="<?=e((string)($r['company_name']??''))?>"></label>
            <label>What Can We Help You With?<select name="inquiry_type"><option value="">TBD</option><?php foreach ($typeLabels as $key => $label): ?><option value="<?=e($key)?>" <?=($r['inquiry_type']??'') === $key ? 'selected' : ''?>><?=e($label)?></option><?php endforeach; ?></select></label>
            <label>Status<select name="status"><?php foreach ($statusOptions as $key => $label): ?><option value="<?=e($key)?>" <?=$r['status'] === $key ? 'selected' : ''?>><?=e($label)?></option><?php endforeach; ?></select></label>
            <label>Prospective Investor Type<select name="prospective_investor_type"><option value="">TBD</option><?php foreach(['individual'=>'Individual','joint'=>'Joint','llc_partnership'=>'LLC / Partnership','trust'=>'Trust','retirement_account'=>'Retirement Account','other'=>'Other'] as $key=>$label):?><option value="<?=e($key)?>" <?=($r['prospective_investor_type']??'')===$key?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></label>
            <label>Amount They May Consider Investing<input class="admin-currency-input" name="investment_amount" inputmode="numeric" value="<?=!empty($r['investment_amount'])?e('$'.number_format((int)$r['investment_amount'])):''?>" placeholder="$0"></label>
            <div class="admin-original-message admin-field-wide"><strong>Original Message:</strong><span><?=nl2br(e(trim((string)($r['message']??'')) !== '' ? (string)$r['message'] : '—'))?></span></div>
        </form>
        <section class="admin-contact-newsletter-card" aria-labelledby="<?=e($panelId)?>-newsletter-title">
            <div class="admin-contact-newsletter-card__copy">
                <span class="admin-contact-newsletter-card__eyebrow">Newsletter</span>
                <h3 id="<?=e($panelId)?>-newsletter-title">Newsletter Status</h3>
                <?php if (!$newsletterSubscriber): ?>
                    <span class="admin-newsletter-status-pill not-subscribed"><span aria-hidden="true"></span> Not Subscribed</span>
                    <p>This email is not currently in Newsletter Subscribers.</p>
                <?php elseif ((string)$newsletterSubscriber['status'] === 'active'): ?>
                    <span class="admin-newsletter-status-pill active"><span aria-hidden="true"></span> Active</span>
                    <p>Added <?=e(date('M j, Y', strtotime((string)($newsletterSubscriber['consent_at'] ?: $newsletterSubscriber['created_at']))))?> · Source: <?=e(ucwords(str_replace('_',' ',(string)$newsletterSubscriber['source'])))?></p>
                <?php elseif ((string)$newsletterSubscriber['status'] === 'unsubscribed'): ?>
                    <span class="admin-newsletter-status-pill disabled"><span aria-hidden="true"></span> Disabled</span>
                    <p>This subscriber is currently disabled and will not receive campaigns.</p>
                <?php else: ?>
                    <span class="admin-newsletter-status-pill suppressed"><span aria-hidden="true"></span> Suppressed</span>
                    <p>This address is suppressed and will not receive campaigns.</p>
                <?php endif; ?>
            </div>
            <div class="admin-contact-newsletter-card__actions">
                <?php if (!$newsletterSubscriber): ?>
                    <form method="post" onsubmit="return confirm('Add <?=e(addslashes((string)$r['email']))?> to Newsletter Subscribers?')">
                        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="record_type" value="<?=$recordType?>"><input type="hidden" name="record_id" value="<?=$recordId?>"><input type="hidden" name="action" value="add_to_newsletter">
                        <button class="admin-newsletter-add-button admin-small-button"><span aria-hidden="true">+</span> Add to Newsletter</button>
                    </form>
                <?php elseif ((string)$newsletterSubscriber['status'] !== 'active'): ?>
                    <form method="post" onsubmit="return confirm('Enable this newsletter subscriber?')">
                        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="record_type" value="<?=$recordType?>"><input type="hidden" name="record_id" value="<?=$recordId?>"><input type="hidden" name="action" value="enable_newsletter">
                        <button class="admin-newsletter-enable-button admin-small-button">Enable Newsletter</button>
                    </form>
                <?php else: ?>
                    <a class="admin-newsletter-view-button admin-small-button" href="newsletter_subscribers.php?q=<?=rawurlencode((string)$r['email'])?>">View Subscriber</a>
                <?php endif; ?>
            </div>
        </section>

        <div class="admin-notes-panel"><label>Internal Notes</label><textarea class="admin-autosave-notes" data-note-type="<?=$recordType?>" data-record-id="<?=$recordId?>" data-csrf-token="<?=e(csrf_token())?>"><?=e((string)($r['notes'] ?? ''))?></textarea></div>
        <?php $selectedProjectIds=array_map('intval',array_column($projectInterests,'investment_opportunity_id')); ?>
        <div class="admin-project-interest-section"><div class="admin-project-interest-title-row"><div><h3>Projects of Interest</h3><p>Select the projects this contact is interested in. Relevant documents will appear below.</p></div></div><form method="post" class="admin-project-selector-autosave admin-project-button-selector"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="record_type" value="<?=$recordType?>"><input type="hidden" name="record_id" value="<?=$recordId?>"><input type="hidden" name="action" value="sync_project_interests"><input type="hidden" name="ajax" value="1"><div class="admin-project-button-list" role="group" aria-label="Projects of Interest"><?php foreach($selectedProjectIds as $selectedProjectId):?><input type="hidden" name="project_ids[]" value="<?=(int)$selectedProjectId?>" data-selected-project-input><?php endforeach;?><?php foreach($contactOpportunities as $opportunity):$isSelected=in_array((int)$opportunity['id'],$selectedProjectIds,true);?><button type="button" class="admin-project-interest-button <?=$isSelected?'is-selected':''?>" data-project-id="<?=(int)$opportunity['id']?>" data-project-name="<?=e($opportunity['project_name'])?>" aria-pressed="<?=$isSelected?'true':'false'?>"><span class="admin-project-button-check" aria-hidden="true"></span><span class="admin-project-button-label"><?=e($opportunity['project_name'])?></span></button><?php endforeach;?><?php if(!$contactOpportunities):?><p>No investment opportunities are available.</p><?php endif;?></div></form></div>
        <?php
        $deliveryStmt->execute([$recordType,$recordId,$recordType,$recordId]);
        $allDeliveries=$deliveryStmt->fetchAll();
        $deliveries=array_values(array_filter($allDeliveries, static function(array $delivery) use ($selectedProjectIds): bool {
            $deliveryProjectId=(int)($delivery['delivery_opportunity_id']??0);
            return $deliveryProjectId>0 && in_array($deliveryProjectId,$selectedProjectIds,true);
        }));
        ?>
        <?php $documentsOpen = isset($_GET['document_sent']) && (string)($_GET['expand'] ?? '') === $panelId; ?>
        <details class="admin-contact-documents admin-contact-documents-collapsible" data-contact-documents <?=$selectedProjectIds?'':'hidden'?> <?=$documentsOpen ? 'open' : ''?>>
            <summary class="admin-documents-toggle"><span>Documents</span><span class="admin-documents-toggle-icon" aria-hidden="true">+</span></summary>
            <div class="admin-contact-documents-body">
                <form method="post" class="admin-send-document-form" onsubmit="return confirm('Email the selected document to <?=e(addslashes((string)$r['email']))?>?')">
                    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="record_type" value="<?=$recordType?>"><input type="hidden" name="record_id" value="<?=$recordId?>"><input type="hidden" name="action" value="send_document">
                    <select name="template_id" title="Select Project Document" required><option value="">Select Project Document</option><?php foreach($documentTemplates as $doc):if($doc['entity_type']!=='opportunity')continue;?><option data-project-id="<?=(int)$doc['entity_id']?>" value="<?=(int)$doc['id']?>" <?=in_array((int)$doc['entity_id'],$selectedProjectIds,true)?'':'hidden'?>><?=e($doc['entity_name'].' — '.$doc['document_name'])?><?=((int)($doc['requires_acceptance']??0)===1)?' — Acceptance Required':''?></option><?php endforeach;?></select>
                    <button class="admin-document-button admin-small-button" <?=filter_var($r['email'],FILTER_VALIDATE_EMAIL)&&$selectedProjectIds?'':'disabled'?>>Send Document</button>
                    <span class="admin-no-project-documents" hidden>No documents are available for the selected projects.</span>
                </form>
                <div class="admin-document-history" data-document-history>
                    <strong>Documents Sent</strong>
                    <span class="admin-document-history-empty" <?=$deliveries?'hidden':''?>>None sent for the selected projects.</span>
                    <?php if ($deliveries): ?><div class="admin-agreement-history"><?php foreach ($deliveries as $delivery): ?><?php $deliveryProjectId=(int)($delivery['delivery_opportunity_id']??0); ?><div class="admin-delivery-card" data-delivery-project-id="<?=$deliveryProjectId?>"><strong><?=e($delivery['project_name'].' — '.$delivery['document_name'])?></strong><?php $deliveryStatus=trim((string)($delivery['status']??''));$deliveryStatusLabel=$deliveryStatus!==''?ucfirst($deliveryStatus):'Unknown';?><span class="admin-status-pill <?=e($deliveryStatus!==''?$deliveryStatus:'unknown')?>"><?=e($deliveryStatusLabel)?></span><small>Sent <?=e(date('M j, Y g:i A', strtotime($delivery['sent_at'])))?> to <?=e((string)$delivery['recipient_email'])?><?php if ($delivery['accepted_at']): ?> · Accepted <?=e(date('M j, Y g:i A', strtotime($delivery['accepted_at'])))?> by <?=e((string)$delivery['accepted_name'])?><?php endif; ?></small><?php if(strtolower((string)$delivery['status'])==='accepted'):?><form method="post" class="admin-revoke-agreement-form" onsubmit="return confirm('Revoke this accepted agreement? The existing acceptance will be cleared and the agreement link can be used again.')"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="record_type" value="<?=$recordType?>"><input type="hidden" name="record_id" value="<?=$recordId?>"><input type="hidden" name="delivery_id" value="<?=(int)$delivery['id']?>"><input type="hidden" name="action" value="revoke_agreement"><button class="admin-danger-button admin-small-button">Revoke Agreement</button></form><?php endif;?></div><?php endforeach; ?></div><?php endif; ?>
                </div>
            </div>
        </details>
        <?php $activityModalId='activity-'.$recordType.'-'.$recordId;$emailModalId='email-'.$recordType.'-'.$recordId; ?>
        <div class="admin-contact-bottom-actions">
            <button type="button" class="admin-email-button admin-small-button" data-admin-modal-open="<?=e($emailModalId)?>"><span aria-hidden="true">✉</span> Send Email</button>
            <button type="button" class="admin-activity-button admin-small-button" data-admin-modal-open="<?=e($activityModalId)?>" data-activity-record-type="<?=e($recordType)?>" data-activity-record-id="<?=$recordId?>"><span aria-hidden="true">◷</span> Activity</button>
            <form method="post" onsubmit="return confirm('Delete this contact? This action cannot be undone.')"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="record_type" value="<?=$recordType?>"><input type="hidden" name="record_id" value="<?=$recordId?>"><input type="hidden" name="action" value="delete"><button class="admin-delete-contact-button admin-small-button"><span aria-hidden="true">⌫</span> Delete Contact</button></form>
        </div>
        <div class="admin-modal" id="<?=e($emailModalId)?>" hidden role="dialog" aria-modal="true" aria-labelledby="<?=e($emailModalId)?>-title"><div class="admin-modal-dialog"><header><h2 id="<?=e($emailModalId)?>-title">Send Email — <?=e((string)$r['full_name'])?></h2><button type="button" aria-label="Close email" data-admin-modal-close>&times;</button></header><div class="admin-modal-body"><form method="post" enctype="multipart/form-data" class="admin-modal-email-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="record_type" value="<?=$recordType?>"><input type="hidden" name="record_id" value="<?=$recordId?>"><input type="hidden" name="action" value="send_email"><label>To<input value="<?=e((string)$r['email'])?>" disabled></label><label>Email Subject<input name="email_subject" required></label><label>Email Body<textarea name="email_body" rows="8" required></textarea></label><label>Optional Attachment<input type="file" name="email_attachment" accept=".pdf,.ppt,.pptx,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png"><small>Maximum file size: 15 MB.</small></label><div class="admin-modal-actions"><button type="button" class="secondary admin-small-button" data-admin-modal-close>Cancel</button><button class="admin-email-button admin-small-button">Send Email</button></div></form></div></div></div>
        <div class="admin-modal" id="<?=e($activityModalId)?>" hidden role="dialog" aria-modal="true" aria-labelledby="<?=e($activityModalId)?>-title"><div class="admin-modal-dialog"><header><h2 id="<?=e($activityModalId)?>-title">Activity — <?=e((string)$r['full_name'])?></h2><button type="button" aria-label="Close activity" data-admin-modal-close>&times;</button></header><div class="admin-modal-body" data-activity-modal-body><section class="admin-activity-timeline"><h3>Contact History</h3><p class="admin-help-text">Open Activity to load the latest contact history.</p></section></div></div></div>
    </div>
</article>
<?php endforeach; ?>
<?php if (!$contacts): ?><div class="admin-empty-state">No CRM records found.</div><?php endif; ?>
</div>
<div class="admin-pagination-footer admin-contacts-pagination-footer"><strong id="contact-total-label">TOTAL CRM RECORDS: <?=$inquiryCount?></strong><nav class="admin-pagination" id="contact-pagination" aria-label="CRM pagination"></nav></div>
<div class="admin-modal" id="add-contact-modal" hidden role="dialog" aria-modal="true" aria-labelledby="add-contact-modal-title">
    <div class="admin-modal-dialog admin-modal-dialog-wide">
        <header><h2 id="add-contact-modal-title">Add Contact</h2><button type="button" aria-label="Close add contact" data-admin-modal-close>&times;</button></header>
        <div class="admin-modal-body">
            <form method="post" class="admin-add-contact-form" id="admin-add-contact-form">
                <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="record_type" value="inquiry"><input type="hidden" name="record_id" value="0"><input type="hidden" name="action" value="add_contact">
                <div class="admin-form-grid"><label>Full Name *<input name="full_name" required></label><label>Company<input name="company_name"></label></div>
                <div class="admin-form-grid"><label>Email *<input type="email" name="email" required></label><label>Phone<input name="phone" placeholder="XXX-XXX-XXXX"></label></div>
                <div class="admin-form-grid"><label>Subject<select name="inquiry_type" id="admin-add-contact-type"><?php foreach($typeLabels as $key=>$label):?><option value="<?=e($key)?>"><?=e($label)?></option><?php endforeach;?></select></label><label>Status<select name="status"><?php foreach($inquiryStatusOptions as $key=>$label):?><option value="<?=e($key)?>"><?=e($label)?></option><?php endforeach;?></select></label></div>
                <div id="admin-add-contact-investment-fields" hidden>
                    <label>Project of Interest<select name="investment_opportunity_id"><option value="">Open to Any Opportunity</option><?php foreach($contactOpportunities as $opportunity):?><option value="<?=(int)$opportunity['id']?>"><?=e($opportunity['project_name'])?></option><?php endforeach;?></select></label>
                    <div class="admin-form-grid"><label>Prospective Investor Type<select name="prospective_investor_type"><option value="">Select</option><option value="individual">Individual</option><option value="joint">Joint</option><option value="llc_partnership">LLC / Partnership</option><option value="trust">Trust</option><option value="retirement_account">Retirement Account</option><option value="other">Other</option></select></label><label>Amount You May Consider Investing<select name="investment_amount"><option value="">Select</option><?php foreach(['25000','50000','75000','100000','150000','250000'] as $amount):?><option value="<?=$amount?>">$<?=number_format((int)$amount)?></option><?php endforeach;?></select></label></div>
                </div>
                <label>Message<textarea name="message" rows="5"></textarea></label>
                <label>Internal Notes<textarea name="notes" rows="4"></textarea></label>
                <div class="admin-modal-actions"><button type="button" class="secondary admin-small-button" data-admin-modal-close>Cancel</button><button class="primary admin-small-button">Add Contact</button></div>
            </form>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var type = document.getElementById('admin-add-contact-type');
    var investment = document.getElementById('admin-add-contact-investment-fields');
    if (type && investment) {
        var update = function () { investment.hidden = type.value !== 'investment_opportunity'; };
        type.addEventListener('change', update);
        update();
    }

    document.querySelectorAll('.admin-currency-input').forEach(function (input) {
        var formatCurrency = function () {
            var digits = input.value.replace(/[^0-9]/g, '');
            input.value = digits ? '$' + Number(digits).toLocaleString('en-US') : '';
        };
        input.addEventListener('blur', formatCurrency);
        input.addEventListener('focus', function () { input.value = input.value.replace(/[^0-9]/g, ''); });
        formatCurrency();
    });

    var toastTimer = null;
    function showContactToast(message, type) {
        var tray = document.querySelector('.admin-toast-tray');
        if (!tray) {
            tray = document.createElement('div');
            tray.className = 'admin-toast-tray';
            document.body.appendChild(tray);
        }
        tray.innerHTML = '';
        var toast = document.createElement('div');
        toast.className = 'admin-toast admin-toast--' + (type || 'success');
        var text = document.createElement('span');
        text.textContent = message;
        toast.appendChild(text);
        tray.appendChild(toast);
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () {
            toast.classList.add('admin-toast--leaving');
            setTimeout(function () { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 260);
        }, type === 'error' ? 5000 : 1800);
    }

    function parseJsonResponse(response) {
        return response.text().then(function (text) {
            var clean = text.trim();
            var data = null;
            try { data = JSON.parse(clean); } catch (firstError) {
                var start = clean.lastIndexOf('{');
                var end = clean.lastIndexOf('}');
                if (start !== -1 && end > start) {
                    try { data = JSON.parse(clean.slice(start, end + 1)); } catch (secondError) {}
                }
            }
            if (!data) throw new Error('The server did not return a usable save response.');
            if (!response.ok || !data.ok) throw new Error(data.error || 'Could not save.');
            return data;
        });
    }


    document.querySelectorAll('.admin-activity-button[data-activity-record-type][data-activity-record-id]').forEach(function (button) {
        button.addEventListener('click', function () {
            var modalId = button.getAttribute('data-admin-modal-open');
            var modal = modalId ? document.getElementById(modalId) : null;
            var body = modal ? modal.querySelector('[data-activity-modal-body]') : null;
            if (!body) return;
            body.innerHTML = '<section class="admin-activity-timeline"><h3>Contact History</h3><p class="admin-help-text admin-activity-loading">Loading latest activity…</p></section>';
            var params = new URLSearchParams({
                record_type: button.getAttribute('data-activity-record-type') || '',
                record_id: button.getAttribute('data-activity-record-id') || ''
            });
            fetch('contact-activity.php?' + params.toString(), {
                credentials: 'same-origin',
                headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'},
                cache: 'no-store'
            }).then(parseJsonResponse).then(function (data) {
                body.innerHTML = data.html;
            }).catch(function (error) {
                body.innerHTML = '<section class="admin-activity-timeline"><h3>Contact History</h3><p class="admin-help-text">' + String(error.message || 'Could not load activity.').replace(/[&<>"']/g, function (char) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]; }) + '</p></section>';
            });
        });
    });

    document.querySelectorAll('.admin-contact-autosave-form').forEach(function (form) {
        var timer = null;
        var requestNumber = 0;
        var save = function () {
            var currentRequest = ++requestNumber;
            fetch('contact-autosave.php', {
                method: 'POST', body: new FormData(form), credentials: 'same-origin',
                headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}
            }).then(parseJsonResponse).then(function (data) {
                if (currentRequest !== requestNumber) return;
                var article = form.closest('.admin-compact-record');
                if (article) {
                    var summaryStatus = article.querySelector('.admin-contact-status-autosave select[name="status"]');
                    if (summaryStatus) summaryStatus.value = data.status;
                    var summaryName = article.querySelector('.admin-compact-record__name strong');
                    if (summaryName) summaryName.textContent = data.name;
                }
                showContactToast('Saved', 'success');
            }).catch(function (error) {
                if (currentRequest !== requestNumber) return;
                showContactToast(error.message || 'Could not save.', 'error');
            });
        };
        form.querySelectorAll('input:not([type="hidden"]):not([readonly]), select, textarea:not([readonly])').forEach(function (field) {
            var eventName = field.tagName === 'SELECT' ? 'change' : 'input';
            field.addEventListener(eventName, function () {
                clearTimeout(timer);
                timer = setTimeout(save, eventName === 'change' ? 100 : 650);
            });
            if (eventName !== 'change') field.addEventListener('change', function () { clearTimeout(timer); save(); });
        });
    });

    document.querySelectorAll('.admin-contact-status-autosave').forEach(function (statusForm) {
        var select = statusForm.querySelector('select[name="status"]');
        if (!select) return;
        select.addEventListener('change', function () {
            var article = statusForm.closest('.admin-compact-record');
            var editForm = article ? article.querySelector('.admin-contact-autosave-form') : null;
            var detailStatus = editForm ? editForm.querySelector('select[name="status"]') : null;
            if (detailStatus) {
                detailStatus.value = select.value;
                detailStatus.dispatchEvent(new Event('change', {bubbles:true}));
            }
        });
    });

    document.querySelectorAll('.admin-project-selector-autosave').forEach(function (form) {
        var article = form.closest('.admin-compact-record');
        var documents = null;
        var documentSelect = null;
        var documentButton = null;
        var emptyMessage = null;
        var documentHistory = null;
        var documentHistoryEmpty = null;
        var refreshDocumentReferences = function () {
            documents = article ? article.querySelector('[data-contact-documents]') : null;
            documentSelect = documents ? documents.querySelector('select[name="template_id"]') : null;
            documentButton = documents ? documents.querySelector('.admin-document-button') : null;
            emptyMessage = documents ? documents.querySelector('.admin-no-project-documents') : null;
            documentHistory = documents ? documents.querySelector('[data-document-history]') : null;
            documentHistoryEmpty = documentHistory ? documentHistory.querySelector('.admin-document-history-empty') : null;
        };
        refreshDocumentReferences();
        var projectSummary = article ? article.querySelector('[data-project-summary]') : null;
        var getSelectedProjects = function () {
            return Array.from(form.querySelectorAll('input[data-selected-project-input]')).map(function(input){ return input.value; });
        };
        var getSelectedProjectNames = function () {
            return Array.from(form.querySelectorAll('.admin-project-interest-button.is-selected')).map(function(button){
                return button.dataset.projectName || button.textContent.trim();
            });
        };
        var updateProjectSummary = function () {
            if (!article || !projectSummary) return;
            var selected = getSelectedProjects();
            var names = getSelectedProjectNames();
            article.dataset.projectIds = selected.join(',');
            projectSummary.title = names.join(', ');
            projectSummary.innerHTML = '';
            if (names.length > 3) {
                var count = document.createElement('span');
                count.className = 'admin-project-count-summary';
                count.textContent = names.length + ' Projects';
                projectSummary.appendChild(count);
            } else if (names.length) {
                names.forEach(function(name){
                    var pill = document.createElement('span');
                    pill.textContent = name;
                    projectSummary.appendChild(pill);
                });
            } else {
                var empty = document.createElement('span');
                empty.className = 'admin-project-empty';
                empty.textContent = 'No Projects';
                projectSummary.appendChild(empty);
            }
        };
        var updateDocuments = function () {
            var selected = getSelectedProjects();
            if (documents) documents.hidden = selected.length === 0;
            var available = 0;
            if (documentSelect) {
                Array.from(documentSelect.options).forEach(function(option, index){
                    if (index === 0) return;
                    var visible = selected.indexOf(option.dataset.projectId || '') !== -1;
                    option.hidden = !visible;
                    option.disabled = !visible;
                    if (visible) available++;
                });
                if (documentSelect.selectedOptions.length && documentSelect.selectedOptions[0].disabled) documentSelect.value = '';
            }
            if (documentButton) documentButton.disabled = selected.length === 0 || available === 0;
            if (emptyMessage) emptyMessage.hidden = !(selected.length > 0 && available === 0);
            var visibleDeliveries = 0;
            if (documentHistory) {
                documentHistory.querySelectorAll('.admin-delivery-card[data-delivery-project-id]').forEach(function(card){
                    var visible = selected.indexOf(card.dataset.deliveryProjectId || '') !== -1;
                    card.hidden = !visible;
                    if (visible) visibleDeliveries++;
                });
            }
            if (documentHistoryEmpty) documentHistoryEmpty.hidden = visibleDeliveries > 0;
        };
        var refreshDocumentsFromServer = function () {
            if (!article || !article.dataset.contactKey) return Promise.resolve();
            var url = new URL(window.location.href);
            url.searchParams.set('_documents_refresh', Date.now().toString());
            return fetch(url.toString(), {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            }).then(function (response) {
                if (!response.ok) throw new Error('Could not refresh documents.');
                return response.text();
            }).then(function (html) {
                var parsed = new DOMParser().parseFromString(html, 'text/html');
                var selector = '.admin-compact-record[data-contact-key="' + CSS.escape(article.dataset.contactKey) + '"] [data-contact-documents]';
                var freshDocuments = parsed.querySelector(selector);
                var currentDocuments = article.querySelector('[data-contact-documents]');
                if (freshDocuments && currentDocuments) {
                    currentDocuments.replaceWith(freshDocuments);
                    refreshDocumentReferences();
                    updateDocuments();
                }
            });
        };
        updateProjectSummary();
        updateDocuments();
        var timer = null;
        form.querySelectorAll('.admin-project-interest-button[data-project-id]').forEach(function (button) {
            button.addEventListener('click', function () {
                var projectId = button.dataset.projectId;
                var existing = form.querySelector('input[data-selected-project-input][value="' + CSS.escape(projectId) + '"]');
                if(existing && document.body.classList.contains('admin-partner-role')) return;
                if (existing) {
                    existing.remove();
                    button.classList.remove('is-selected');
                    button.setAttribute('aria-pressed', 'false');
                } else {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'project_ids[]';
                    input.value = projectId;
                    input.setAttribute('data-selected-project-input', '');
                    form.appendChild(input);
                    button.classList.add('is-selected');
                    button.setAttribute('aria-pressed', 'true');
                }
                updateProjectSummary();
                updateDocuments();
                if (typeof renderContacts === 'function') renderContacts();
                clearTimeout(timer);
                timer = setTimeout(function () {
                    fetch('contact-autosave.php', {
                        method: 'POST',
                        body: new FormData(form),
                        credentials: 'same-origin',
                        headers: {'X-Requested-With': 'XMLHttpRequest'}
                    }).then(parseJsonResponse).then(function () {
                        return refreshDocumentsFromServer();
                    }).then(function () {
                        showContactToast('Saved', 'success');
                    }).catch(function (error) {
                        showContactToast(error.message || 'Could not save projects.', 'error');
                    });
                }, 250);
            });
        });
    });

    var contactRows = Array.from(document.querySelectorAll('#contact-list > .admin-compact-record'));
    var contactSearch = document.getElementById('contact-search');
    var contactStatus = document.getElementById('contact-status-filter');
    var contactProject = document.getElementById('contact-project-filter');
    var contactPager = document.getElementById('contact-pagination');
    var contactTotal = document.getElementById('contact-total-label');
    var contactPage = 1;
    var contactsPerPage = 25;
    var requestedProject = new URLSearchParams(window.location.search).get('project_id');
    if(requestedProject && contactProject.querySelector('option[value="'+CSS.escape(requestedProject)+'"]')) contactProject.value=requestedProject;
    function renderContacts() {
        var q = (contactSearch.value || '').trim().toLowerCase();
        var status = contactStatus.value;
        var project = contactProject.value;
        var filtered = contactRows.filter(function(row){
            var projects = (row.dataset.projectIds || '').split(',').filter(Boolean);
            return (!q || (row.dataset.searchText || '').indexOf(q) !== -1) && (!status || row.dataset.status === status) && (!project || projects.indexOf(project) !== -1);
        });
        var pages = Math.max(1, Math.ceil(filtered.length / contactsPerPage));
        contactPage = Math.min(contactPage, pages);
        contactRows.forEach(function(row){ row.hidden = true; });
        filtered.slice((contactPage-1)*contactsPerPage, contactPage*contactsPerPage).forEach(function(row){ row.hidden = false; });
        contactPager.innerHTML = '';
        [['Previous',contactPage-1,contactPage===1]].concat(Array.from({length:pages},function(_,i){return [String(i+1),i+1,false];}),[['Next',contactPage+1,contactPage===pages]]).forEach(function(item){
            var button=document.createElement('button'); button.type='button'; button.textContent=item[0]; button.disabled=item[2];
            if(item[1]===contactPage) button.classList.add('active');
            button.addEventListener('click',function(){contactPage=item[1];renderContacts();document.getElementById('contact-list').scrollIntoView({behavior:'smooth',block:'start'});});
            contactPager.appendChild(button);
        });
        contactTotal.textContent = 'TOTAL CRM RECORDS: ' + contactRows.length + (filtered.length !== contactRows.length ? ' · SHOWING ' + filtered.length : '');
    }
    [contactSearch,contactStatus,contactProject].forEach(function(control){
        control.addEventListener(control.tagName==='INPUT'?'input':'change',function(){contactPage=1;renderContacts();});
    });
    renderContacts();

});
</script>
<?php require __DIR__ . '/_footer.php'; ?>
