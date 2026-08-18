<?php
declare(strict_types=1);
require_once __DIR__.'/db.php';
require_once __DIR__.'/functions.php';
if(session_status()!==PHP_SESSION_ACTIVE) session_start();
function admin_user(): ?array { return $_SESSION['admin_user']??null; }
function portal_session_user(array $user): array {
    $mustChange=normalized_role((string)($user['role']??''))==='management user'?0:(int)($user['must_change_password']??0);
    return ['id'=>(int)$user['id'],'name'=>(string)$user['full_name'],'role'=>(string)$user['role'],'email'=>(string)$user['email'],'construction_only'=>(int)($user['construction_only']??0),'construction_role'=>(string)($user['construction_role']??''),'must_change_password'=>$mustChange];
}
function normalized_role(?string $role): string {
    $role=strtolower(trim((string)$role));
    $role=str_replace(['_','-'],' ',$role);
    return preg_replace('/\s+/',' ',$role) ?: '';
}
function super_admin_role(?array $user=null): bool {
    $user=$user??admin_user();
    return $user&&in_array(normalized_role((string)($user['role']??'')),['super admin','super administrator'],true);
}
function legacy_admin_role(?array $user=null): bool {
    $user=$user??admin_user();
    return $user&&in_array(normalized_role((string)($user['role']??'')),['admin','administrator'],true);
}
function investments_only_role(?array $user=null): bool {
    $user=$user??admin_user();
    if(!$user)return false;
    $role=normalized_role((string)($user['role']??''));
    if(in_array($role,['investments only','investment only','investment','investor'],true))return true;
    if($role!==''||(int)($user['id']??0)<1)return false;
    try{
        if(!investment_user_access_ready())return false;
        $check=db()->prepare('SELECT 1 FROM investment_user_access WHERE admin_user_id=? LIMIT 1');
        $check->execute([(int)$user['id']]);
        return (bool)$check->fetchColumn();
    }catch(Throwable $exception){
        error_log('Investment-only legacy role check failed: '.$exception->getMessage());
        return false;
    }
}
function investment_user_access_ready(): bool {
    return db_schema_ready(['investment_user_access']);
}
function partner_role(?array $user=null): bool {
    $user=$user??admin_user();
    return $user&&normalized_role((string)($user['role']??''))==='partner';
}
function partner_access_ready(): bool {
    return db_schema_ready(['admin_partner_permissions']);
}
function partner_permission_definitions(): array {
    return [
        'projects'=>'Website Projects',
        'team'=>'Website Team',
        'investments'=>'Investments',
        'crm'=>'CRM',
        'subscribers'=>'Marketing Subscribers',
        'campaigns'=>'Marketing Campaigns',
    ];
}
function partner_has_permission(string $permission,?array $user=null): bool {
    $user=$user??admin_user();
    if(!partner_role($user))return admin_portal_role($user);
    if(!isset(partner_permission_definitions()[$permission])||!partner_access_ready())return false;
    $check=db()->prepare('SELECT 1 FROM admin_partner_permissions WHERE admin_user_id=? AND permission_key=? LIMIT 1');
    $check->execute([(int)($user['id']??0),$permission]);
    return (bool)$check->fetchColumn();
}
function can_access_investment(int $investmentId,?array $user=null): bool {
    $user=$user??admin_user();
    if($investmentId<1||!$user)return false;
    if(!investments_only_role($user))return admin_portal_role($user);
    if(!investment_user_access_ready())return false;
    $check=db()->prepare('SELECT 1 FROM investment_user_access WHERE admin_user_id=? AND investment_opportunity_id=? LIMIT 1');
    $check->execute([(int)($user['id']??0),$investmentId]);
    return (bool)$check->fetchColumn();
}
function require_investment_access(int $investmentId,?array $user=null): void {
    if(can_access_investment($investmentId,$user))return;
    http_response_code(403);
    exit('You do not have access to this investment.');
}
function admin_portal_role(?array $user=null): bool {
    $user=$user??admin_user();
    if(!$user)return false;
    return super_admin_role($user)||partner_role($user)
        || investments_only_role($user);
}
function management_user_role(?array $user=null): bool {
    $user=$user??admin_user();
    if(!$user)return false;
    $role=normalized_role((string)($user['role']??''));
    if($role==='management user')return true;
    if($role!==''||(int)($user['id']??0)<1)return false;
    try{
        if(!db_table_exists('management_property_users'))return false;
        $check=db()->prepare('SELECT 1 FROM management_property_users WHERE admin_user_id=? LIMIT 1');
        $check->execute([(int)$user['id']]);
        return (bool)$check->fetchColumn();
    }catch(Throwable $exception){
        error_log('Management user legacy role check failed: '.$exception->getMessage());
        return false;
    }
}
function management_admin_role(?array $user=null): bool {
    $user=$user??admin_user();
    return super_admin_role($user);
}
function management_portal_role(?array $user=null): bool {
    return management_admin_role($user)||management_user_role($user);
}
function investments_only_admin_pages(): array {
    return [
        'opportunities.php',
        'opportunity.php',
        'investment_investors_ajax.php',
        'logout.php',
    ];
}
function enforce_admin_page_access(?array $user=null, ?string $script=null): void {
    $user=$user??admin_user();
    $page=basename($script??(string)($_SERVER['PHP_SELF']??''));
    if(investments_only_role($user)){
        if(in_array($page,investments_only_admin_pages(),true))return;
        http_response_code(403);exit('This account is restricted to Website > Investments.');
    }
    if(!partner_role($user))return;
    if((string)($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
        $action=strtolower(trim((string)($_POST['action']??'')));
        $destructiveActions=['delete','delete_project','archive','remove_project_interest','revoke_agreement'];
        if(in_array($action,$destructiveActions,true)||str_starts_with($action,'delete_')||str_starts_with($action,'remove_')){
            http_response_code(403);exit('Partner accounts cannot delete, archive, remove, or revoke records.');
        }
    }
    if(in_array($page,['index.php','logout.php'],true))return;
    $groups=[
        'projects'=>['projects.php','project_edit.php'],
        'team'=>['team.php','team_edit.php'],
        'investments'=>['opportunities.php','opportunity.php','opportunity_edit.php','investment_investors_ajax.php','model_settings_autosave.php','document_file.php'],
        'crm'=>['contacts.php','leads.php','inquiries.php','contact-activity.php','contact-autosave.php','save_notes.php'],
        'subscribers'=>['newsletter_subscribers.php'],
        'campaigns'=>['newsletter_campaigns.php','newsletter_campaign_edit.php'],
    ];
    foreach($groups as $permission=>$pages)if(in_array($page,$pages,true)&&partner_has_permission($permission,$user))return;
    http_response_code(403);exit('This Partner account does not have access to this admin area.');
}
function login_destination(?array $user=null): string {
    if(investments_only_role($user))return app_url('/admin/opportunities.php');
    if(management_user_role($user))return app_url('/manage/');
    return app_url(admin_portal_role($user)?'/admin/':'/dev/');
}
function require_login(): void {
    if(!admin_user()){$_SESSION['login_return']=$_SERVER['REQUEST_URI']??'/';header('Location: '.app_url('/login.php'));exit;}
}
function require_admin(): void {
    require_login();
    if(!admin_portal_role()){header('Location: '.app_url('/dev/'));exit;}
    enforce_admin_page_access();
}
