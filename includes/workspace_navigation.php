<?php
declare(strict_types=1);

function znp_workspace_navigation_config(): array
{
    return [
        'admin'=>['label'=>'Admin Portal','href'=>'/admin/','icon'=>'fa-solid fa-shield-halved','permission'=>'admin'],
        'construction'=>['label'=>'Construction Portal','href'=>'/dev/','icon'=>'fa-solid fa-hammer','permission'=>'construction'],
        'management'=>['label'=>'Management Portal','href'=>'/manage/','icon'=>'fa-solid fa-briefcase','permission'=>'management'],
        'public'=>['label'=>'Public Website','href'=>'/','icon'=>'fa-solid fa-house','permission'=>'staff'],
        'logout'=>['label'=>'Logout','href'=>'/logout.php','icon'=>'fa-solid fa-right-from-bracket','permission'=>'staff','class'=>'znp-logout-icon'],
    ];
}

function znp_workspace_navigation_allowed(string $permission): bool
{
    $staff=admin_user();
    if(!$staff)return false;
    if($permission==='admin')return admin_portal_role($staff);
    if($permission==='management')return management_portal_role($staff);
    if($permission==='construction')return !management_user_role($staff)&&!investments_only_role($staff)&&!partner_role($staff)&&!legacy_admin_role($staff);
    return $permission==='staff';
}

function znp_render_workspace_icons(string $activePortal): void
{
    echo '<div class="znp-portal-icons" aria-label="Portal navigation">';
    foreach(znp_workspace_navigation_config() as $key=>$item){
        if(!znp_workspace_navigation_allowed((string)$item['permission']))continue;
        $classes=['znp-portal-icon'];
        if($key===$activePortal)$classes[]='is-active';
        if(!empty($item['class']))$classes[]=(string)$item['class'];
        echo '<a class="'.e(implode(' ',$classes)).'" href="'.e(app_url((string)$item['href'])).'" aria-label="'.e((string)$item['label']).'" data-label="'.e((string)$item['label']).'"'.($key===$activePortal?' aria-current="page"':'').'>';
        echo '<i class="'.e((string)$item['icon']).'" aria-hidden="true"></i></a>';
    }
    echo '</div>';
}
