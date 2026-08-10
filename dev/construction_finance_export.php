<?php
declare(strict_types=1);

require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/budget.php';
require_once __DIR__.'/includes/expense_tracker.php';
require_once __DIR__.'/includes/contracts.php';

if(!dev_is_super()){
    http_response_code(403);
    exit('Finance export access denied.');
}

$projectId=dev_active_project_id((int)($_GET['project_id']??0));
$project=dev_require_project($projectId);
dev_ensure_budget_schema();
dev_ensure_expense_tracker_schema();
dev_ensure_contract_schema();
dev_sync_contract_payment_expense_references($projectId);

$budget=dev_budget_for_project($projectId,(int)($user['id']??0));
$projectCompanies=[];
foreach(dev_budget_project_companies($projectId) as $company)$projectCompanies[(int)$company['id']]=$company;
$budgetItems=[];$templateCategories=[];
foreach(dev_budget_items((int)$budget['id']) as $item){
    $assignment=$projectCompanies[(int)($item['construction_project_company_id']??0)]??null;
    $record=[
        'id'=>(int)$item['id'],
        'category_key'=>(string)$item['category_key'],
        'category_name'=>(string)$item['category_name'],
        'name'=>(string)$item['item_name'],
        'amount'=>(float)$item['amount'],
        'reallocated_amount'=>(float)($item['reallocated_amount']??0),
        'reallocation_note'=>(string)($item['reallocation_note']??''),
        'is_completed'=>(bool)($item['is_completed']??false),
        'notes'=>(string)($item['notes']??''),
        'display_order'=>(int)$item['display_order'],
        'assigned_company_id'=>$assignment?(int)$assignment['id']:null,
        'assigned_company_name'=>$assignment?(string)$assignment['company_name']:'',
        'assigned_trade'=>$assignment?(string)$assignment['trade_role']:'',
    ];
    $budgetItems[]=$record;
    $categoryKey=(string)$item['category_key'];
    if(!isset($templateCategories[$categoryKey]))$templateCategories[$categoryKey]=[
        'key'=>$categoryKey,
        'name'=>(string)$item['category_name'],
        'display_order'=>(int)$item['display_order'],
        'items'=>[],
    ];
    $templateCategories[$categoryKey]['items'][]=[
        'source_id'=>(int)$item['id'],
        'name'=>(string)$item['item_name'],
        'amount'=>(float)$item['amount'],
        'display_order'=>(int)$item['display_order'],
    ];
}
$equitySources=array_map(static fn(array $source):array=>[
    'id'=>(int)$source['id'],
    'name'=>(string)$source['source_name'],
    'date'=>(string)($source['source_date']??''),
    'amount'=>(float)$source['amount'],
    'method'=>(string)$source['method'],
    'display_order'=>(int)$source['display_order'],
],dev_budget_equity_sources((int)$budget['id']));
$budgetSettings=[
    'id'=>(int)$budget['id'],
    'contingency_percent'=>(float)$budget['contingency_percent'],
    'management_fee_percent'=>(float)$budget['management_fee_percent'],
    'partnership_percent'=>(float)$budget['partnership_percent'],
    'bank_percent'=>(float)$budget['bank_percent'],
    'contingency_reallocated_amount'=>(float)($budget['contingency_reallocated_amount']??0),
    'contingency_reallocation_note'=>(string)($budget['contingency_reallocation_note']??''),
    'contingency_is_completed'=>(bool)($budget['contingency_is_completed']??false),
    'is_locked'=>(bool)($budget['is_locked']??false),
    'template_original_name'=>(string)($budget['template_original_name']??''),
    'template_mime_type'=>(string)($budget['template_mime_type']??''),
    'template_file_size'=>(int)($budget['template_file_size']??0),
    'line_items'=>$budgetItems,
    'equity_sources'=>$equitySources,
];
$budgetTemplate=[
    'name'=>(string)($budget['template_original_name']??'')?:((string)$project['project_name'].' Budget Template'),
    'assumptions'=>array_intersect_key($budgetSettings,array_flip(['contingency_percent','management_fee_percent','partnership_percent','bank_percent'])),
    'categories'=>array_values($templateCategories),
];

$contracts=[];
foreach(dev_contracts($projectId) as $contract){
    $changeOrders=array_map(static fn(array $changeOrder):array=>[
        'id'=>(int)$changeOrder['id'],
        'number'=>(string)$changeOrder['change_order_number'],
        'description'=>(string)$changeOrder['description'],
        'amount'=>(float)$changeOrder['amount'],
        'date'=>(string)$changeOrder['change_order_date'],
    ],dev_contract_change_orders((int)$contract['id']));
    $contracts[]=[
        'id'=>(int)$contract['id'],
        'vendor_name'=>(string)$contract['company_name'],
        'trade'=>(string)$contract['trade_role'],
        'contact_name'=>(string)$contract['contact_name'],
        'contact_email'=>(string)$contract['contact_email'],
        'contact_phone'=>(string)$contract['contact_phone'],
        'budget_item_id'=>isset($contract['construction_budget_item_id'])?(int)$contract['construction_budget_item_id']:null,
        'original_amount'=>(float)$contract['original_contract_amount'],
        'current_amount'=>(float)$contract['new_contract_amount'],
        'notes'=>(string)($contract['notes']??''),
        'change_orders'=>$changeOrders,
    ];
}

$bankDraws=[];$bankDrawIds=[];
foreach(dev_expense_groups($projectId) as $group){
    if((string)($group['group_type']??'')!=='draw')continue;
    $groupId=(int)$group['id'];$bankDrawIds[$groupId]=$groupId;
    $bankDraws[]=[
        'id'=>$groupId,
        'name'=>(string)$group['group_name'],
        'draw_number'=>isset($group['draw_number'])?(int)$group['draw_number']:null,
        'date_submitted'=>(string)($group['date_submitted']??''),
        'date_funded'=>(string)($group['date_funded']??''),
        'amount_funded'=>(float)($group['amount_funded']??0),
        'notes'=>'',
    ];
}

$expenses=array_map(static fn(array $expense):array=>[
    'record_type'=>'expense',
    'id'=>(int)$expense['id'],
    'contract_id'=>isset($expense['construction_contract_id'])?(int)$expense['construction_contract_id']:null,
    'budget_item_id'=>isset($expense['construction_budget_item_id'])?(int)$expense['construction_budget_item_id']:null,
    'bank_draw_id'=>$bankDrawIds[(int)($expense['expense_group_id']??0)]??null,
    'number'=>(string)$expense['expense_number'],
    'name'=>(string)$expense['expense_name'],
    'amount'=>(float)$expense['amount'],
    'retainage'=>(float)($expense['retainage_amount']??0),
    'date'=>(string)($expense['expense_date']??''),
    'payment_method'=>(string)$expense['payment_method'],
    'category'=>(string)$expense['category'],
    'group'=>(string)($expense['grouping_name']??'UNGROUPED'),
    'notes'=>'',
],dev_project_expenses($projectId));

$legacyQuery=db()->prepare('SELECT cp.* FROM construction_contract_payments cp JOIN construction_contracts ct ON ct.id=cp.construction_contract_id WHERE ct.construction_project_id=? AND cp.construction_project_expense_id IS NULL ORDER BY cp.entry_date,cp.id');
$legacyQuery->execute([$projectId]);
$legacyPayments=array_map(static fn(array $payment):array=>[
    'record_type'=>'legacy_contract_payment',
    'id'=>(int)$payment['id'],
    'contract_id'=>(int)$payment['construction_contract_id'],
    'number'=>(string)$payment['app_number'],
    'name'=>'Legacy contract payment',
    'amount'=>(float)$payment['amount_paid'],
    'retainage'=>(float)$payment['retainage'],
    'date'=>(string)$payment['entry_date'],
    'payment_method'=>(string)$payment['payment_method'],
    'category'=>'',
    'group'=>'LEGACY CONTRACT PAYMENTS',
    'notes'=>'Imported from the former contract-only payment ledger.',
],$legacyQuery->fetchAll()?:[]);

$payload=[
    'format'=>'znpdev-construction-finance-v1',
    'exported_at'=>gmdate('c'),
    'project'=>['id'=>$projectId,'name'=>(string)$project['project_name']],
    'budget'=>$budgetSettings,
    'budget_template'=>$budgetTemplate,
    'contracts'=>$contracts,
    'bank_draws'=>$bankDraws,
    'expenses'=>$expenses,
    'legacy_contract_payments'=>$legacyPayments,
];

$filename=(preg_replace('/[^a-z0-9]+/i','-',strtolower((string)$project['project_name']))?:'project').'-construction-finance.json';
header('Content-Type: application/json; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.trim($filename,'-').'"');
header('Cache-Control: no-store, private');
echo json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
