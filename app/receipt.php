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

/** Loads a sale plus its lines, or null when the caller may not see it. */
function receipt_data(string $column, string|int $value, ?array $user = null): ?array
{
    $sql = "SELECT * FROM sales WHERE {$column} = :value";
    $params = ['value' => $value];

    // Staff may only print their own sales; admins and the public token route
    // are not narrowed here.
    if ($user !== null && $user['role'] === 'staff') {
        $sql .= ' AND staff_id = :staff_id';
        $params['staff_id'] = $user['id'];
    }

    $statement = database()->prepare($sql . ' LIMIT 1');
    $statement->execute($params);
    $sale = $statement->fetch();

    if (!$sale) {
        return null;
    }

    $items = database()->prepare('SELECT * FROM sale_items WHERE sale_id = :id ORDER BY id');
    $items->execute(['id' => $sale['id']]);

    return ['sale' => $sale, 'items' => $items->fetchAll()];
}

function receipt_payload(array $sale, array $items): array
{
    return [
        'shop' => [
            'name' => setting('shop_name', app_name()),
            'phone' => setting('shop_phone'),
            'address' => setting('shop_address'),
            'footer' => setting('receipt_footer', 'Thank you for your business.'),
        ],
        'width' => receipt_width_mm(),
        'sale' => [
            'id' => (int) $sale['id'],
            'date' => friendly_date($sale['sale_date']),
            'time' => date('h:i A', strtotime((string) $sale['sale_time'])),
            'staff' => $sale['staff_name'],
            'customer' => $sale['customer_name'] ?: 'Walk-in customer',
            'contact' => $sale['customer_contact'] ?: '',
            'address' => $sale['customer_address'] ?: '',
            'subtotal' => (float) $sale['subtotal_amount'],
            'discount' => (float) $sale['discount_amount'],
            'discount_label' => discount_label($sale),
            'total' => (float) $sale['total_amount'],
        ],
        'items' => array_map(static fn (array $item): array => [
            'name' => $item['product_name'],
            'qty' => (int) $item['quantity'],
            'rate' => (float) $item['rate'],
            'total' => (float) $item['line_total'],
        ], $items),
        'share_url' => $sale['public_token'] ? public_receipt_url((string) $sale['public_token']) : '',
    ];
}

function discount_label(array $sale): string
{
    if (($sale['discount_type'] ?? 'none') === 'percent') {
        return 'Discount ' . rtrim(rtrim(number_format((float) $sale['discount_value'], 2), '0'), '.') . '%';
    }

    if (($sale['discount_type'] ?? 'none') === 'amount') {
        return 'Discount';
    }

    return '';
}

/** The paper itself, shared by the staff/admin view and the public link. */
function receipt_paper(array $payload): void
{
    $sale = $payload['sale'];
    $shop = $payload['shop'];
    ?>
    <article class="receipt-paper" id="receipt-paper" style="--roll:<?= (int) $payload['width'] ?>mm">
        <header class="receipt-head">
            <h2><?= e($shop['name']) ?></h2>
            <?php if ($shop['address']): ?><p><?= e($shop['address']) ?></p><?php endif; ?>
            <?php if ($shop['phone']): ?><p><?= e($shop['phone']) ?></p><?php endif; ?>
        </header>
        <div class="receipt-rule"></div>
        <dl class="receipt-meta">
            <div><dt>Receipt</dt><dd>#<?= (int) $sale['id'] ?></dd></div>
            <div><dt>Date</dt><dd><?= e($sale['date']) ?> <?= e($sale['time']) ?></dd></div>
            <div><dt>Salesman</dt><dd><?= e($sale['staff']) ?></dd></div>
            <div><dt>Customer</dt><dd><?= e($sale['customer']) ?></dd></div>
            <?php if ($sale['contact']): ?><div><dt>Contact</dt><dd><?= e($sale['contact']) ?></dd></div><?php endif; ?>
        </dl>
        <div class="receipt-rule"></div>
        <table class="receipt-items">
            <thead><tr><th>Item</th><th>Qty</th><th>Rate</th><th>Amount</th></tr></thead>
            <tbody>
                <?php foreach ($payload['items'] as $item): ?>
                <tr>
                    <td><?= e($item['name']) ?></td>
                    <td><?= (int) $item['qty'] ?></td>
                    <td><?= e(number_format($item['rate'], 0)) ?></td>
                    <td><?= e(number_format($item['total'], 0)) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="receipt-rule"></div>
        <dl class="receipt-totals">
            <div><dt>Subtotal</dt><dd><?= e(money($sale['subtotal'])) ?></dd></div>
            <?php if ($sale['discount'] > 0): ?>
            <div class="is-discount"><dt><?= e($sale['discount_label']) ?></dt><dd>− <?= e(money($sale['discount'])) ?></dd></div>
            <?php endif; ?>
            <div class="is-total"><dt>Total</dt><dd><?= e(money($sale['total'])) ?></dd></div>
        </dl>
        <div class="receipt-rule"></div>
        <footer class="receipt-foot">
            <p><?= e($shop['footer']) ?></p>
            <p class="receipt-ref">Receipt #<?= (int) $sale['id'] ?> · <?= e($sale['date']) ?></p>
        </footer>
    </article>
    <?php
}

function receipt_actions(array $payload): void
{
    ?>
    <div class="receipt-actions no-print" id="receipt-actions"
         data-receipt='<?= e(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>
        <button class="button primary full" id="receipt-print" type="button">PRINT RECEIPT</button>
        <div class="receipt-action-row">
            <button class="button secondary" id="receipt-share" type="button">Share on WhatsApp</button>
            <button class="button secondary" id="receipt-save" type="button">Save as photo</button>
        </div>
        <?php if ($payload['share_url']): ?>
        <label class="share-link-field">Receipt link
            <span><input id="receipt-link" type="text" readonly value="<?= e($payload['share_url']) ?>">
            <button class="button tiny" id="receipt-copy" type="button">Copy</button></span>
        </label>
        <?php endif; ?>
        <p class="muted small">The photo saves to your phone gallery. WhatsApp gets the picture where your phone supports it, and the link otherwise.</p>
    </div>
    <canvas id="receipt-canvas" hidden></canvas>
    <?php
}

function render_receipt_page(array $user): void
{
    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    $data = $id ? receipt_data('id', $id, $user) : null;

    if (!$data) {
        http_response_code(404);
        set_flash('error', 'Receipt not found.');
        redirect($user['role'] === 'admin' ? 'admin-sales' : 'my-sales');
    }

    $payload = receipt_payload($data['sale'], $data['items']);
    $back = 'index.php?page=sale-detail&id=' . (int) $data['sale']['id'];

    layout_start('Receipt #' . (int) $data['sale']['id'], $user, 'receipt', 'receipt-body');
    page_heading('RECEIPT', 'Receipt #' . (int) $data['sale']['id'], $user, $back);
    ?><section class="receipt-stage"><?php receipt_paper($payload); ?></section><?php
    receipt_actions($payload);
    receipt_print_styles($payload['width']);
    layout_end($user, 'receipt', false, true);
}

/**
 * The share-link view. No session, no navigation — just the paper, so a
 * customer opening the WhatsApp link sees only their own receipt.
 */
function render_public_receipt(string $token): void
{
    $data = preg_match('/^[a-f0-9]{32}$/', $token) === 1
        ? receipt_data('public_token', $token)
        : null;

    if (!$data) {
        http_response_code(404);
        ?><!doctype html><html lang="en"><head><meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Receipt not found</title><link rel="stylesheet" href="assets/app.css"></head>
        <body class="login-body"><main class="login-page"><h1>Receipt not found</h1>
        <p class="muted center">This receipt link is not valid or has been removed.</p></main></body></html><?php
        exit;
    }

    $payload = receipt_payload($data['sale'], $data['items']);
    ?><!doctype html>
    <html lang="en"><head>
        <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
        <meta name="theme-color" content="#0b7656"><meta name="robots" content="noindex,nofollow">
        <title>Receipt #<?= (int) $data['sale']['id'] ?> — <?= e($payload['shop']['name']) ?></title>
        <link rel="stylesheet" href="assets/app.css">
    </head><body class="receipt-body public-receipt">
    <main class="page-shell">
        <section class="receipt-stage"><?php receipt_paper($payload); ?></section>
        <div class="receipt-actions no-print">
            <button class="button primary full" id="receipt-print" type="button">PRINT RECEIPT</button>
        </div>
    </main>
    <?php receipt_print_styles($payload['width']); ?>
    <script src="assets/receipt.js" defer></script>
    </body></html><?php
    exit;
}

/**
 * Print rules are emitted per-receipt because the roll width is a shop
 * setting, and @page size cannot read a CSS custom property.
 */
function receipt_print_styles(int $width): void
{
    ?><style>
        .receipt-paper { --roll: <?= $width ?>mm; }
        @media print {
            @page { size: <?= $width ?>mm auto; margin: 0; }
            html, body { width: <?= $width ?>mm; margin: 0; padding: 0; background: #fff; }
            .no-print, .bottom-nav, .page-heading, .alert { display: none !important; }
            .page-shell, .receipt-stage { margin: 0; padding: 0; max-width: none; }
            .receipt-paper { width: <?= $width ?>mm; box-shadow: none; border: 0; border-radius: 0; margin: 0; padding: 4mm 3mm; }
        }
    </style><?php
}
