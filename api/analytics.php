<?php
declare(strict_types=1);

define('LF_APP', true);
require __DIR__ . '/bootstrap.php';

function analyticsRange(string $period, DateTimeImmutable $now): array
{
    $start = match ($period) {
        'daily' => $now->setTime(0, 0),
        'weekly' => $now->setTime(0, 0)->modify('-6 days'),
        'monthly' => $now->modify('first day of this month')->setTime(0, 0),
        'semester' => $now->modify('first day of this month')->setTime(0, 0)->modify('-5 months'),
        'yearly' => $now->setDate((int) $now->format('Y'), 1, 1)->setTime(0, 0),
    };
    $labels = [
        'daily' => 'Today',
        'weekly' => 'Last 7 days',
        'monthly' => 'This month',
        'semester' => 'Last 6 months',
        'yearly' => 'This year',
    ];
    return [
        'start' => $start,
        'end' => $now->modify('+1 second'),
        'label' => $labels[$period],
    ];
}

function analyticsRecoveryTotals(DateTimeImmutable $start, DateTimeImmutable $end): array
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) AS lost,
                COUNT(DISTINCT CASE WHEN completed.lost_report_id IS NOT NULL THEN r.id END) AS recovered
         FROM reports r
         LEFT JOIN (
             SELECT DISTINCT lost_report_id FROM matches
             WHERE assignment_status = 'completed' AND status <> 'dismissed'
         ) completed ON completed.lost_report_id = r.id
         WHERE r.report_type = 'lost' AND r.created_at >= ? AND r.created_at < ?"
    );
    $stmt->execute([analyticsSqlDate($start), analyticsSqlDate($end)]);
    $row = $stmt->fetch();
    return ['lost' => (int) $row['lost'], 'recovered' => (int) $row['recovered']];
}

function analyticsRate(int $recovered, int $lost): ?float
{
    return $lost > 0 ? round($recovered / $lost * 100, 1) : null;
}

function analyticsSqlDate(DateTimeImmutable $date): string
{
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function analyticsInsight(array $data, string $rangeLabel): array
{
    $insights = [];
    if ($data['summary']['lost'] > 0) {
        if ($data['locationsByLost']) {
            $topLocation = $data['locationsByLost'][0];
            $insights[] = "{$topLocation['location']} has the most lost-item reports in {$rangeLabel} ({$topLocation['lost']}).";
        }
        if ($data['categories']) {
            $topCategory = $data['categories'][0];
            $insights[] = "{$topCategory['category']} is the most frequently lost category in {$rangeLabel} ({$topCategory['lost']} reports, {$topCategory['share']}% of lost reports).";
        }
    } else {
        $insights[] = "There are no lost-item reports in {$rangeLabel}, so a recovery rate cannot be calculated.";
    }

    $trend = $data['monthlyTrend'];
    if (count($trend) >= 2) {
        $latest = $trend[count($trend) - 1];
        $previous = $trend[count($trend) - 2];
        if ($latest['lost'] > 0 && $previous['lost'] > 0) {
            $change = round($latest['rate'] - $previous['rate'], 1);
            $direction = $change > 0 ? 'improved' : ($change < 0 ? 'declined' : 'held steady');
            $amount = number_format(abs($change), 1);
            $insights[] = "The recovery rate {$direction} by {$amount} percentage points, from {$previous['rate']}% in {$previous['month']} to {$latest['rate']}% in {$latest['month']}.";
        }
    }
    if ($data['locationsByUnclaimed']) {
        $place = $data['locationsByUnclaimed'][0];
        $insights[] = "{$place['location']} has the most currently unclaimed found items in the selected period ({$place['unclaimed']}).";
    }
    $withLost = array_values(array_filter($data['categories'], static fn($category) => $category['lost'] > 0));
    if ($withLost) {
        usort($withLost, static fn($a, $b) => $a['recoveryRate'] <=> $b['recoveryRate']);
        $lowest = $withLost[0];
        $insights[] = "{$lowest['category']} has the lowest verified recovery rate among the displayed categories ({$lowest['recoveryRate']}%, {$lowest['recovered']} of {$lowest['lost']} lost reports).";
    }
    return $insights;
}

try {
    requireMethod(['GET']);
    requireAdmin(false, 'admin');

    $period = (string) ($_GET['period'] ?? 'monthly');
    $periods = ['daily', 'weekly', 'monthly', 'semester', 'yearly'];
    if (!in_array($period, $periods, true)) {
        fail(422, 'Choose a valid analytics date range.');
    }

    $timezone = new DateTimeZone((string) config()['timezone']);
    $now = new DateTimeImmutable('now', $timezone);
    $range = analyticsRange($period, $now);
    $totals = analyticsRecoveryTotals($range['start'], $range['end']);
    $summary = [
        'lost' => $totals['lost'],
        'recovered' => $totals['recovered'],
        'unrecovered' => max(0, $totals['lost'] - $totals['recovered']),
        'recoveryRate' => analyticsRate($totals['recovered'], $totals['lost']),
    ];

    $monthStart = $now->modify('first day of this month')->setTime(0, 0);
    $previousMonthStart = $monthStart->modify('-1 month');
    $previousMonthEnd = $previousMonthStart->modify('+' . (int) $now->format('j') . ' days');
    if ($previousMonthEnd > $monthStart) {
        $previousMonthEnd = $monthStart;
    }
    $currentMonthTotals = analyticsRecoveryTotals($monthStart, $now->modify('+1 second'));
    $previousMonthTotals = analyticsRecoveryTotals($previousMonthStart, $previousMonthEnd);
    $monthlyComparison = [
        'current' => [
            'label' => $monthStart->format('F') . ' to date',
            'lost' => $currentMonthTotals['lost'],
            'recovered' => $currentMonthTotals['recovered'],
            'rate' => analyticsRate($currentMonthTotals['recovered'], $currentMonthTotals['lost']),
        ],
        'previous' => [
            'label' => $previousMonthStart->format('F') . ' same dates',
            'lost' => $previousMonthTotals['lost'],
            'recovered' => $previousMonthTotals['recovered'],
            'rate' => analyticsRate($previousMonthTotals['recovered'], $previousMonthTotals['lost']),
        ],
    ];

    $categoryQuery = db()->prepare(
        "SELECT r.category,
                SUM(r.report_type = 'lost') AS lost,
                SUM(r.report_type = 'found') AS found,
                SUM(r.report_type = 'lost' AND completed.lost_report_id IS NOT NULL) AS recovered
         FROM reports r
         LEFT JOIN (
             SELECT DISTINCT lost_report_id FROM matches
             WHERE assignment_status = 'completed' AND status <> 'dismissed'
         ) completed ON completed.lost_report_id = r.id
         WHERE r.created_at >= ? AND r.created_at < ?
         GROUP BY r.category
         HAVING SUM(r.report_type = 'lost') > 0
         ORDER BY lost DESC, r.category
         LIMIT 10"
    );
    $categoryQuery->execute([analyticsSqlDate($range['start']), analyticsSqlDate($range['end'])]);
    $categories = [];
    foreach ($categoryQuery->fetchAll() as $row) {
        $lost = (int) $row['lost'];
        $recovered = (int) $row['recovered'];
        $categories[$row['category']] = [
            'category' => $row['category'],
            'lost' => $lost,
            'found' => (int) $row['found'],
            'share' => $summary['lost'] > 0 ? round($lost / $summary['lost'] * 100, 1) : 0.0,
            'recovered' => $recovered,
            'recoveryRate' => analyticsRate($recovered, $lost),
            'monthly' => [],
        ];
    }

    $locationQuery = db()->prepare(
        "SELECT r.location,
                SUM(r.report_type = 'lost') AS lost,
                SUM(r.report_type = 'found') AS found,
                SUM(r.report_type = 'lost' AND completed.lost_report_id IS NOT NULL) AS recovered,
                SUM(r.report_type = 'found' AND r.status <> 'resolved'
                    AND approved.report_id IS NULL AND completedFound.found_report_id IS NULL) AS unclaimed
         FROM reports r
         LEFT JOIN (
             SELECT DISTINCT lost_report_id FROM matches
             WHERE assignment_status = 'completed' AND status <> 'dismissed'
         ) completed ON completed.lost_report_id = r.id
         LEFT JOIN (
             SELECT DISTINCT found_report_id FROM matches
             WHERE assignment_status = 'completed' AND status <> 'dismissed'
         ) completedFound ON completedFound.found_report_id = r.id
         LEFT JOIN (SELECT DISTINCT report_id FROM claims WHERE status = 'approved') approved
             ON approved.report_id = r.id
         WHERE r.created_at >= ? AND r.created_at < ?
         GROUP BY r.location
         ORDER BY (SUM(r.report_type = 'lost') + SUM(r.report_type = 'found')) DESC,
                  SUM(r.report_type = 'lost') DESC, r.location"
    );
    $locationQuery->execute([analyticsSqlDate($range['start']), analyticsSqlDate($range['end'])]);
    $allLocations = array_map(static function ($row) {
        $lost = (int) $row['lost'];
        $recovered = (int) $row['recovered'];
        return [
            'location' => $row['location'],
            'lost' => $lost,
            'found' => (int) $row['found'],
            'unclaimed' => (int) $row['unclaimed'],
            'recovered' => $recovered,
            'recoveryRate' => analyticsRate($recovered, $lost),
        ];
    }, $locationQuery->fetchAll());
    $locationsByLost = $allLocations;
    usort($locationsByLost, static fn($a, $b) => ($b['lost'] <=> $a['lost']) ?: ($b['found'] <=> $a['found']));
    $locationsByActivity = $allLocations;
    usort($locationsByActivity, static fn($a, $b) => (($b['lost'] + $b['found']) <=> ($a['lost'] + $a['found'])) ?: ($b['lost'] <=> $a['lost']));
    $locationsByUnclaimed = array_values(array_filter($allLocations, static fn($location) => $location['unclaimed'] > 0));
    usort($locationsByUnclaimed, static fn($a, $b) => ($b['unclaimed'] <=> $a['unclaimed']) ?: strcmp($a['location'], $b['location']));
    $locationsByRecovery = array_values(array_filter($allLocations, static fn($location) => $location['lost'] > 0));
    usort($locationsByRecovery, static fn($a, $b) => ($b['recoveryRate'] <=> $a['recoveryRate']) ?: ($b['lost'] <=> $a['lost']));
    $locations = array_slice($locationsByActivity, 0, 10);
    $locationsByLost = array_slice($locationsByLost, 0, 10);
    $locationsByUnclaimed = array_slice($locationsByUnclaimed, 0, 5);
    $locationsByRecovery = array_slice($locationsByRecovery, 0, 5);

    $trendStart = $monthStart->modify('-5 months');
    $trendQuery = db()->prepare(
        "SELECT r.created_at, (completed.lost_report_id IS NOT NULL) AS recovered
         FROM reports r
         LEFT JOIN (
             SELECT DISTINCT lost_report_id FROM matches
             WHERE assignment_status = 'completed' AND status <> 'dismissed'
         ) completed ON completed.lost_report_id = r.id
         WHERE r.report_type = 'lost' AND r.created_at >= ? AND r.created_at < ?
         ORDER BY r.created_at"
    );
    $trendQuery->execute([analyticsSqlDate($trendStart), analyticsSqlDate($now->modify('+1 second'))]);
    $trendRows = [];
    foreach ($trendQuery->fetchAll() as $row) {
        $monthKey = (new DateTimeImmutable($row['created_at'], new DateTimeZone('UTC')))
            ->setTimezone($timezone)->format('Y-m');
        if (!isset($trendRows[$monthKey])) {
            $trendRows[$monthKey] = ['lost' => 0, 'recovered' => 0];
        }
        $trendRows[$monthKey]['lost']++;
        $trendRows[$monthKey]['recovered'] += (int) $row['recovered'];
    }
    $monthlyTrend = [];
    $trendMonths = [];
    for ($index = 0; $index < 6; $index++) {
        $month = $trendStart->modify("+{$index} months");
        $trendMonths[] = $month;
        $key = $month->format('Y-m');
        $values = $trendRows[$key] ?? ['lost' => 0, 'recovered' => 0];
        $monthlyTrend[] = [
            'month' => $month->format('M Y'),
            'lost' => $values['lost'],
            'recovered' => $values['recovered'],
            'rate' => analyticsRate($values['recovered'], $values['lost']),
        ];
    }

    $categoryTrendQuery = db()->prepare(
        "SELECT r.category, r.created_at
         FROM reports r
         WHERE r.report_type = 'lost' AND r.created_at >= ? AND r.created_at < ?
         ORDER BY r.created_at"
    );
    $categoryTrendQuery->execute([analyticsSqlDate($trendStart), analyticsSqlDate($now->modify('+1 second'))]);
    foreach ($categoryTrendQuery->fetchAll() as $row) {
        if (isset($categories[$row['category']])) {
            $monthKey = (new DateTimeImmutable($row['created_at'], new DateTimeZone('UTC')))
                ->setTimezone($timezone)->format('Y-m');
            $categories[$row['category']]['monthly'][$monthKey] = ($categories[$row['category']]['monthly'][$monthKey] ?? 0) + 1;
        }
    }
    $categoryTrend = [];
    foreach (array_slice(array_keys($categories), 0, 5) as $category) {
        $categoryTrend[] = [
            'category' => $category,
            'values' => array_map(static function ($month) use ($categories, $category) {
                return $categories[$category]['monthly'][$month->format('Y-m')] ?? 0;
            }, $trendMonths),
        ];
    }
    foreach ($categories as &$category) {
        unset($category['monthly']);
    }
    unset($category);

    $data = [
        'period' => $period,
        'range' => [
            'label' => $range['label'],
            'start' => $range['start']->format('Y-m-d'),
            'end' => $now->format('Y-m-d'),
        ],
        'summary' => $summary,
        'monthlyComparison' => $monthlyComparison,
        'monthlyTrend' => $monthlyTrend,
        'categories' => array_values($categories),
        'categoryTrend' => $categoryTrend,
        'locations' => $locations,
        'locationsByLost' => $locationsByLost,
        'locationsByUnclaimed' => $locationsByUnclaimed,
        'locationsByRecovery' => $locationsByRecovery,
    ];
    $data['insights'] = analyticsInsight($data, $range['label']);
    respond(200, ['analytics' => $data]);
} catch (Throwable $error) {
    error_log('[lost-found analytics] ' . $error);
    fail(500, 'Could not load analytics. Please try again in a moment.');
}
