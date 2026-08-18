<?php
require __DIR__.'/../includes/db.php';require __DIR__.'/../includes/functions.php';
$secret=(string)($_GET['key']??($_SERVER['argv'][1]??''));$stored=setting('newsletter_cron_secret');
if(PHP_SAPI!=='cli'&&($stored===''||!hash_equals($stored,$secret))){http_response_code(403);exit('Forbidden');}
$result=newsletter_process_queue((int)setting('newsletter_batch_size','25'));app_json_response($result);
