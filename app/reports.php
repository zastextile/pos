<?php
declare(strict_types=1);

if (
    PHP_SAPI !== 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__
) {
    http_response_code(404);
    exit;
}

function report_views(): array
{
    return [
        'product'  => 'By Product',
        'category' => 'By Category',
        'customer' => 'By Customer',
        'staff'    => 'By Salesman',
        'discount' => 'Discounts',
    ];
}

/**
 * Every report answers the same question over a different grouping, so they
 * share one date window and one query shape.
 */
function render_reports(array $user): void
{
    $views = report_views();
    $requestedView = (string) ($_GET['view'] ?? 'product');
    $view = in_array($requestedView, array_keys($views), true) ? $requestedView : 'product';
    $requestedPreset = (string) ($_GET['preset'] ?? 'month');
    $preset = in_array($requestedPreset, ['today', 'yesterday', 'week', 'month', 'custom'], true) ? $requestedPreset : 'month';
    [$from, $to] = report_dates($preset);
    $range = ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];

    $totals = database()->prepare(
        'SELECT COUNT(*) AS sales, COALESCE(SUM(subtotal_amount),0) AS gross,
                COALESCE(SUM(discount_amount),0) AS discount, COALESCE(SUM(total_amount),0) AS net
         FROM sales WHERE sale_date BETWEEN :from AND :to'
    );
    $totals->execute($range);
    $summary = $totals->fetch();

    layout_start('Reports', $user, 'reports');
    page_heading('ADMIN', 'Reports', $user);
    ?>
    <form class="report-filter" method="get">
        <input type="hidden" name="page" value="reports">
        <input type="hidden" name="view" value="<?= e($view) ?>">
        <div class="preset-row">
            <?php foreach (['today' => 'Today', 'yesterday' => 'Yesterday', 'week' => 'This Week', 'month' => 'This Month'] as $key => $label): ?>
                <a class="chip <?= $preset === $key ? 'active' : '' ?>" href="index.php?page=reports&view=<?= e($view) ?>&preset=<?= e($key) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="filter-card">
            <div class="filter-dates">
                <label>From<input type="date" name="from" value="<?= e($range['from']) ?>"></label>
                <label>To<input type="date" name="to" value="<?= e($range['to']) ?>"></label>
            </div>
            <input type="hidden" name="preset" value="custom">
            <button class="button primary full" type="submit">APPLY</button>
        </div>
    </form>

    <section class="report-headline">
        <div><span><?= (int) $summary['sales'] ?> sales</span><strong><?= e(money($summary['net'])) ?></strong></div>
        <p><?= e(money($summary['gross'])) ?> before <?= e(money($summary['discount'])) ?> discount · <?= e(friendly_date($range['from'])) ?> – <?= e(friendly_date($range['to'])) ?></p>
    </section>

    <nav class="report-tabs" aria-label="Report type">
        <?php foreach ($views as $key => $label): ?>
            <a class="<?= $view === $key ? 'active' : '' ?>" href="index.php?page=reports&view=<?= e($key) ?>&preset=<?= e($preset) ?>&from=<?= e($range['from']) ?>&to=<?= e($range['to']) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php
    match ($view) {
        'product'  => report_by_product($range),
        'category' => report_by_category($range),
        'customer' => report_by_customer($range),
        'staff'    => report_by_staff($range),
        'discount' => report_discounts($range),
        default    => report_by_product($range),
    };

    layout_end($user, 'reports');
}

function report_rows(string $sql, array $range): array
{
    $statement = database()->prepare($sql);
    $statement->execute($range);
    return $statement->fetchAll();
}

/** One row per grouped line, with a share-of-total bar for quick scanning. */
function report_table(array $rows, string $emptyMessage, callable $row): void
{
    if (!$rows) {
        ?><div class="empty-card"><p><?= e($emptyMessage) ?></p></div><?php
        return;
    }

    $peak = 0.0;
    foreach ($rows as $item) {
        $peak = max($peak, (float) $item['amount']);
    }

    ?><section class="report-list"><?php
    foreach ($rows as $item) {
        [$title, $detail] = $row($item);
        $share = $peak > 0 ? ((float) $item['amount'] / $peak) * 100 : 0;
        ?><article class="report-row">
            <div class="report-row-head">
                <div><h3><?= e($title) ?></h3><p><?= e($detail) ?></p></div>
                <strong><?= e(money($item['amount'])) ?></strong>
            </div>
            <div class="report-bar"><span style="width: <?= number_format($share, 2) ?>%"></span></div>
        </article><?php
    }
    ?></section><?php
}

function report_by_product(array $range): void
{
    $rows = report_rows(
        'SELECT si.product_name AS label, si.barcode,
                SUM(si.quantity) AS units,
                COUNT(DISTINCT s.id) AS sales,
                SUM(si.line_total) AS amount
         FROM sale_items si
         JOIN sales s ON s.id = si.sale_id
         WHERE s.sale_date BETWEEN :from AND :to
         GROUP BY si.product_name, si.barcode
         ORDER BY amount DESC
         LIMIT 200',
        $range
    );

    report_table($rows, 'No products were sold in this period.', static fn (array $r): array => [
        $r['label'],
        number_format((int) $r['units']) . ' units · ' . (int) $r['sales'] . ' sale' . ((int) $r['sales'] === 1 ? '' : 's') . ' · ' . $r['barcode'],
    ]);
}

function report_by_category(array $range): void
{
    // Grouped through the live product record, so a renamed category follows.
    $rows = report_rows(
        "SELECT COALESCE(c.name, 'Uncategorised') AS label,
                SUM(si.quantity) AS units,
                COUNT(DISTINCT si.product_id) AS products,
                SUM(si.line_total) AS amount
         FROM sale_items si
         JOIN sales s ON s.id = si.sale_id
         LEFT JOIN products p ON p.id = si.product_id
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE s.sale_date BETWEEN :from AND :to
         GROUP BY label
         ORDER BY amount DESC",
        $range
    );

    report_table($rows, 'No category sales in this period.', static fn (array $r): array => [
        $r['label'],
        number_format((int) $r['units']) . ' units · ' . (int) $r['products'] . ' product' . ((int) $r['products'] === 1 ? '' : 's'),
    ]);
}

function report_by_customer(array $range): void
{
    $rows = report_rows(
        "SELECT COALESCE(NULLIF(s.customer_name, ''), 'Walk-in customer') AS label,
                MAX(s.customer_contact) AS contact,
                COUNT(*) AS sales,
                SUM(s.total_amount) AS amount
         FROM sales s
         WHERE s.sale_date BETWEEN :from AND :to
         GROUP BY label
         ORDER BY amount DESC
         LIMIT 200",
        $range
    );

    report_table($rows, 'No customer sales in this period.', static fn (array $r): array => [
        $r['label'],
        (int) $r['sales'] . ' sale' . ((int) $r['sales'] === 1 ? '' : 's') . ($r['contact'] ? ' · ' . $r['contact'] : ''),
    ]);
}

function report_by_staff(array $range): void
{
    $rows = report_rows(
        'SELECT s.staff_name AS label,
                COUNT(*) AS sales,
                SUM(s.discount_amount) AS discount,
                SUM(s.total_amount) AS amount
         FROM sales s
         WHERE s.sale_date BETWEEN :from AND :to
         GROUP BY s.staff_name
         ORDER BY amount DESC',
        $range
    );

    report_table($rows, 'No staff sales in this period.', static fn (array $r): array => [
        $r['label'],
        (int) $r['sales'] . ' sale' . ((int) $r['sales'] === 1 ? '' : 's') . ' · ' . money($r['discount']) . ' discount given',
    ]);
}

function report_discounts(array $range): void
{
    // Who is discounting, how hard, and against what ceiling.
    $rows = report_rows(
        "SELECT s.staff_name AS label,
                u.max_discount_percent AS ceiling,
                COUNT(*) AS sales,
                SUM(CASE WHEN s.discount_amount > 0 THEN 1 ELSE 0 END) AS discounted,
                SUM(s.subtotal_amount) AS gross,
                SUM(s.discount_amount) AS amount
         FROM sales s
         LEFT JOIN users u ON u.id = s.staff_id
         WHERE s.sale_date BETWEEN :from AND :to
         GROUP BY s.staff_name, u.max_discount_percent
         ORDER BY amount DESC",
        $range
    );

    report_table($rows, 'No discounts were given in this period.', static function (array $r): array {
        $rate = (float) $r['gross'] > 0 ? ((float) $r['amount'] / (float) $r['gross']) * 100 : 0;

        return [
            $r['label'],
            (int) $r['discounted'] . ' of ' . (int) $r['sales'] . ' sales discounted · '
            . number_format($rate, 1) . '% of gross · limit '
            . rtrim(rtrim(number_format((float) $r['ceiling'], 2), '0'), '.') . '%',
        ];
    });
}
