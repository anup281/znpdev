<?php
declare(strict_types=1);
require_once __DIR__.'/db.php';
require_once __DIR__.'/functions.php';
if(session_status()!==PHP_SESSION_ACTIVE) session_start();
function admin_user(): ?array { return $_SESSION['admin_user']??null; }
function normalized_role(?string $role): string {
    $role=strtolower(trim((string)$role));
    $role=str_replace(['_','-'],' ',$role);
    return preg_replace('/\s+/',' ',$role) ?: '';
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
    static $ready=null;
    if($ready!==null)return $ready;
    try{$ready=(bool)db()->query("SHOW TABLES LIKE 'investment_user_access'")->fetchColumn();}
    catch(Throwable $exception){error_log('Investment user access check failed: '.$exception->getMessage());$ready=false;}
    return $ready;
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
    return in_array(normalized_role((string)($user['role']??'')),['super admin','super administrator','admin','administrator'],true)
        || investments_only_role($user);
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
    if(!investments_only_role($user))return;
    $page=basename($script??(string)($_SERVER['PHP_SELF']??''));
    if(in_array($page,investments_only_admin_pages(),true))return;
    http_response_code(403);
    exit('This account is restricted to Website > Investments.');
}
function login_destination(?array $user=null): string {
    if(investments_only_role($user))return app_url('/admin/opportunities.php');
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
