<?php
$pageTitle = 'Projects | ZNP Development';
$activePage = 'projects';

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

/**
 * Real project photographs included with Website v1.1.
 *
 * The database remains the preferred source. This map is a reliable fallback
 * when an older database contains a blank, outdated, or missing image path.
 */
$projectImageMap = [
    'residences at toler phase 2|longview' =>
        'assets/images/projects/under-development/residences-at-toler-phase-2.jpg',
    'the enclave residential neighborhood|longview' =>
        'assets/images/projects/under-development/the-enclave-residential-neighborhood.jpg',

    'towneplace suites by marriott|longview' =>
        'assets/images/projects/commercial/towneplace-suites-by-marriott-longview.jpg',
    'village lofts|longview' =>
        'assets/images/projects/commercial/village-lofts-longview.jpg',
    'judson lofts|longview' =>
        'assets/images/projects/commercial/judson-lofts-longview.jpg',
    'residences at toler phase 1|longview' =>
        'assets/images/projects/commercial/residences-at-toler-phase-1-longview.jpg',
    'holiday inn express|tyler' =>
        'assets/images/projects/commercial/holiday-inn-express-tyler.jpg',
    'staybridge suites|tyler' =>
        'assets/images/projects/commercial/staybridge-suites-tyler.jpg',
    'staybridge suites|longview' =>
        'assets/images/projects/commercial/staybridge-suites-longview.jpg',
    'candlewood suites|longview' =>
        'assets/images/projects/commercial/candlewood-suites-longview.jpg',
    'wingate by wyndham to holiday inn express ihg|longview' =>
        'assets/images/projects/commercial/wingate-by-wyndham-to-holiday-inn-express-ihg-longview.jpg',
    'sleep inn to wingate by wyndham|longview' =>
        'assets/images/projects/commercial/sleep-inn-to-wingate-by-wyndham-longview.jpg',
    'towneplace suites by marriott|waco' =>
        'assets/images/projects/commercial/towneplace-suites-by-marriott-waco.jpg',

    '708 cove pl|longview' =>
        'assets/images/projects/residential/708-cove-pl.jpg',
    '711 cove pl|longview' =>
        'assets/images/projects/residential/711-cove-pl.jpg',
    '727 cove pl|longview' =>
        'assets/images/projects/residential/727-cove-pl.jpg',
    '725 cove pl|longview' =>
        'assets/images/projects/residential/725-cove-pl.jpg',
    '507 powers ct|longview' =>
        'assets/images/projects/residential/507-powers-ct.jpg',
    '2103 bandera tr|longview' =>
        'assets/images/projects/residential/2103-bandera-tr.jpg',
    '2207 bandera tr|longview' =>
        'assets/images/projects/residential/2207-bandera-tr.jpg',
    '1012 windy ridge dr|longview' =>
        'assets/images/projects/residential/1012-windy-ridge-dr.jpg',
    '703 cove pl|longview' =>
        'assets/images/projects/residential/703-cove-pl.jpg',
    '127 brookway ln|longview' =>
        'assets/images/projects/residential/127-brookway-ln.jpg',
    '4023 hidden hills cir|longview' =>
        'assets/images/projects/residential/4023-hidden-hills-cir.jpg',
    '120 elk dr|longview' =>
        'assets/images/projects/residential/120-elk-dr.jpg',
    '4006 water view dr|longview' =>
        'assets/images/projects/residential/4006-water-view-dr.jpg',
    '3634 hamilton heights|frisco' =>
        'assets/images/projects/residential/3634-hamilton-heights.jpg',
];

/**
 * Normalize project names so en dashes, em dashes, punctuation, and accidental
 * trailing spaces do not prevent the correct image from being found.
 */
function project_image_key(string $name, string $city): string
{
    $value = mb_strtolower(trim($name) . '|' . trim($city), 'UTF-8');
    $value = str_replace(['–', '—', '−', '&'], [' ', ' ', ' ', ' and '], $value);
    $value = preg_replace('/[^a-z0-9|]+/u', ' ', $value) ?? $value;
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    $value = str_replace(' |', '|', $value);
    $value = str_replace('| ', '|', $value);

    return trim($value);
}

function project_image_exists(string $relativePath): bool
{
    if ($relativePath === '') {
        return false;
    }

    // Prevent external URLs and directory traversal from being treated as local files.
    if (preg_match('#^(?:https?:)?//#i', $relativePath) || str_contains($relativePath, '..')) {
        return false;
    }

    return is_file(__DIR__ . '/' . ltrim($relativePath, '/'));
}

function resolve_project_image(
    array $project,
    array $projectImageMap
): ?string {
    $databasePath = trim((string)($project['primary_image'] ?? ''));

    if (project_image_exists($databasePath)) {
        return $databasePath;
    }

    $key = project_image_key(
        (string)($project['project_name'] ?? ''),
        (string)($project['city'] ?? '')
    );

    $fallback = $projectImageMap[$key] ?? null;

    if ($fallback && project_image_exists($fallback)) {
        return $fallback;
    }

    return null;
}

$groups = [];

foreach (['under_development', 'commercial', 'residential'] as $category) {
    if ($category === 'residential') {
        // Residential portfolio: highest-value homes first.
        // Missing or zero values remain at the end, then sort by newest year.
        $orderBy = "CASE WHEN project_value IS NULL OR project_value <= 0 THEN 1 ELSE 0 END ASC,
                    project_value DESC,
                    CASE WHEN year_completed_or_expected IS NULL OR TRIM(CAST(year_completed_or_expected AS CHAR)) = '' THEN 1 ELSE 0 END ASC,
                    CAST(year_completed_or_expected AS UNSIGNED) DESC,
                    display_order ASC,
                    project_name ASC";
    } else {
        // Commercial and under-development portfolios: current projects first,
        // newest year first, with sold projects always at the end.
        $orderBy = "CASE WHEN LOWER(TRIM(COALESCE(portfolio_status,''))) = 'sold' THEN 1 ELSE 0 END ASC,
                    CASE WHEN year_completed_or_expected IS NULL OR TRIM(CAST(year_completed_or_expected AS CHAR)) = '' THEN 1 ELSE 0 END ASC,
                    CAST(year_completed_or_expected AS UNSIGNED) DESC,
                    display_order ASC,
                    project_name ASC";
    }
    $statement = db()->prepare(
        "SELECT *
         FROM projects
         WHERE portfolio_category = ?
           AND is_visible = 1
         ORDER BY {$orderBy}"
    );
    $statement->execute([$category]);
    $groups[$category] = $statement->fetchAll();
}

require __DIR__ . '/includes/header.php';
?>
<main>
  <?php render_public_page_header('projects','What We\'ve Built','A look at the projects that define ZNP Development\'s commitment to quality, disciplined execution, and long-term ownership.','projects-blueprint'); ?>

  <?php foreach ([
      'under_development' => 'Under Development',
      'commercial' => 'Commercial Portfolio',
      'residential' => 'Residential Portfolio',
  ] as $category => $label): ?>

    <section
      class="portfolio-section <?= $category === 'commercial' ? 'portfolio-commercial' : '' ?>"
      id="<?= e($category === 'under_development' ? 'under-development' : $category) ?>"
    >
      <div class="container">
        <div class="portfolio-section-heading">
          <h2><?= e($label) ?></h2>
          <?php if ($category === 'residential'): ?><p>A collection of custom homes built for clients alongside thoughtfully designed spec homes developed for the open market.</p><?php endif; ?>
        </div>

        <div class="<?=
            $category === 'under_development'
                ? 'under-development-grid'
                : ($category === 'residential'
                    ? 'residential-gallery-grid'
                    : 'commercial-project-grid')
        ?>">
          <?php foreach ($groups[$category] as $project): ?>
            <?php $imagePath = resolve_project_image($project, $projectImageMap); ?>

            <article id="project-<?= (int)$project['id'] ?>" class="<?= $category === 'residential'
                ? 'residential-gallery-card'
                : 'project-info-card' ?>">

              <div
                class="project-card-image project-image-<?= e($category) ?> <?= $imagePath ? 'has-project-photo' : 'project-image-placeholder' ?>"
              ><?php if ($imagePath): ?><img class="znp-cover-image" src="<?= e($imagePath) ?>" alt="<?= e($project['project_name']) ?>" loading="lazy"><?php endif; ?></div>

              <div class="<?= $category === 'residential'
                  ? 'residential-gallery-copy'
                  : 'project-info-copy' ?>">

                <h3><?= e($project['project_name']) ?></h3>
                <p><?= e($project['city'] . ', ' . $project['state']) ?></p>

                <?php if ($category !== 'residential'): ?>
                  <div class="project-chip-row">
                    <?php
                    $chips = [
                        'Asset Type' => $project['asset_type'],
                        'Project Type' => $project['project_type'],
                        $category === 'under_development'
                            ? 'Expected Completion'
                            : 'Completed Year'
                            => $project['year_completed_or_expected'],
                        'Units / Keys' => $project['units_or_keys'],
                        'Status' => $project['portfolio_status'] ?? null,
                    ];
                    ?>

                    <?php foreach ($chips as $chipLabel => $chipValue): ?>
                      <?php if ($chipValue !== null && $chipValue !== ''): ?>
                        <span class="project-chip">
                          <small><?= e($chipLabel) ?></small>
                          <?= e((string)$chipValue) ?>
                        </span>
                      <?php endif; ?>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
  <?php endforeach; ?>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
