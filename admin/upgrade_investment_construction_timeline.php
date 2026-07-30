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
                "CREATE TABLE IF NOT EXISTS investment_construction_draw_settings (
                    investment_opportunity_id BIGINT UNSIGNED NOT NULL,
                    month_number TINYINT UNSIGNED NOT NULL,
                    equity_spent_percent DECIMAL(9,4) NOT NULL DEFAULT 0.0000,
                    loan_drawn_percent DECIMAL(9,4) NOT NULL DEFAULT 0.0000,
                    updated_by_admin_id BIGINT UNSIGNED NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (investment_opportunity_id, month_number),
                    KEY idx_construction_draw_updated_by (updated_by_admin_id),
                    CONSTRAINT chk_construction_draw_month CHECK (month_number BETWEEN 1 AND 12),
                    CONSTRAINT chk_construction_equity_percent CHECK (equity_spent_percent BETWEEN 0 AND 100),
                    CONSTRAINT chk_construction_loan_percent CHECK (loan_drawn_percent BETWEEN 0 AND 100)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            $requiredColumns=[
                'investment_opportunity_id',
                'month_number',
                'equity_spent_percent',
                'loan_drawn_percent',
                'updated_by_admin_id',
                'created_at',
                'updated_at',
            ];
            $installedColumns=$pdo->query('SHOW COLUMNS FROM investment_construction_draw_settings')->fetchAll(PDO::FETCH_COLUMN);
            $missingColumns=array_diff($requiredColumns,$installedColumns);
            if($missingColumns){
                throw new RuntimeException('The construction timeline table exists but is incomplete. Missing columns: '.implode(', ',$missingColumns).'.');
            }

            $equityDefaults=[25,50,75,100,100,100,100,100,100,100,100,100];
            $loanDefaults=[0,0,0,0,12.5,25,37.5,50,62.5,75,87.5,100];
            $opportunityIds=$pdo->query('SELECT id FROM investment_opportunities ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
            $seed=$pdo->prepare(
                'INSERT IGNORE INTO investment_construction_draw_settings
                 (investment_opportunity_id,month_number,equity_spent_percent,loan_drawn_percent,updated_by_admin_id)
                 VALUES (?,?,?,?,?)'
            );
            $inserted=0;
            foreach($opportunityIds as $opportunityId){
                for($month=1;$month<=12;$month++){
                    $seed->execute([
                        (int)$opportunityId,
                        $month,
                        $equityDefaults[$month-1],
                        $loanDefaults[$month-1],
                        admin_user()['id']??null,
                    ]);
                    $inserted+=$seed->rowCount();
                }
            }

            $message='Construction timeline upgrade completed successfully. '
                .number_format(count($opportunityIds)).' investment(s) verified and '
                .number_format($inserted).' missing monthly setting(s) added.';
        }catch(Throwable $exception){
            error_log('Investment construction timeline upgrade failed: '.$exception->getMessage());
            $error='Upgrade failed: '.$exception->getMessage();
        }
    }
}

require __DIR__.'/_header.php';
?>
<div class="admin-page-head">
  <div>
    <h1>Investment Construction Timeline Upgrade</h1>
    <p>Create the project-specific 12-month equity-spend and construction-loan draw schedule.</p>
  </div>
</div>
<?php if($message):?><div class="status success"><?=e($message)?></div><?php endif;?>
<?php if($error):?><div class="status error"><?=e($error)?></div><?php endif;?>
<section class="admin-panel">
  <h2>Construction Timeline Settings</h2>
  <p>This rerunnable installer creates the required table and adds the 12-month example to investments that do not already have timeline values. Existing saved percentages are preserved.</p>
  <form method="post" onsubmit="return confirm('Install or verify the Investment Construction Timeline database table?')">
    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
    <button class="primary" type="submit">Install / Verify Upgrade</button>
  </form>
</section>
<?php require __DIR__.'/_footer.php';?>
