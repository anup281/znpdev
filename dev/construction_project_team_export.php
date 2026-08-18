<?php
declare(strict_types=1);

require_once __DIR__.'/includes/bootstrap.php';

if(!dev_is_super()){
    http_response_code(403);
    exit('Project Team export access denied.');
}

$projectId=dev_active_project_id((int)($_GET['project_id']??0));
$project=dev_require_project($projectId);

$assignmentQuery=db()->prepare("SELECT pc.id,pc.trade_role,pc.contract_status,pc.notes,pc.created_at,pc.updated_at,c.id company_id,c.company_name,c.primary_contact,c.cell_phone,c.office_phone,c.email,c.website,c.license_number FROM construction_project_companies pc JOIN construction_companies c ON c.id=pc.construction_company_id WHERE pc.construction_project_id=? ORDER BY pc.id");
$assignmentQuery->execute([$projectId]);
$contactQuery=db()->prepare('SELECT id,name,title,phone,email,is_primary FROM construction_vendor_contacts WHERE construction_company_id=? AND is_active=1 ORDER BY is_primary DESC,id');
$assignments=[];
foreach($assignmentQuery->fetchAll()?:[] as $assignment){
    $contactQuery->execute([(int)$assignment['company_id']]);
    $assignments[]=[
        'id'=>(int)$assignment['id'],
        'vendor'=>[
            'legacy_id'=>(int)$assignment['company_id'],
            'name'=>(string)$assignment['company_name'],
            'contact_name'=>(string)($assignment['primary_contact']??''),
            'email'=>(string)($assignment['email']??''),
            'cell_phone'=>(string)($assignment['cell_phone']??''),
            'office_phone'=>(string)($assignment['office_phone']??''),
            'website'=>(string)($assignment['website']??''),
            'license_number'=>(string)($assignment['license_number']??''),
        ],
        'trade'=>(string)($assignment['trade_role']??''),
        'status'=>(string)($assignment['contract_status']??'Active'),
        'notes'=>(string)($assignment['notes']??''),
        'contacts'=>array_map(static fn(array $contact):array=>[
            'legacy_id'=>(int)$contact['id'],
            'name'=>(string)$contact['name'],
            'title'=>(string)($contact['title']??''),
            'phone'=>(string)($contact['phone']??''),
            'email'=>(string)($contact['email']??''),
            'contact_type'=>(bool)($contact['is_primary']??false)?'primary':'other',
            'is_primary'=>(bool)($contact['is_primary']??false),
        ],$contactQuery->fetchAll()?:[]),
        'created_at'=>(string)($assignment['created_at']??''),
        'updated_at'=>(string)($assignment['updated_at']??''),
    ];
}

$thirdPartyContacts=[];
if(db_table_exists('construction_project_contacts')){
    $thirdPartyQuery=db()->prepare('SELECT * FROM construction_project_contacts WHERE construction_project_id=? AND is_active=1 ORDER BY id');
    $thirdPartyQuery->execute([$projectId]);
    $thirdPartyContacts=array_map(static fn(array $contact):array=>[
        'id'=>(int)$contact['id'],
        'role_title'=>(string)$contact['role_title'],
        'organization_name'=>(string)($contact['organization_name']??''),
        'contact_name'=>(string)($contact['contact_name']??''),
        'phone'=>(string)($contact['phone']??''),
        'email'=>(string)($contact['email']??''),
        'address'=>(string)($contact['address']??''),
        'notes'=>(string)($contact['notes']??''),
        'created_at'=>(string)($contact['created_at']??''),
        'updated_at'=>(string)($contact['updated_at']??''),
    ],$thirdPartyQuery->fetchAll()?:[]);
}

$payload=[
    'format'=>'znpdev-project-team-v1',
    'exported_at'=>gmdate('c'),
    'project'=>['id'=>$projectId,'name'=>(string)$project['project_name']],
    'assignments'=>$assignments,
    'third_party_contacts'=>$thirdPartyContacts,
];

$filename=(preg_replace('/[^a-z0-9]+/i','-',strtolower((string)$project['project_name']))?:'project').'-project-team.json';
header('Content-Type: application/json; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.trim($filename,'-').'"');
header('Cache-Control: no-store, private');
echo json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
