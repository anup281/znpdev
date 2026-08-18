<?php
declare(strict_types=1);require_once __DIR__.'/../includes/auth.php';require_admin();
if($_SERVER['REQUEST_METHOD']!=='POST'||!csrf_check($_POST['csrf_token']??''))app_json_result(false,'Session expired',[],403);
$type=($_POST['type']??'')==='inquiry'?'inquiry':'lead';$id=(int)($_POST['record_id']??0);$notes=trim((string)($_POST['notes']??''));$table=$type==='lead'?'leads':'contact_inquiries';
try{$s=db()->prepare("SELECT notes FROM {$table} WHERE id=?");$s->execute([$id]);$old=$s->fetchColumn();if($old===false)throw new RuntimeException('Contact not found');db()->prepare("UPDATE {$table} SET notes=? WHERE id=?")->execute([$notes,$id]);if(trim((string)$old)!==$notes)contact_activity_log($type,$id,'notes_updated','Internal notes updated',null,admin_user()['id']??null);app_json_response(['ok'=>true,'saved_at'=>date('g:i A')]);}catch(Throwable $e){app_json_result(false,'Unable to save notes',[],500);}
