<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_admin();

if(!in_array(normalized_role((string)(admin_user()['role']??'')),['super admin','super administrator'],true)){
    http_response_code(403);
    exit('Only a Super Admin may run database upgrades.');
}

$message='';
$error='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!csrf_check((string)($_POST['csrf_token']??''))){
        $error='Your session expired. Refresh and try again.';
    }else{
        try{
            $pdo=db();
            if(!$pdo->query("SHOW TABLES LIKE 'investment_opportunities'")->fetchColumn()){
                throw new RuntimeException('The investment_opportunities table is not installed.');
            }

            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS investment_monthly_occupancy_settings (
                    investment_opportunity_id BIGINT UNSIGNED NOT NULL,
                    year_number TINYINT UNSIGNED NOT NULL,
                    month_number TINYINT UNSIGNED NOT NULL,
                    occupancy_percent DECIMAL(9,4) NOT NULL DEFAULT 0.0000,
                    updated_by_admin_id BIGINT UNSIGNED NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (investment_opportunity_id, year_number, month_number),
                    KEY idx_monthly_occupancy_updated_by (updated_by_admin_id),
                    CONSTRAINT chk_monthly_occupancy_year CHECK (year_number BETWEEN 1 AND 2),
                    CONSTRAINT chk_monthly_occupancy_month CHECK (month_number BETWEEN 1 AND 12),
                    CONSTRAINT chk_monthly_occupancy_percent CHECK (occupancy_percent BETWEEN 0 AND 100)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            $requiredColumns=['investment_opportunity_id','year_number','month_number','occupancy_percent','updated_by_admin_id','created_at','updated_at'];
            $installedColumns=$pdo->query('SHOW COLUMNS FROM investment_monthly_occupancy_settings')->fetchAll(PDO::FETCH_COLUMN);
            $missingColumns=array_diff($requiredColumns,$installedColumns);
            if($missingColumns){
                throw new RuntimeException('The monthly occupancy table exists but is incomplete. Missing columns: '.implode(', ',$missingColumns).'.');
            }

            $settingsAvailable=(bool)$pdo->query("SHOW TABLES LIKE 'investment_model_settings'")->fetchColumn();
            $opportunityIds=$pdo->query('SELECT id FROM investment_opportunities ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
            $annualSettings=$settingsAvailable?$pdo->prepare('SELECT year1_occupancy_percent,year2_occupancy_percent FROM investment_model_settings WHERE investment_opportunity_id=?'):null;
            $seed=$pdo->prepare(
                'INSERT IGNORE INTO investment_monthly_occupancy_settings
                 (investment_opportunity_id,year_number,month_number,occupancy_percent,updated_by_admin_id)
                 VALUES (?,?,?,?,?)'
            );
            $inserted=0;
            foreach($opportunityIds as $opportunityId){
                $yearDefaults=[1=>50.0,2=>95.0];
                if($annualSettings){
                    $annualSettings->execute([(int)$opportunityId]);
                    $annual=$annualSettings->fetch();
                    if($annual){
                        if($annual['year1_occupancy_percent']!==null)$yearDefaults[1]=(float)$annual['year1_occupancy_percent'];
                        if($annual['year2_occupancy_percent']!==null)$yearDefaults[2]=(float)$annual['year2_occupancy_percent'];
                    }
                }
                foreach($yearDefaults as $year=>$default){
                    for($month=1;$month<=12;$month++){
                        $seed->execute([(int)$opportunityId,$year,$month,$default,admin_user()['id']??null]);
                        $inserted+=$seed->rowCount();
                    }
                }
            }

            $message='Monthly occupancy upgrade completed successfully. '
                .number_format(count($opportunityIds)).' investment(s) verified and '
                .number_format($inserted).' missing monthly setting(s) added.';
        }catch(Throwable $exception){
            error_log('Investment monthly occupancy upgrade failed: '.$exception->getMessage());
            $error='Upgrade failed: '.$exception->getMessage();
        }
    }
}

require __DIR__.'/_header.php';
?>
<div class="admin-page-head">
  <div>
    <h1>Investment Monthly Occupancy Upgrade</h1>
    <p>Create project-specific Year 1 and Year 2 monthly occupancy assumptions.</p>
  </div>
</div>
<?php if($message):?><div class="status success"><?=e($message)?></div><?php endif;?>
<?php if($error):?><div class="status error"><?=e($error)?></div><?php endif;?>
<section class="admin-panel">
  <h2>Monthly Occupancy Settings</h2>
  <p>This rerunnable installer creates 24 monthly occupancy settings for each investment. Existing monthly values are preserved. New values are initialized from each project’s existing Year 1 and Year 2 annual occupancy assumptions.</p>
  <form method="post" onsubmit="return confirm('Install or verify the Investment Monthly Occupancy table?')">
    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
    <button class="primary" type="submit">Install / Verify Upgrade</button>
  </form>
</section>
<?php require __DIR__.'/_footer.php';?>
