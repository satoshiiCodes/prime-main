<?php
/**
 * Prime City - Dashboard page.
 *
 * Layout only: data comes from actions/dashboard-actions.php, markup from
 * includes/*, styles from assets/css, behaviour from assets/js.
 */

require_once __DIR__ . '/actions/dashboard-actions.php';

dashboard_start_session();
dashboard_handle_logout();

$dashboard = dashboard_context($_GET['branch'] ?? 'all');

// Shared layout includes read $user and $layout_active.
$user = $dashboard['user'];
$layout_active = 'dashboard';

$branch = $dashboard['branch'];
$collectorData = $dashboard['collectors'];
$areaData = $dashboard['areas'];
$summary = $dashboard['summary'];
$chartPayload = dashboard_chart_payload($dashboard);

$branchLabel = $branch['label'];
$topCollector = $summary['top_collector'];

$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <link rel="icon" type="image/png" href="assets/images/prime-logo-only.png" />
        <meta charset="utf-8" />
        <meta http-equiv="X-UA-Compatible" content="IE=edge" />
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
        <meta name="description" content="Prime City collections dashboard" />
        <meta name="author" content="Prime City" />
        <title>Dashboard - Prime City Admin</title>
        <link href="css/styles.css" rel="stylesheet" />
        <link href="assets/css/theme-logo.css" rel="stylesheet" />
        <link href="assets/css/dash.css" rel="stylesheet" />
        <link href="assets/css/dashboard-style.css" rel="stylesheet" />
        <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    </head>
    <body class="sb-nav-fixed">
        <?php include __DIR__ . '/includes/topbar.php'; ?>

        <div id="layoutSidenav">
            <?php include __DIR__ . '/includes/sidebar.php'; ?>

            <div id="layoutSidenav_content">
                <main>
                    <div class="dash">
                        <header class="dash-head">
                            <div>
                                <p class="dash-eyebrow">
                                    <i class="fas fa-chart-simple" aria-hidden="true"></i>
                                    Prime City &middot; Overview
                                </p>
                                <h1 class="dash-title">Dashboard</h1>
                                <p class="dash-sub">
                                    Collection performance for <strong id="branchSummaryLabel"><?php echo htmlspecialchars($branchLabel, ENT_QUOTES, 'UTF-8'); ?></strong>
                                </p>
                            </div>

                            <?php if ($branch['show_filter']): ?>
                            <form class="dash-filter" method="get" action="dashboard.php">
                                <label for="branchFilter">Branch</label>
                                <select class="form-select" id="branchFilter" name="branch">
                                    <?php if ($branch['show_all_option']): ?>
                                    <option value="all" <?php echo $branch['selected'] === 'all' ? 'selected' : ''; ?>>All Branches</option>
                                    <?php endif; ?>

                                    <?php foreach ($branch['available'] as $branchOption): ?>
                                    <option value="<?php echo htmlspecialchars($branchOption, ENT_QUOTES, 'UTF-8'); ?>"
                                        <?php echo $branch['selected'] === $branchOption ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($branchOption, ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <noscript>
                                    <button type="submit" class="btn btn-primary">Apply</button>
                                </noscript>
                            </form>
                            <?php else: ?>
                            <!-- Hidden filter for single-branch accounts -->
                            <div class="hidden-filter">
                                <input type="hidden" id="branchFilter" value="<?php echo htmlspecialchars($branch['selected'], ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <?php endif; ?>
                        </header>

                        <?php if (!empty($dashboard['error'])): ?>
                        <div class="alert alert-warning" role="alert">
                            Live collection data could not be loaded right now. Please try again later.
                        </div>
                        <?php endif; ?>

                        <section class="dash-stats" aria-label="Collection summary">
                            <article class="stat">
                                <div class="stat__body">
                                    <p class="stat__label">Total collection</p>
                                    <p class="stat__value" id="statTotal">&#8369;<?php echo number_format((float) $summary['total_collection'], 2); ?></p>
                                    <p class="stat__meta" id="statTotalMeta"><?php echo htmlspecialchars($branchLabel, ENT_QUOTES, 'UTF-8'); ?></p>
                                </div>
                                <span class="stat__icon"><i class="fas fa-coins" aria-hidden="true"></i></span>
                            </article>

                            <article class="stat stat--green">
                                <div class="stat__body">
                                    <p class="stat__label">Top collector</p>
                                    <p class="stat__value stat__value--text" id="statTop">
                                        <?php echo $topCollector ? htmlspecialchars((string) $topCollector['name'], ENT_QUOTES, 'UTF-8') : 'No collections yet'; ?>
                                    </p>
                                    <p class="stat__meta" id="statTopAmount">
                                        <?php echo $topCollector ? '&#8369;' . number_format((float) $topCollector['total'], 2) : '&nbsp;'; ?>
                                    </p>
                                </div>
                                <span class="stat__icon"><i class="fas fa-trophy" aria-hidden="true"></i></span>
                            </article>

                            <article class="stat stat--cyan">
                                <div class="stat__body">
                                    <p class="stat__label">Collectors</p>
                                    <p class="stat__value" id="statCollectors"><?php echo (int) $summary['collector_count']; ?></p>
                                    <p class="stat__meta">reported collections</p>
                                </div>
                                <span class="stat__icon"><i class="fas fa-user-group" aria-hidden="true"></i></span>
                            </article>

                            <article class="stat stat--navy">
                                <div class="stat__body">
                                    <p class="stat__label">Areas</p>
                                    <p class="stat__value" id="statAreas"><?php echo (int) $summary['area_count']; ?></p>
                                    <p class="stat__meta">with collections</p>
                                </div>
                                <span class="stat__icon"><i class="fas fa-map-location-dot" aria-hidden="true"></i></span>
                            </article>
                        </section>

                        <section class="dash-charts" aria-label="Collection charts">
                            <article class="dash-card">
                                <header class="dash-card__head">
                                    <span class="dash-card__icon"><i class="fas fa-chart-column" aria-hidden="true"></i></span>
                                    <div>
                                        <h2 class="dash-card__title" id="collectorChartTitle">Collector Collection Performance - <?php echo htmlspecialchars($branchLabel, ENT_QUOTES, 'UTF-8'); ?></h2>
                                        <p class="dash-card__hint">Total collected per collector</p>
                                    </div>
                                    <span class="dash-card__loading chart-loading" role="status">Updating&hellip;</span>
                                </header>
                                <div class="dash-card__body" id="collectorChartBody">
                                    <?php if (!empty($collectorData)): ?>
                                    <div class="chart-container">
                                        <canvas id="collectorChart"></canvas>
                                    </div>
                                    <?php else: ?>
                                    <div class="alert alert-info" role="alert">
                                        No collection data available for <?php echo htmlspecialchars($branchLabel, ENT_QUOTES, 'UTF-8'); ?>.
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </article>

                            <article class="dash-card">
                                <header class="dash-card__head">
                                    <span class="dash-card__icon"><i class="fas fa-chart-pie" aria-hidden="true"></i></span>
                                    <div>
                                        <h2 class="dash-card__title" id="areaChartTitle">Area Collection Performance - <?php echo htmlspecialchars($branchLabel, ENT_QUOTES, 'UTF-8'); ?></h2>
                                        <p class="dash-card__hint">Share of collections by area</p>
                                    </div>
                                    <span class="dash-card__loading chart-loading" role="status">Updating&hellip;</span>
                                </header>
                                <div class="dash-card__body" id="areaChartBody">
                                    <?php if (!empty($areaData)): ?>
                                    <div class="chart-container">
                                        <canvas id="areaChart"></canvas>
                                    </div>
                                    <?php else: ?>
                                    <div class="alert alert-info" role="alert">
                                        No area data available for <?php echo htmlspecialchars($branchLabel, ENT_QUOTES, 'UTF-8'); ?>.
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </article>
                        </section>
                    </div>
                </main>

                <?php include __DIR__ . '/includes/footer.php'; ?>
            </div>
        </div>

        <script type="application/json" id="dashboard-data"><?php echo json_encode($chartPayload, $jsonFlags); ?></script>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <script src="js/scripts.js"></script>
        <script src="assets/js/dashboard.js"></script>
    </body>
</html>
