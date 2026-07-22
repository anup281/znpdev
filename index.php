<?php
$pageTitle='ZNP Development | Home'; $activePage='home';
require __DIR__.'/includes/db.php'; require __DIR__.'/includes/functions.php';
$active=(float)db()->query("SELECT COALESCE(SUM(project_value),0) FROM projects WHERE status='Under Development' AND is_visible=1")->fetchColumn();
$completed=(float)db()->query("SELECT COALESCE(SUM(project_value),0) FROM projects WHERE is_visible=1")->fetchColumn();
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

$pagePreloadImage = $slides[0]['primary_image'] ?? null;
require __DIR__.'/includes/header.php';
?>
<main>
<section class="hero hero-blue hero-architectural">
  <div class="container hero-grid">
    <div class="home-slider" aria-label="Featured projects" data-slide-interval="<?= (int)setting('homepage_slider_seconds', '4') * 1000 ?>"><?php foreach($slides as $i=>$s): ?><a class="home-slide <?=$i===0?'active':''?>" href="<?=e($s['project_url'])?>" style="background-image:url('<?=e($s['primary_image'])?>')" role="img" aria-label="<?=e($s['project_name'])?>" <?=$i===0?'':'data-lazy-bg="'.e($s['primary_image']).'"'?>></a><?php endforeach;?></div>
    <div class="hero-copy">
      <div class="hero-copy-inner">
        <h1><span>Texas-Based.</span><br>Vertically Integrated.<br>Built to Perform.</h1>
        <p class="lead"><?=e(setting('hero_subtitle'))?></p>
        <div class="actions"><a class="primary hero-white-btn" href="/projects">Explore Our Projects →</a><a class="secondary hero-outline-btn" href="/invest">Investment Opportunities</a></div>
      </div>
    </div>
  </div>
</section>
<section class="container metrics executive-kpi-bar" aria-label="ZNP Development key performance indicators">
<a class="metric" href="/team"><strong><?=e(setting('years_experience','15'))?>+</strong><span>Years of Experience</span></a>
<a class="metric" href="/projects#under-development"><strong><?=money_compact($active)?></strong><span>Active Developments</span></a>
<a class="metric" href="/projects#commercial"><strong><?=money_compact($completed)?></strong><span>Asset Value</span></a>
<a class="metric" href="/invest"><strong><?=db()->query("SELECT COUNT(*) FROM investment_opportunities WHERE status='raising_capital' AND is_visible=1")->fetchColumn()?></strong><span>Current Investment Opportunities</span></a>
</section>

<section class="what-we-do">
  <div class="container">
    <div class="what-we-do-copy">
      <h2>What We Do</h2>
      <p>From land acquisition to long-term ownership, ZNP Development manages every stage of the development lifecycle through a vertically integrated platform built on experience, accountability, and disciplined execution.</p>
    </div>

    <div class="process-timeline">
      <article class="process-step">
        <div class="process-number">01</div>
        <h3>Land Acquisition</h3>
        <p>Identifying high-quality opportunities in growing Texas markets.</p>
      </article>
      <article class="process-step">
        <div class="process-number">02</div>
        <h3>Entitlements &amp; Planning</h3>
        <p>Managing due diligence, design coordination, and municipal approvals.</p>
      </article>
      <article class="process-step">
        <div class="process-number">03</div>
        <h3>Capital Formation</h3>
        <p>Structuring disciplined investment partnerships aligned for long-term success.</p>
      </article>
      <article class="process-step">
        <div class="process-number">04</div>
        <h3>Development &amp; Construction</h3>
        <p>Delivering thoughtfully planned residential and commercial developments.</p>
      </article>
      <article class="process-step">
        <div class="process-number">05</div>
        <h3>Asset Management</h3>
        <p>Overseeing operations, performance, and value creation after completion.</p>
      </article>
      <article class="process-step">
        <div class="process-number">06</div>
        <h3>Long-Term Ownership</h3>
        <p>Building durable assets designed to perform for years to come.</p>
      </article>
    </div>
  </div>
</section>

<section class="container band">
  <div>
    <h2>Let’s build something enduring.</h2>
    <p>Discuss acquisitions, capital partnerships, or development opportunities.</p>
  </div>
  <a class="secondary" href="/contact">Start a Conversation&nbsp;<i class="fa-solid fa-pen" aria-hidden="true"></i></a>
</section>

<?php include __DIR__.'/includes/footer.php'; ?>
