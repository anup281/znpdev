<?php
$pageTitle='ZNP Development | Home'; $activePage='home';
require __DIR__.'/includes/db.php'; require __DIR__.'/includes/functions.php';
$active=(float)db()->query("SELECT COALESCE(SUM(project_value),0) FROM projects WHERE status='Under Development' AND is_visible=1")->fetchColumn();
$completed=(float)db()->query("SELECT COALESCE(SUM(project_value),0) FROM projects WHERE is_visible=1")->fetchColumn();
// ZNP HOMEPAGE REDESIGN START: homepage-only dynamic content queries.
$currentOpportunityCount=(int)db()->query("SELECT COUNT(*) FROM investment_opportunities WHERE status='raising_capital' AND is_visible=1")->fetchColumn();
$slideStatement = db()->query(
    "SELECT id, project_name, primary_image
     FROM projects
     WHERE is_homepage_featured = 1
       AND is_visible = 1
       AND primary_image IS NOT NULL
       AND TRIM(primary_image) <> ''
     ORDER BY display_order ASC, project_name ASC"
);
$slides = [];
foreach ($slideStatement->fetchAll() as $record) {
    $image = ltrim(trim((string)($record['primary_image'] ?? '')), '/');
    if ($image === '' || !is_file(__DIR__ . '/' . $image)) {
        continue;
    }
    $slides[] = [
        'project_name' => (string)$record['project_name'],
        'primary_image' => $image,
        'project_url' => '/projects#project-' . (int)$record['id'],
    ];
}

// Graceful fallback: if nothing is featured, use the first visible projects
// with valid images. This keeps the homepage usable without hard-coded IDs.
if (!$slides) {
    $fallbackStatement = db()->query(
        "SELECT id, project_name, primary_image
         FROM projects
         WHERE is_visible = 1
           AND primary_image IS NOT NULL
           AND TRIM(primary_image) <> ''
         ORDER BY display_order ASC, project_name ASC
         LIMIT 3"
    );
    foreach ($fallbackStatement->fetchAll() as $record) {
        $image = ltrim(trim((string)($record['primary_image'] ?? '')), '/');
        if ($image === '' || !is_file(__DIR__ . '/' . $image)) {
            continue;
        }
        $slides[] = [
            'project_name' => (string)$record['project_name'],
            'primary_image' => $image,
            'project_url' => '/projects#project-' . (int)$record['id'],
        ];
    }
}

$featuredProjects = db()->query(
    "SELECT id, project_name, city, state, status, asset_type, primary_image
     FROM projects
     WHERE is_homepage_featured = 1
       AND is_visible = 1
     ORDER BY display_order ASC, project_name ASC
     LIMIT 3"
)->fetchAll();
if (!$featuredProjects) {
    $featuredProjects = db()->query(
        "SELECT id, project_name, city, state, status, asset_type, primary_image
         FROM projects
         WHERE is_visible = 1
         ORDER BY display_order ASC, project_name ASC
         LIMIT 3"
    )->fetchAll();
}
$investmentOpportunities = db()->query(
    "SELECT id, project_name, asset_type, city, state, status, investment_structure, unit_label, unit_count_text, hero_image, accepting_inquiries
     FROM investment_opportunities
     WHERE is_visible = 1
     ORDER BY display_order ASC, project_name ASC
     LIMIT 3"
)->fetchAll();
// ZNP HOMEPAGE REDESIGN END

$pagePreloadImage = $slides[0]['primary_image'] ?? null;
require __DIR__.'/includes/header.php';
?>
<!-- ZNP HOMEPAGE REDESIGN START -->
<link rel="stylesheet" href="<?=e(app_url('/assets/home-redesign.css'))?>?v=20260730-4">
<main class="znp-home-redesign">
<section class="zhr-hero">
  <div class="home-slider zhr-hero-media" aria-label="Featured projects" data-slide-interval="<?= (int)setting('homepage_slider_seconds', '4') * 1000 ?>">
    <?php foreach($slides as $i=>$s): ?><a class="home-slide <?=$i===0?'active':''?>" href="<?=e(app_url($s['project_url']))?>" aria-label="<?=e($s['project_name'])?>" data-project-name="<?=e($s['project_name'])?>"><img class="znp-cover-image" src="<?=e(app_url($s['primary_image']))?>" alt="<?=e($s['project_name'])?>" <?=$i===0?'fetchpriority="high"':'loading="lazy"'?>></a><?php endforeach;?>
  </div>
  <div class="zhr-hero-shade"></div>
  <div class="container zhr-hero-content">
    <p class="zhr-eyebrow">Texas Real Estate Development</p>
    <h1>Texas-Based.<br>Vertically Integrated.<br><em>Built to Perform.</em></h1>
    <p class="lead"><?=e(setting('hero_subtitle'))?></p>
    <div class="actions"><a class="zhr-button zhr-button-gold" href="<?=e(app_url('/projects'))?>">Explore Our Projects <span aria-hidden="true">→</span></a><a class="zhr-button zhr-button-ghost" href="<?=e(app_url('/invest'))?>">Investment Opportunities</a></div>
  </div>
  <div class="zhr-hero-caption" aria-hidden="true"><span data-home-caption-number>01</span><i></i><span data-home-caption-name><?=e($slides[0]['project_name'] ?? 'ZNP Development')?></span></div>
</section>

<section class="zhr-metrics" aria-label="ZNP Development key performance indicators">
  <div class="container zhr-metrics-grid <?=$currentOpportunityCount > 0 ? '' : 'zhr-metrics-grid-three'?>">
    <a class="zhr-metric" href="<?=e(app_url('/team'))?>"><strong><?=e(setting('years_experience','15'))?>+</strong><span>Years of Experience</span></a>
    <a class="zhr-metric" href="<?=e(app_url('/projects#under-development'))?>"><strong><?=money_compact($active)?></strong><span>Active Developments</span></a>
    <a class="zhr-metric" href="<?=e(app_url('/projects#commercial'))?>"><strong><?=money_compact($completed)?></strong><span>Asset Value</span></a>
    <?php if ($currentOpportunityCount > 0): ?>
    <a class="zhr-metric" href="<?=e(app_url('/invest'))?>"><strong><?=$currentOpportunityCount?></strong><span>Current Investment Opportunities</span></a>
    <?php endif; ?>
  </div>
</section>

<section class="zhr-process">
  <div class="container">
    <div class="zhr-section-intro">
      <div><p class="zhr-eyebrow">A fully integrated platform</p><h2>What We Do</h2></div>
      <p>From land acquisition to long-term ownership, ZNP Development manages every stage of the development lifecycle through a vertically integrated platform built on experience, accountability, and disciplined execution.</p>
    </div>
    <div class="zhr-process-grid">
      <?php foreach ([
        ['01','Land Acquisition','Identifying high-quality opportunities in growing Texas markets.'],
        ['02','Entitlements & Planning','Managing due diligence, design coordination, and municipal approvals.'],
        ['03','Capital Formation','Structuring disciplined investment partnerships aligned for long-term success.'],
        ['04','Development & Construction','Delivering thoughtfully planned residential and commercial developments.'],
        ['05','Asset Management','Overseeing operations, performance, and value creation after completion.'],
        ['06','Long-Term Ownership','Building durable assets designed to perform for years to come.'],
      ] as [$number,$title,$description]): ?>
      <article class="zhr-process-step"><span><?=$number?></span><h3><?=e($title)?></h3><p><?=e($description)?></p></article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="zhr-featured">
  <div class="container">
    <div class="zhr-section-heading"><div><p class="zhr-eyebrow">Selected portfolio</p><h2>Featured Projects</h2></div><a href="<?=e(app_url('/projects'))?>">View all projects <span aria-hidden="true">→</span></a></div>
    <div class="zhr-project-grid">
      <?php foreach ($featuredProjects as $project): $projectImage=ltrim(trim((string)($project['primary_image']??'')),'/'); ?>
      <a class="zhr-project-card" id="home-project-<?= (int)$project['id'] ?>" href="<?=e(app_url('/projects#project-'.(int)$project['id']))?>">
        <div class="zhr-card-image">
          <?php if ($projectImage!=='' && is_file(__DIR__.'/'.$projectImage)): ?><img src="<?=e(app_url($projectImage))?>" alt="<?=e((string)$project['project_name'])?>" loading="lazy"><?php endif; ?>
          <span><?=e((string)($project['status'] ?: 'Project'))?></span>
        </div>
        <div class="zhr-card-copy"><p><?=e(trim((string)$project['city'].', '.(string)$project['state'],', '))?></p><h3><?=e((string)$project['project_name'])?></h3><small><?=e((string)($project['asset_type'] ?: 'ZNP Development'))?></small></div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php if ($investmentOpportunities): ?>
<section class="zhr-investments">
  <div class="container">
    <div class="zhr-section-intro zhr-section-intro-light"><div><p class="zhr-eyebrow">Invest alongside us</p><h2>Current Investment Opportunities</h2></div><p>We believe successful developments are built on strong partnerships. Discover opportunities to invest alongside our experienced team.</p></div>
    <div class="zhr-investment-grid">
      <?php foreach ($investmentOpportunities as $opportunity): $opportunityImage=ltrim(trim((string)($opportunity['hero_image']??'')),'/'); ?>
      <article class="zhr-investment-card">
        <?php if ($opportunityImage!=='' && is_file(__DIR__.'/'.$opportunityImage)): ?><div class="zhr-investment-image"><img src="<?=e(app_url($opportunityImage))?>" alt="<?=e((string)$opportunity['project_name'])?>" loading="lazy"></div><?php endif; ?>
        <div class="zhr-investment-copy">
          <span class="zhr-status"><?=e(ucwords(str_replace('_',' ',(string)$opportunity['status'])))?></span>
          <h3><?=e((string)$opportunity['project_name'])?></h3>
          <p><?=e((string)$opportunity['asset_type'])?> · <?=e(trim((string)$opportunity['city'].', '.(string)$opportunity['state'],', '))?></p>
          <dl><div><dt>Structure</dt><dd><?=e((string)$opportunity['investment_structure'])?></dd></div><div><dt><?=e((string)$opportunity['unit_label'])?></dt><dd><?=e((string)$opportunity['unit_count_text'])?></dd></div></dl>
          <a href="<?=e(app_url('/invest'))?>">View opportunity <span aria-hidden="true">→</span></a>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="zhr-conversation">
  <div class="container zhr-conversation-inner">
    <div><p class="zhr-eyebrow">Build with ZNP</p><h2>Let’s build something enduring.</h2><p>Discuss acquisitions, capital partnerships, or development opportunities.</p></div>
    <a class="zhr-button zhr-button-gold" href="<?=e(app_url('/contact'))?>">Start a Conversation <i class="fa-solid fa-pen" aria-hidden="true"></i></a>
  </div>
</section>
</main>
<script src="<?=e(app_url('/assets/home-redesign.js'))?>?v=20260730-1" defer></script>
<!-- ZNP HOMEPAGE REDESIGN END -->
<?php include __DIR__.'/includes/footer.php'; ?>
