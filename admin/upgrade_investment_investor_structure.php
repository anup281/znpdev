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
                "CREATE TABLE IF NOT EXISTS investment_investor_structures (
                    investment_opportunity_id BIGINT UNSIGNED NOT NULL,
                    lp_total_units DECIMAL(15,2) NOT NULL DEFAULT 2000000.00,
                    updated_by_admin_id BIGINT UNSIGNED NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (investment_opportunity_id),
                    KEY idx_investor_structure_updated_by (updated_by_admin_id),
                    CONSTRAINT chk_investor_structure_units CHECK (lp_total_units >= 0)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS investment_project_investors (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    investment_opportunity_id BIGINT UNSIGNED NOT NULL,
                    investor_type VARCHAR(2) NOT NULL,
                    first_name VARCHAR(100) NOT NULL,
                    last_name VARCHAR(100) NOT NULL,
                    ownership_percent DECIMAL(9,4) NULL,
                    investment_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                    unit_amount DECIMAL(15,2) NULL,
                    created_by_admin_id BIGINT UNSIGNED NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_project_investors_project_type (investment_opportunity_id, investor_type),
                    KEY idx_project_investors_name (last_name, first_name),
                    CONSTRAINT chk_project_investor_type CHECK (investor_type IN ('GP','LP')),
                    CONSTRAINT chk_project_investor_percent CHECK (ownership_percent IS NULL OR (ownership_percent > 0 AND ownership_percent <= 100)),
                    CONSTRAINT chk_project_investor_amount CHECK (investment_amount >= 0),
                    CONSTRAINT chk_project_investor_units CHECK (unit_amount IS NULL OR unit_amount > 0)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            $structureColumns=['investment_opportunity_id','lp_total_units','updated_by_admin_id','created_at','updated_at'];
            $investorColumns=['id','investment_opportunity_id','investor_type','first_name','last_name','ownership_percent','investment_amount','unit_amount','created_by_admin_id','created_at','updated_at'];
            $missingStructure=array_diff($structureColumns,$pdo->query('SHOW COLUMNS FROM investment_investor_structures')->fetchAll(PDO::FETCH_COLUMN));
            $missingInvestors=array_diff($investorColumns,$pdo->query('SHOW COLUMNS FROM investment_project_investors')->fetchAll(PDO::FETCH_COLUMN));
            if($missingStructure||$missingInvestors){
                throw new RuntimeException('An investor table exists but is incomplete. Missing columns: '.implode(', ',array_merge($missingStructure,$missingInvestors)).'.');
            }

            $seed=$pdo->prepare(
                'INSERT IGNORE INTO investment_investor_structures
                 (investment_opportunity_id,lp_total_units,updated_by_admin_id)
                 SELECT id,2000000.00,? FROM investment_opportunities'
            );
            $seed->execute([admin_user()['id']??null]);
            $message='Investor structure upgrade completed successfully. '
                .number_format($seed->rowCount()).' missing project structure(s) initialized with 2,000,000 LP units.';
        }catch(Throwable $exception){
            error_log('Investment investor structure upgrade failed: '.$exception->getMessage());
            $error='Upgrade failed: '.$exception->getMessage();
        }
    }
}

require __DIR__.'/_header.php';
?>
<div class="admin-page-head">
  <div>
    <h1>Investment Investor Structure Upgrade</h1>
    <p>Create project-specific GP ownership and LP unit subscription records.</p>
  </div>
</div>
<?php if($message):?><div class="status success"><?=e($message)?></div><?php endif;?>
<?php if($error):?><div class="status error"><?=e($error)?></div><?php endif;?>
<section class="admin-panel">
  <h2>GP and LP Investor Structure</h2>
  <p>This rerunnable installer creates the project investor tables and initializes each investment with 2,000,000 LP units. Existing structures and investors are preserved.</p>
  <form method="post" onsubmit="return confirm('Install or verify the Investment Investor Structure tables?')">
    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
    <button class="primary" type="submit">Install / Verify Upgrade</button>
  </form>
</section>
<?php require __DIR__.'/_footer.php';?>
