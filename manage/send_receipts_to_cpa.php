<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
manage_require_admin();

$year=(int)($_POST['year']??0);
$month=(int)($_POST['month']??0);
$redirect='receipts.php?year='.$year.'&month='.$month;
function manage_cpa_redirect(string $redirect,string $type,string $message): never {
    $_SESSION['management_cpa_notice']=['type'=>$type,'message'=>$message];
    header('Location: '.$redirect);
    exit;
}

if($_SERVER['REQUEST_METHOD']!=='POST'||!csrf_check((string)($_POST['csrf_token']??'')))manage_cpa_redirect($redirect,'error','Your session expired. Refresh the page and try again.');
if($year<2026||$year>2045||$month<1||$month>12||($year===2026&&$month<7))manage_cpa_redirect('receipts.php','error','Select a valid receipt period.');

try{
    $propertyId=manage_active_property_id();
    $property=$propertyId?manage_property($propertyId):null;
    if(!$property||!manage_receipts_schema_ready())throw new RuntimeException('The selected property or receipt period is unavailable.');
    if(!manage_cpa_delivery_schema_ready()||!manage_cpa_delivery_cc_ready())throw new RuntimeException('Run the latest CPA Delivery History upgrade before sending.');
    $cpaEmail=strtolower(trim(setting('management_cpa_email')));
    if(!filter_var($cpaEmail,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Add a valid CPA email address in Management Settings before sending.');
    $ccEmail=strtolower(trim((string)($managementUser['email']??'')));
    if(!filter_var($ccEmail,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Your Super Admin account needs a valid email address before sending to the CPA.');

    $categories=db()->query('SELECT category_name FROM management_receipt_categories WHERE is_active=1 ORDER BY category_name')->fetchAll(PDO::FETCH_COLUMN);
    $categoryTotals=array_fill_keys(array_map('strval',$categories),0.0);$categoryTotals['UNCATEGORIZED']=0.0;
    $query=db()->prepare("SELECT COALESCE(c.category_name,'UNCATEGORIZED') category_name,SUM(t.amount) total FROM management_receipt_transactions t JOIN management_receipt_periods p ON p.id=t.management_receipt_period_id LEFT JOIN management_receipt_categories c ON c.id=t.category_id LEFT JOIN management_receipt_analysis_runs r ON r.id=t.analysis_run_id WHERE p.management_property_id=? AND p.report_year=? AND p.report_month=? AND (r.is_current=1 OR t.analysis_run_id IS NULL) GROUP BY COALESCE(c.category_name,'UNCATEGORIZED')");
    $query->execute([$propertyId,$year,$month]);foreach($query->fetchAll() as $row)$categoryTotals[(string)$row['category_name']]=(float)$row['total'];ksort($categoryTotals,SORT_NATURAL|SORT_FLAG_CASE);

    $payments=[];if(manage_receipt_payments_schema_ready()){$query=db()->prepare('SELECT payment_date,bank_account_paid_from,amount_paid FROM management_receipt_payments rp JOIN management_receipt_periods p ON p.id=rp.management_receipt_period_id WHERE p.management_property_id=? AND p.report_year=? AND p.report_month=? ORDER BY payment_date,rp.id');$query->execute([$propertyId,$year,$month]);$payments=$query->fetchAll();}
    $escape=static fn(mixed $value):string=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
    $money=static fn(float $amount):string=>($amount<0?'-$':'$').number_format(abs($amount),2);
    $monthName=DateTimeImmutable::createFromFormat('!m',(string)$month)->format('F');
    $tableStyle='width:100%;border-collapse:collapse;margin:0 0 24px;font-family:Arial,sans-serif';$cellStyle='padding:9px 11px;border:1px solid #cfd9e4;text-align:left';
    $html='<h2 style="font-family:Arial,sans-serif;margin:0 0 6px">'.$escape($property['property_name']).'</h2><p style="font-family:Arial,sans-serif;margin:0 0 20px">'.$escape($monthName.' '.$year).' Receipts Breakdown</p>';
    $html.='<table style="'.$tableStyle.'"><thead><tr><th style="'.$cellStyle.'">CATEGORY</th><th style="'.$cellStyle.'">AMOUNT</th></tr></thead><tbody>';
    foreach($categoryTotals as $category=>$amount)$html.='<tr><td style="'.$cellStyle.'">'.$escape($category).'</td><td style="'.$cellStyle.'">'.$money((float)$amount).'</td></tr>';
    $html.='</tbody></table><h3 style="font-family:Arial,sans-serif;margin:0 0 10px">PAYMENT HISTORY</h3><table style="'.$tableStyle.'"><thead><tr><th style="'.$cellStyle.'">DATE</th><th style="'.$cellStyle.'">ACCOUNT</th><th style="'.$cellStyle.'">AMOUNT</th></tr></thead><tbody>';
    if($payments){foreach($payments as $payment)$html.='<tr><td style="'.$cellStyle.'">'.$escape(date('M j, Y',strtotime((string)$payment['payment_date']))).'</td><td style="'.$cellStyle.'">'.$escape($payment['bank_account_paid_from']).'</td><td style="'.$cellStyle.'">'.$money((float)$payment['amount_paid']).'</td></tr>';}else $html.='<tr><td style="'.$cellStyle.'" colspan="3">No submitted payments.</td></tr>';
    $html.='</tbody></table>';

    $result=app_send_mail_detailed($cpaEmail,'Receipts Breakdown - '.$property['property_name'].' - '.$monthName.' '.$year,$html,[],[$ccEmail]);
    if(!$result['ok'])throw new RuntimeException('The CPA email could not be sent: '.$result['error']);
    $categoryTotal=array_sum($categoryTotals);$paymentTotal=array_sum(array_map(static fn(array $payment):float=>(float)$payment['amount_paid'],$payments));
    db()->prepare('INSERT INTO management_cpa_deliveries(management_property_id,report_year,report_month,recipient_email,cc_email,recipient_name,sent_by_admin_user_id,category_total,payment_total,sent_at) VALUES(?,?,?,?,?,?,?,?,?,NOW())')->execute([$propertyId,$year,$month,$cpaEmail,$ccEmail,trim(setting('management_cpa_name'))?:null,(int)($managementUser['id']??0)?:null,$categoryTotal,$paymentTotal]);
    manage_cpa_redirect($redirect,'success','The breakdown and payment history were emailed to '.$cpaEmail.' and copied to '.$ccEmail.'.');
}catch(Throwable $error){error_log('CPA receipt email failed: '.$error->getMessage());manage_cpa_redirect($redirect,'error',$error->getMessage());}
