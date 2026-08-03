<?php
$pageTitle='Invest | ZNP Development'; $activePage='invest'; $pageAssetVersion='20260729-588';
require __DIR__.'/includes/db.php'; require __DIR__.'/includes/functions.php';
$items=db()->query("SELECT * FROM investment_opportunities WHERE is_visible=1 ORDER BY display_order")->fetchAll();
$publicModelSettings=[];
try{
  $modelSettingsReady=(bool)db()->query("SHOW TABLES LIKE 'investment_model_settings'")->fetchColumn();
  $constructionTimelineReady=(bool)db()->query("SHOW TABLES LIKE 'investment_construction_draw_settings'")->fetchColumn();
  $monthlyOccupancyReady=(bool)db()->query("SHOW TABLES LIKE 'investment_monthly_occupancy_settings'")->fetchColumn();
  $investorStructureReady=(bool)db()->query("SHOW TABLES LIKE 'investment_investor_structures'")->fetchColumn();
  foreach($items as $item){
    $projectId=(int)$item['id'];$settings=[];
    if($modelSettingsReady){
      $stmt=db()->prepare('SELECT * FROM investment_model_settings WHERE investment_opportunity_id=?');$stmt->execute([$projectId]);$settings=$stmt->fetch()?:[];
      unset($settings['id'],$settings['investment_opportunity_id'],$settings['updated_by_admin_id'],$settings['created_at'],$settings['updated_at']);
    }
    if($constructionTimelineReady){
      $stmt=db()->prepare('SELECT month_number,equity_spent_percent,loan_drawn_percent FROM investment_construction_draw_settings WHERE investment_opportunity_id=? ORDER BY month_number');$stmt->execute([$projectId]);
      foreach($stmt->fetchAll() as $row){$month=(int)$row['month_number'];$settings['construction_equity_spent_month_'.$month]=$row['equity_spent_percent'];$settings['construction_loan_drawn_month_'.$month]=$row['loan_drawn_percent'];}
    }
    if($monthlyOccupancyReady){
      $stmt=db()->prepare('SELECT year_number,month_number,occupancy_percent FROM investment_monthly_occupancy_settings WHERE investment_opportunity_id=? ORDER BY year_number,month_number');$stmt->execute([$projectId]);
      foreach($stmt->fetchAll() as $row)$settings['occupancy_year_'.(int)$row['year_number'].'_month_'.(int)$row['month_number']]=$row['occupancy_percent'];
    }
    if($investorStructureReady){
      $stmt=db()->prepare('SELECT lp_total_units FROM investment_investor_structures WHERE investment_opportunity_id=?');$stmt->execute([$projectId]);$lpTotalUnits=$stmt->fetchColumn();
      if($lpTotalUnits!==false)$settings['lp_total_units']=$lpTotalUnits;
    }
    $publicModelSettings[$projectId]=$settings;
  }
}catch(Throwable $exception){error_log('Public investment model settings could not be loaded: '.$exception->getMessage());}
require __DIR__.'/includes/header.php';
?>
<main><?php render_public_page_header('invest','Partner With Us','We believe successful developments are built on strong partnerships. Discover opportunities to invest alongside our experienced team.','projects-blueprint'); ?>
<section class="section soft"><div class="container invest-grid">
<?php if (!$items): ?>
<div class="znp-ui-empty-state">
  <h3>No Active Investment Opportunities</h3>
  <p>Please check back in the future as we are always seeking our next deal.</p>
</div>
<?php endif; ?>
<?php foreach($items as $i): ?><?php
  $investmentImage = ltrim(trim((string)($i['hero_image'] ?? '')), '/');
  $investmentImageUrl = '';
  if ($investmentImage !== '' && is_file(__DIR__ . '/' . $investmentImage)) {
      $investmentImageUrl = $investmentImage . '?v=' . (string) filemtime(__DIR__ . '/' . $investmentImage);
  }
?><article class="invest-card">
<?php if ($investmentImageUrl !== ''): ?>
<div class="invest-visual has-invest-photo"><img class="znp-cover-image" src="<?=e($investmentImageUrl)?>" alt="<?=e((string)$i['project_name'])?>" loading="lazy"></div>
<?php endif; ?>
<div class="invest-copy"><span class="investment-status <?=e($i['status']==='raising_capital'?'raising':'funded')?>"><?=e(ucwords(str_replace('_',' ',$i['status'])))?></span>
<h2><?=e($i['project_name'])?></h2><div class="invest-facts invest-facts-clean">
<div class="invest-fact"><strong><?=e($i['asset_type'])?></strong><span>Asset Type</span></div>
<div class="invest-fact"><strong><?=e($i['city'].', '.$i['state'])?></strong><span>Location</span></div>
<div class="invest-fact"><strong><?=e($i['investment_structure'])?></strong><span>Investment Structure</span></div></div>
<div class="development-size-box"><span><?=e($i['unit_label'])?></span><strong><?=e($i['unit_count_text'])?></strong></div>
<div class="public-investment-actions"><button class="secondary public-investment-calculator-button" type="button" data-open-public-model="<?=e((string)$i['id'])?>" data-project-name="<?=e((string)$i['project_name'])?>"><i class="fa-solid fa-calculator" aria-hidden="true"></i><span>Investment Calculator</span></button><?php if($i['accepting_inquiries']): ?><a class="primary" href="/contact?subject=investment_opportunity&project=<?=e((string)$i['id'])?>">Start a Conversation <i class="fa-solid fa-pen" aria-hidden="true"></i></a><?php else:?><span class="funded-button">Fully Funded</span><?php endif;?></div>
</div></article><?php endforeach;?></div></section></main>
<?php foreach($publicModelSettings as $projectId=>$settings):?><div data-waterfall-projection-engine data-project-id="<?=(int)$projectId?>" data-model-settings="<?=e(json_encode($settings,JSON_UNESCAPED_SLASHES))?>" hidden></div><?php endforeach;?>
<dialog class="public-investment-model-dialog" data-public-investment-model>
 <header><div><span>LP Return Projection</span><h2 data-public-model-title>Project Model</h2><p>Select a potential investment to view the projected annual activity.</p></div><button type="button" aria-label="Close projection" data-close-public-model>×</button></header>
 <div class="public-investment-model-body">
  <label class="public-investment-range"><span>Investment Range</span><select data-public-investment-amount><option value="25000">$25,000</option><option value="50000">$50,000</option><option value="75000">$75,000</option><option value="100000">$100,000</option><option value="150000">$150,000</option><option value="200000">$200,000</option></select></label>
  <div data-public-model-results></div>
 </div>
 <section class="public-investment-disclaimer" aria-label="Investment calculator disclaimer">
  <h3>Important Disclaimer</h3>
  <p>The information, projections, financial models, and estimated returns generated by this Investment Calculator are provided for illustrative purposes only. All assumptions, including but not limited to construction costs, financing terms, rental income, operating expenses, occupancy, appreciation, capitalization rates, and exit values, are estimates and are subject to change.</p>
  <p>Actual investment results may differ materially from the projections presented. No representation or warranty is made regarding the accuracy, completeness, or future performance of any projections or assumptions.</p>
  <p>Nothing contained in this calculator constitutes an offer to sell or a solicitation to purchase securities, investment, legal, tax, or accounting advice. Any investment in real estate involves risk, including the possible loss of principal.</p>
  <p>Prospective investors should conduct their own independent due diligence and consult their own legal, tax, accounting, and financial advisors before making any investment decision. Any investment opportunity will be governed solely by the applicable offering documents, operating agreement, subscription documents, and other definitive legal agreements, which shall control in the event of any inconsistency with the information presented by this calculator.</p>
  <p>Past performance is not indicative of future results, and no rate of return, cash distribution, appreciation, or investment outcome is guaranteed.</p>
 </section>
 <footer><button class="secondary" type="button" data-close-public-model>Close</button></footer>
</dialog>
<script src="<?=e(app_url('/assets/admin-waterfall-calculator.js?v=20260723-604'))?>" defer></script>
<script src="<?=e(app_url('/assets/public-investment-model.js?v=20260723-598'))?>" defer></script>
<?php include __DIR__.'/includes/footer.php'; ?>
