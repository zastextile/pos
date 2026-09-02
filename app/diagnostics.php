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

/**
 * Every file this release changed, with a marker proving the new version of
 * it actually landed rather than an older copy of the same filename.
 */
function diagnostic_files(): array
{
    return [
        'index.php'                    => ["asset('assets/app.js')", 'versioned asset URLs'],
        'app/bootstrap.php'            => ['function asset(', 'asset() helper'],
        'app/receipt.php'              => ['receipt-paper', 'receipt layout'],
        'app/reports.php'              => ['report_by_customer', 'customer report'],
        'app/diagnostics.php'          => ['diagnostic_files', 'this page'],
        'assets/app.js'                => ['data-quantity-input', 'editable cart quantity'],
        'assets/app.css'               => ['.quantity-input', 'cart quantity styling'],
        'assets/receipt.js'            => ['receipt-canvas', 'receipt image'],
        'assets/install.js'            => ['beforeinstallprompt', 'install prompt'],
        'sw.js'                        => ['VERSION = "v3"', 'service worker v3'],
        'manifest.json'                => ['maskable', 'installable icons'],
        'bin/migrate_v2.php'           => ['max_discount_percent', 'database upgrade'],
        'icons/icon-512-maskable.png'  => [null, 'maskable icon'],
    ];
}

function diagnostic_database(): array
{
    $pdo = database();

    $hasColumn = static function (string $table, string $column) use ($pdo): bool {
        $q = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
        );
        $q->execute(['t' => $table, 'c' => $column]);
        return (int) $q->fetchColumn() > 0;
    };

    $hasTable = static function (string $table) use ($pdo): bool {
        $q = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
        );
        $q->execute(['t' => $table]);
        return (int) $q->fetchColumn() > 0;
    };

    return [
        'categories table'          => $hasTable('categories'),
        'customers table'           => $hasTable('customers'),
        'settings table'            => $hasTable('settings'),
        'users.max_discount_percent'=> $hasColumn('users', 'max_discount_percent'),
        'products.category_id'      => $hasColumn('products', 'category_id'),
        'products.photo'            => $hasColumn('products', 'photo'),
        'sales.discount_amount'     => $hasColumn('sales', 'discount_amount'),
        'sales.customer_id'         => $hasColumn('sales', 'customer_id'),
        'sales.public_token'        => $hasColumn('sales', 'public_token'),
    ];
}

function render_diagnostics(array $user): void
{
    $files = [];
    $missing = 0;

    foreach (diagnostic_files() as $path => [$marker, $label]) {
        $full = APP_ROOT . '/' . $path;
        $exists = is_file($full);
        $fresh = true;

        if ($exists && $marker !== null) {
            $contents = (string) file_get_contents($full);
            $fresh = str_contains($contents, $marker);
        }

        if (!$exists || !$fresh) {
            $missing++;
        }

        $files[] = [
            'path' => $path,
            'label' => $label,
            'exists' => $exists,
            'fresh' => $fresh,
            'size' => $exists ? filesize($full) : 0,
            'time' => $exists ? date('d M Y H:i', (int) filemtime($full)) : '—',
        ];
    }

    $database = diagnostic_database();
    $dbMissing = count(array_filter($database, static fn ($ok) => !$ok));
    $uploads = APP_ROOT . '/uploads/products';

    layout_start('Installation Check', $user, 'diagnostics');
    page_heading('ADMIN', 'Installation Check', $user, 'index.php?page=admin-dashboard');
    ?>
    <section class="report-headline">
        <div><span><?= $missing === 0 && $dbMissing === 0 ? 'Everything is up to date' : 'Needs attention' ?></span>
        <strong><?= $missing + $dbMissing ?></strong></div>
        <p><?= $missing ?> file<?= $missing === 1 ? '' : 's' ?> out of date or missing ·
           <?= $dbMissing ?> database change<?= $dbMissing === 1 ? '' : 's' ?> not applied</p>
    </section>

    <?php if ($missing > 0): ?>
    <div class="alert error" role="status">Re-upload the files marked below, then reload this page.</div>
    <?php endif; ?>
    <?php if ($dbMissing > 0): ?>
    <div class="alert error" role="status">Run <b>php bin/migrate_v2.php</b> from your public_html folder.</div>
    <?php endif; ?>

    <div class="section-heading padded-top"><div><p class="eyebrow muted-label">FILES ON THIS SERVER</p><h2>Uploaded files</h2></div></div>
    <section class="check-list">
        <?php foreach ($files as $file): ?>
        <article class="check-row <?= $file['exists'] && $file['fresh'] ? 'ok' : 'bad' ?>">
            <span class="check-mark"><?= $file['exists'] && $file['fresh'] ? '✓' : '✕' ?></span>
            <div>
                <h3><?= e($file['path']) ?></h3>
                <p><?php if (!$file['exists']): ?>Not found — upload this file<?php
                    elseif (!$file['fresh']): ?>Old version — re-upload (missing: <?= e($file['label']) ?>)<?php
                    else: ?><?= e($file['label']) ?> · <?= number_format($file['size'] / 1024, 1) ?> KB · <?= e($file['time']) ?><?php endif; ?></p>
            </div>
        </article>
        <?php endforeach; ?>
    </section>

    <div class="section-heading padded-top"><div><p class="eyebrow muted-label">DATABASE</p><h2>Upgrade status</h2></div></div>
    <section class="check-list">
        <?php foreach ($database as $name => $ok): ?>
        <article class="check-row <?= $ok ? 'ok' : 'bad' ?>">
            <span class="check-mark"><?= $ok ? '✓' : '✕' ?></span>
            <div><h3><?= e($name) ?></h3><p><?= $ok ? 'Applied' : 'Missing — run the migration' ?></p></div>
        </article>
        <?php endforeach; ?>
    </section>

    <div class="section-heading padded-top"><div><p class="eyebrow muted-label">SERVER</p><h2>Environment</h2></div></div>
    <section class="check-list">
        <?php
        $env = [
            'App version' => APP_VERSION,
            'PHP version' => PHP_VERSION,
            'Photo resizing (GD)' => extension_loaded('gd') ? 'Available' : 'MISSING — photos will not save',
            'Photo folder writable' => is_dir($uploads) && is_writable($uploads) ? 'Yes' : 'NO — check permissions on uploads/products',
            'Receipt roll width' => receipt_width_mm() . ' mm',
        ];
        foreach ($env as $name => $value): ?>
        <article class="check-row ok"><span class="check-mark">·</span>
            <div><h3><?= e($name) ?></h3><p><?= e((string) $value) ?></p></div></article>
        <?php endforeach; ?>
    </section>

    <div class="section-heading padded-top"><div><p class="eyebrow muted-label">BROWSER</p><h2>What this device is running</h2></div></div>
    <section class="check-list" id="browser-check"></section>
    <p class="muted small">If a file above says “Old version”, the copy on the server is stale. If the server is fine but this device still behaves oddly, use Clear cache below.</p>
    <button class="button secondary full" id="clear-cache" type="button">CLEAR THIS DEVICE'S CACHE AND RELOAD</button>

    <script>
    (() => {
      const list = document.getElementById('browser-check');
      const row = (ok, name, detail) =>
        `<article class="check-row ${ok ? 'ok' : 'bad'}"><span class="check-mark">${ok ? '\u2713' : '\u2715'}</span>` +
        `<div><h3>${name}</h3><p>${detail}</p></div></article>`;

      (async () => {
        const rows = [];
        // The stylesheet link is in <head>, so it is present whatever the
        // deferred scripts have done by now.
        const sheet = document.querySelector('link[rel=stylesheet]');
        const versioned = !!sheet && sheet.getAttribute('href').includes('?v=');
        rows.push(row(versioned, 'Stylesheet and script versioning',
          versioned ? 'Active — updates reach this device' : 'Not active — index.php on the server is an old copy'));

        const css = getComputedStyle(document.documentElement).getPropertyValue('--green').trim();
        rows.push(row(css !== '', 'Stylesheet loaded', css ? 'Yes' : 'No — assets/app.css did not load'));

        if ('serviceWorker' in navigator) {
          const regs = await navigator.serviceWorker.getRegistrations();
          const keys = 'caches' in window ? await caches.keys() : [];
          const current = keys.some(k => k.endsWith('v3'));
          rows.push(row(current || keys.length === 0, 'Offline cache',
            keys.length ? keys.join(', ') + (current ? ' (current)' : ' (OLD — press the button below)') : 'None yet'));
          rows.push(row(true, 'Service workers registered', String(regs.length)));
          regs.forEach(r => r.update());
        }
        list.innerHTML = rows.join('');
      })();

      document.getElementById('clear-cache').addEventListener('click', async () => {
        if ('serviceWorker' in navigator) {
          for (const r of await navigator.serviceWorker.getRegistrations()) await r.unregister();
        }
        if ('caches' in window) {
          for (const k of await caches.keys()) await caches.delete(k);
        }
        location.reload(true);
      });
    })();
    </script>
    <?php
    layout_end($user, 'diagnostics');
}
