<?php
$pageTitle='Invest | ZNP Development'; $activePage='invest';
require __DIR__.'/includes/db.php'; require __DIR__.'/includes/functions.php';
$items=db()->query("SELECT * FROM investment_opportunities WHERE is_visible=1 ORDER BY display_order")->fetchAll();
require __DIR__.'/includes/header.php';
?>
<main><?php render_public_page_header('invest','Partner With Us','We believe successful developments are built on strong partnerships. Discover opportunities to invest alongside our experienced team.','projects-blueprint'); ?>
<section class="section soft"><div class="container invest-grid">
<?php foreach($items as $i): ?><?php
  $investmentImage = ltrim(trim((string)($i['hero_image'] ?? '')), '/');
  $investmentImageUrl = '';
  if ($investmentImage !== '' && is_file(__DIR__ . '/' . $investmentImage)) {
      $investmentImageUrl = $investmentImage . '?v=' . (string) filemtime(__DIR__ . '/' . $investmentImage);
  }
?><article class="invest-card">
<?php if ($investmentImageUrl !== ''): ?>
<div class="invest-visual has-invest-photo" style="background-image:url('<?=e($investmentImageUrl)?>')" role="img" aria-label="<?=e((string)$i['project_name'])?>"></div>
<?php endif; ?>
<div class="invest-copy"><span class="investment-status <?=e($i['status']==='raising_capital'?'raising':'funded')?>"><?=e(ucwords(str_replace('_',' ',$i['status'])))?></span>
<h2><?=e($i['project_name'])?></h2><div class="invest-facts invest-facts-clean">
<div class="invest-fact"><strong><?=e($i['asset_type'])?></strong><span>Asset Type</span></div>
<div class="invest-fact"><strong><?=e($i['city'].', '.$i['state'])?></strong><span>Location</span></div>
<div class="invest-fact"><strong><?=e($i['investment_structure'])?></strong><span>Investment Structure</span></div></div>
<div class="development-size-box"><span><?=e($i['unit_label'])?></span><strong><?=e($i['unit_count_text'])?></strong></div>
<?php if($i['accepting_inquiries']): ?><a class="primary" href="/contact?subject=investment_opportunity&project=<?=e((string)$i['id'])?>">Start a Conversation <i class="fa-solid fa-pen" aria-hidden="true"></i></a><?php else:?><span class="funded-button">Fully Funded</span><?php endif;?>
</div></article><?php endforeach;?></div></section></main>
<?php include __DIR__.'/includes/footer.php'; ?>
