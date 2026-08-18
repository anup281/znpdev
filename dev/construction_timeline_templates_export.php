<?php
declare(strict_types=1);

require_once __DIR__.'/includes/bootstrap.php';

if(!dev_is_super()){
    http_response_code(403);
    exit('Timeline template export access denied.');
}

$templateQuery=db()->query("SELECT id,template_name,schedule_scope,is_default,is_active,created_at,updated_at FROM construction_schedule_templates WHERE is_active=1 AND schedule_scope IN ('Project','Building') ORDER BY FIELD(schedule_scope,'Project','Building'),is_default DESC,id");
$itemQuery=db()->prepare('SELECT id,sequence_no,activity_name,default_duration_days,default_trade,created_at FROM construction_schedule_template_items WHERE template_id=? ORDER BY sequence_no,id');
$templates=[];

foreach($templateQuery->fetchAll()?:[] as $template){
    $scope=(string)$template['schedule_scope'];
    $itemQuery->execute([(int)$template['id']]);
    $items=[];

    foreach($itemQuery->fetchAll()?:[] as $position=>$item){
        $items[]=[
            'position'=>$position+1,
            'sequence'=>(int)$item['sequence_no'],
            'activity'=>(string)$item['activity_name'],
            'duration_days'=>max(1,(int)$item['default_duration_days']),
            'trade'=>filled_export_value($item['default_trade']??null),
            'predecessor_position'=>$position>0?$position:null,
        ];
    }

    $templates[]=[
        'key'=>timeline_export_key($scope,(string)$template['template_name']),
        'name'=>(string)$template['template_name'],
        'scope'=>$scope==='Project'?'sitework':'building',
        'legacy_scope'=>$scope,
        'is_default'=>(bool)$template['is_default'],
        'scheduling'=>'sequential',
        'item_count'=>count($items),
        'total_duration_days'=>array_sum(array_column($items,'duration_days')),
        'items'=>$items,
        'created_at'=>(string)($template['created_at']??''),
        'updated_at'=>(string)($template['updated_at']??''),
    ];
}

$payload=[
    'format'=>'znpdev-construction-timeline-templates-v1',
    'exported_at'=>gmdate('c'),
    'source'=>'znpdev',
    'scope_mapping'=>[
        'Project'=>'sitework',
        'Building'=>'building',
    ],
    'scheduling_note'=>'Activities are scheduled sequentially in exported order. The legacy template does not store explicit predecessor relationships.',
    'templates'=>$templates,
];

header('Content-Type: application/json; charset=UTF-8');
header('Content-Disposition: attachment; filename="construction-timeline-templates.json"');
header('Cache-Control: no-store, private');
echo json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);

function timeline_export_key(string $scope,string $name): string
{
    $slug=strtolower(trim((string)preg_replace('/[^a-z0-9]+/i','-',$name),'-'));
    return strtolower($scope).'-'.($slug!==''?$slug:'template');
}

function filled_export_value(mixed $value): ?string
{
    $value=trim((string)$value);
    return $value!==''?$value:null;
}
