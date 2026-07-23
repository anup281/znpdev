<?php
require_once __DIR__ . '/../includes/auth.php';
require_admin();
$admin = admin_user();
if (!in_array(normalized_role((string)($admin['role'] ?? '')), ['super admin','super administrator'], true)) { http_response_code(403); exit('Super Admin access is required.'); }
$message='';$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
 if (!csrf_check($_POST['csrf_token']??'')) $error='Your session expired. Refresh and try again.';
 else try {
  db()->exec("CREATE TABLE IF NOT EXISTS investment_model_settings (
   investment_opportunity_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
   cumulative_preferred_return_percent DECIMAL(9,4) NULL, tier1_lp_split DECIMAL(9,4) NULL, tier1_gp_split DECIMAL(9,4) NULL, tier2_lp_irr_hurdle DECIMAL(9,4) NULL, tier2_lp_split DECIMAL(9,4) NULL, tier2_gp_split DECIMAL(9,4) NULL,
   total_development_costs DECIMAL(15,2) NULL, contingency_percent DECIMAL(9,4) NULL, contingency_expected_use_percent DECIMAL(9,4) NULL, development_fee_percent DECIMAL(9,4) NULL, capital_fee_percent DECIMAL(9,4) NULL,
   bank_loan_percent DECIMAL(9,4) NULL, bank_loan_equity_percent DECIMAL(9,4) NULL, bank_loan_prime_rate DECIMAL(9,4) NULL, bank_loan_spread DECIMAL(9,4) NULL, bank_loan_amortization_years DECIMAL(9,2) NULL, bank_loan_interest_only_months INT UNSIGNED NULL,
   targeted_gross_income_monthly DECIMAL(15,2) NULL, targeted_operating_expense_percent DECIMAL(9,4) NULL, hold_period_years DECIMAL(9,2) NULL, first_distribution_month INT UNSIGNED NULL, minimum_cash_reserve DECIMAL(15,2) NOT NULL DEFAULT 100000.00,
   year1_occupancy_percent DECIMAL(9,4) NULL, year2_occupancy_percent DECIMAL(9,4) NULL, year3_occupancy_percent DECIMAL(9,4) NULL, year4_occupancy_percent DECIMAL(9,4) NULL, year5_occupancy_percent DECIMAL(9,4) NULL, year6_occupancy_percent DECIMAL(9,4) NULL,
   refinance_month INT UNSIGNED NULL, refinance_loan_amount DECIMAL(15,2) NULL, refinance_interest_rate DECIMAL(9,4) NULL, refinance_amortization_years DECIMAL(9,2) NULL, refinance_interest_only_months INT UNSIGNED NULL, refinance_term_years DECIMAL(9,2) NULL,
   exit_cap_rate DECIMAL(9,4) NULL, commission_percent DECIMAL(9,4) NULL, updated_by_admin_id BIGINT UNSIGNED NULL,
   created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  $columns=db()->query("SHOW COLUMNS FROM investment_model_settings LIKE 'first_distribution_month'")->fetchAll();
  if(!$columns)db()->exec('ALTER TABLE investment_model_settings ADD COLUMN first_distribution_month INT UNSIGNED NULL AFTER hold_period_years');
  $columns=db()->query("SHOW COLUMNS FROM investment_model_settings LIKE 'minimum_cash_reserve'")->fetchAll();
  if(!$columns)db()->exec('ALTER TABLE investment_model_settings ADD COLUMN minimum_cash_reserve DECIMAL(15,2) NOT NULL DEFAULT 100000.00 AFTER first_distribution_month');
  else{db()->exec('UPDATE investment_model_settings SET minimum_cash_reserve=100000.00 WHERE minimum_cash_reserve IS NULL');db()->exec('ALTER TABLE investment_model_settings MODIFY minimum_cash_reserve DECIMAL(15,2) NOT NULL DEFAULT 100000.00');}
  $message='Investment Model Settings table is ready.';
 } catch(Throwable $e) {$error=$e->getMessage();}
}
require __DIR__.'/_header.php';
?>
<div class="admin-page-head"><div><h1>Investment Model Settings Upgrade</h1><p>Creates the project-specific settings table. This installer is safe to run again.</p></div></div>
<?php if($message):?><div class="status success"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="status error"><?=e($error)?></div><?php endif;?>
<form method="post" class="admin-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><button class="primary">Install / Verify Upgrade</button></form>
<?php require __DIR__.'/_footer.php';?>
