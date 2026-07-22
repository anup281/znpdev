<?php
$pageTitle='Newsletter Preferences';$activePage='';require __DIR__.'/includes/db.php';require __DIR__.'/includes/functions.php';
$token=trim((string)($_GET['token']??''));$message='The unsubscribe link is invalid or has expired.';
if(preg_match('/^[a-f0-9]{64}$/',$token)){$check=db()->prepare('SELECT id FROM newsletter_subscribers WHERE unsubscribe_token=?');$check->execute([$token]);if($check->fetchColumn()){$s=db()->prepare("UPDATE newsletter_subscribers SET status='unsubscribed',unsubscribed_at=COALESCE(unsubscribed_at,NOW()) WHERE unsubscribe_token=?");$s->execute([$token]);$message='You have been unsubscribed from ZNP Development newsletters.';}}
require __DIR__.'/includes/header.php';
?><main><section class="public-page-hero"><div class="container"><h1>Email Preferences</h1><p><?=e($message)?></p></div></section><section class="container band"><a class="primary" href="index.php">Return Home</a></section></main><?php include __DIR__.'/includes/footer.php';?>
