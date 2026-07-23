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
function admin_portal_role(?array $user=null): bool {
    $user=$user??admin_user();
    if(!$user)return false;
    return in_array(normalized_role((string)($user['role']??'')),['super admin','super administrator','admin','administrator'],true);
}
function login_destination(?array $user=null): string { return app_url(admin_portal_role($user)?'/admin/':'/dev/'); }
function require_login(): void {
    if(!admin_user()){$_SESSION['login_return']=$_SERVER['REQUEST_URI']??'/';header('Location: '.app_url('/login.php'));exit;}
}
function require_admin(): void {
    require_login();
    if(!admin_portal_role()){header('Location: '.app_url('/dev/'));exit;}
}
