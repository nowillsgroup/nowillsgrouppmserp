<?php

/**
 * Nowills PMS — cPanel / shared-hosting web installer.
 *
 * cPanel shared hosting usually gives you no SSH and no Composer, so none of
 * the `php artisan` steps in the README can be run. This file does the whole
 * install through the browser: it checks the host, writes .env, generates the
 * application key, runs the migrations, creates your first property and an
 * administrator, and then locks itself.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  DELETE THIS FILE (public/install.php) AS SOON AS THE INSTALL FINISHES.
 *  Until you do, anyone who reaches it can see your host's configuration.
 *  It refuses to reinstall once locked, but it must not be left in place.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Deliberately standalone: it cannot bootstrap Laravel until .env exists and
 * the database is reachable, so the checks phase uses only plain PHP.
 */

declare(strict_types=1);

@set_time_limit(0);
@ini_set('memory_limit', '512M');
@ignore_user_abort(true);

const ROOT = __DIR__.'/..';
const ENV_FILE = ROOT.'/.env';
const LOCK_FILE = ROOT.'/storage/framework/installed.lock';
const CACHE_DIR = ROOT.'/bootstrap/cache';

const MIN_PHP = '8.3.0';
const REQUIRED_EXT = ['pdo', 'pdo_mysql', 'mbstring', 'openssl', 'tokenizer', 'ctype', 'json', 'filter', 'hash', 'session', 'dom', 'xml', 'fileinfo'];
const RECOMMENDED_EXT = ['gd' => 'menu photos, logos and room images', 'curl' => 'OTA calendar sync, webhooks, online payments', 'zip' => 'spreadsheet / archive exports'];
const REQUIRED_DIRS = ['storage', 'storage/app', 'storage/framework', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache', 'public/uploads'];

/*
 * Folders the app writes into at run time. Two of them — public/uploads and
 * storage/app/backups — are gitignored, so they are missing from a fresh
 * upload. The installer creates them itself before running the checks,
 * otherwise it would report a blocking failure for something it is about to
 * create anyway.
 */
const RUNTIME_DIRS = [
    'public/uploads',
    'public/uploads/logos',
    'public/uploads/menu',
    'public/uploads/room-images',
    'storage/app/backups',
    'storage/framework/cache/data',
    'storage/framework/sessions',
    'storage/framework/views',
    'storage/logs',
    'bootstrap/cache',
];

$errors = [];
$done = false;
$summary = [];

// ── Small helpers ────────────────────────────────────────────────────────────

function h(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function installed(): bool
{
    return is_file(LOCK_FILE);
}

function can_write(string $path): bool
{
    if (! file_exists($path)) {
        return false;
    }

    if (is_dir($path)) {
        return is_writable($path);
    }

    return is_writable($path);
}

/**
 * Quote a value for .env.
 *
 * Always double-quoted, and escapes the four characters that would otherwise
 * change the meaning of the file: the backslash itself, the closing quote, a
 * dollar (phpdotenv expands $VAR inside double quotes) and any newline. A
 * bare newline in a password must never be able to inject a config line.
 */
function env_quote(string $v): string
{
    return '"'.str_replace(
        ['\\',  '"',   '$',    "\r", "\n"],
        ['\\\\', '\\"', '\\$', '',   ''],
        $v
    ).'"';
}



function disabled_functions(): array
{
    return array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
}

/** Laravel needs proc_open only for runInBackground() scheduler commands. */
function has_proc_open(): bool
{
    return function_exists('proc_open') && ! in_array('proc_open', disabled_functions(), true);
}

/** Guess the public base URL from the request. */
function guess_url(): string
{
    $https = (($_SERVER['HTTPS'] ?? '') === 'on')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 80) === 443);
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    // install.php sits in /public — the app's base URL is one level up when the
    // project is served through the root .htaccess fallback.
    $dir = preg_replace('#/public$#', '', $dir) ?? $dir;

    return ($https ? 'https://' : 'http://').$host.$dir;
}

function guess_php_bin(): string
{
    foreach (['/usr/local/bin/php', '/usr/bin/php', '/opt/cpanel/ea-php84/root/usr/bin/php', '/opt/cpanel/ea-php83/root/usr/bin/php'] as $p) {
        if (@is_executable($p)) {
            return $p;
        }
    }

    return 'php';
}

/** Empty everything Laravel may have cached from a previous attempt. */
function clear_bootstrap_cache(): array
{
    $cleared = [];
    foreach (['config.php', 'routes-v7.php', 'routes.php', 'services.php', 'packages.php', 'events.php', 'views.php'] as $f) {
        $p = CACHE_DIR.'/'.$f;
        if (is_file($p) && @unlink($p)) {
            $cleared[] = $f;
        }
    }

    return $cleared;
}

// ── Requirements ─────────────────────────────────────────────────────────────

$checks = [];

// Make the runtime folders exist first, so the checks below report on the
// directory we will actually use rather than on one that is simply absent.
foreach (RUNTIME_DIRS as $d) {
    $abs = ROOT.'/'.$d;
    if (! is_dir($abs)) {
        @mkdir($abs, 0755, true);
    }
}

$checks[] = [
    'label' => 'PHP '.MIN_PHP.' or newer',
    'ok' => version_compare(PHP_VERSION, MIN_PHP, '>='),
    'detail' => 'running '.PHP_VERSION,
    'hint' => 'In cPanel open "MultiPHP Manager" and set this domain to PHP 8.3 or 8.4.',
];

foreach (REQUIRED_EXT as $ext) {
    $checks[] = [
        'label' => "Extension: {$ext}",
        'ok' => extension_loaded($ext),
        'detail' => extension_loaded($ext) ? 'loaded' : 'missing',
        'hint' => 'In cPanel open "Select PHP Version" → Extensions and tick "'.$ext.'".',
    ];
}

foreach (RECOMMENDED_EXT as $ext => $why) {
    $checks[] = [
        'label' => "Extension: {$ext} (recommended)",
        'ok' => extension_loaded($ext),
        'detail' => extension_loaded($ext) ? 'loaded' : 'missing — '.$why,
        'hint' => 'Recommended for '.$why.'.',
        'optional' => true,
    ];
}

foreach (REQUIRED_DIRS as $dir) {
    $abs = ROOT.'/'.$dir;
    $checks[] = [
        'label' => "Writable: {$dir}",
        'ok' => can_write($abs),
        'detail' => is_dir($abs) ? (can_write($abs) ? 'writable' : 'not writable') : 'missing',
        'hint' => 'In cPanel File Manager, set the permissions of "'.$dir.'" to 755 (or 775) and its owner to your account user.',
    ];
}

$envWritable = is_file(ENV_FILE) ? is_writable(ENV_FILE) : is_writable(ROOT);
$checks[] = [
    'label' => 'Writable: project root (.env)',
    'ok' => $envWritable,
    'detail' => is_file(ENV_FILE) ? 'existing .env is '.(is_writable(ENV_FILE) ? 'writable' : 'NOT writable') : 'can create .env',
    'hint' => 'The installer must be able to create/overwrite ".env" in the project folder.',
];

$checks[] = [
    'label' => 'Scheduler support (proc_open)',
    'ok' => has_proc_open(),
    'detail' => has_proc_open() ? 'available' : 'disabled by disable_functions',
    'hint' => 'Optional. Without it the two background scheduler commands run in the foreground instead; everything still works.',
    'optional' => true,
];

$checks[] = [
    'label' => 'Laravel dependencies present',
    'ok' => is_file(ROOT.'/build/vendor/autoload.php'),
    'detail' => is_file(ROOT.'/build/vendor/autoload.php') ? 'build/vendor/autoload.php found' : 'build/vendor/autoload.php MISSING',
    'hint' => 'Upload the whole "build" folder with the project (this project keeps Composer packages in build/vendor).',
];

$blocking = array_filter($checks, fn ($c) => ! $c['ok'] && empty($c['optional']));
$checksOk = $blocking === [];

// ── Install action ───────────────────────────────────────────────────────────

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'install') {
    $in = fn (string $k, string $d = '') => trim((string) ($_POST[$k] ?? $d));

    $dbHost = $in('db_host', 'localhost');
    $dbPort = $in('db_port', '3306');
    $dbName = $in('db_name');
    $dbUser = $in('db_user');
    $dbPass = (string) ($_POST['db_pass'] ?? '');
    $appUrl = rtrim($in('app_url', guess_url()), '/');
    $appName = $in('app_name', 'Nowills PMS');

    $hotelCode = strtoupper($in('hotel_code'));
    $hotelName = $in('hotel_name');
    $currency = $in('currency', 'GHS');
    $currencySymbol = $in('currency_symbol', 'GH₵');
    $timezone = $in('timezone', 'Africa/Accra');

    $adminName = $in('admin_name', 'Administrator');
    $adminUser = $in('admin_user', 'admin');
    $adminEmail = $in('admin_email');
    $adminPass = (string) ($_POST['admin_pass'] ?? '');
    $seedDemo = ! empty($_POST['seed_demo']);

    if (! $checksOk) {
        $errors[] = 'Fix the failing requirements above before installing.';
    }
    foreach (['db_name' => $dbName, 'db_user' => $dbUser, 'hotel_code' => $hotelCode, 'hotel_name' => $hotelName, 'admin_user' => $adminUser] as $k => $v) {
        if ($v === '') {
            $errors[] = "Field \"{$k}\" is required.";
        }
    }
    if (! preg_match('/^[A-Z0-9]{2,30}$/', $hotelCode)) {
        $errors[] = 'Hotel ID must be 2–30 characters, letters and digits only (it is your login ID, e.g. NOWILLS).';
    }
    if (strlen($adminPass) < 8) {
        $errors[] = 'The administrator password must be at least 8 characters.';
    }
    if (! preg_match('/^[A-Za-z0-9._-]{2,60}$/', $adminUser)) {
        $errors[] = 'Username may contain letters, digits, dot, underscore and dash only.';
    }

    // These values are written into .env. env_quote() strips line breaks so
    // that nothing can inject a config line, but silently altering a password
    // would lock you out of MySQL with a confusing error later. Reject it
    // here and say why. (The admin login password is stored hashed, never in
    // .env, so it is allowed to contain anything.)
    foreach (['database name' => $dbName, 'database user' => $dbUser, 'database password' => $dbPass, 'database host' => $dbHost, 'site URL' => $appUrl, 'site name' => $appName, 'e-mail' => $adminEmail] as $label => $v) {
        if (preg_match('/[\x00-\x1F\x7F]/', $v)) {
            $errors[] = "The {$label} contains a line break or control character, which cannot be stored in .env. Paste it again as a single line.";
        }
    }

    // Test the database with PDO first: a clear "Access denied" beats a
    // mile-long Laravel stack trace.
    if (! $errors) {
        try {
            $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
            $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
            $pdo->query('SELECT 1');
            $pdo = null;
            $summary[] = 'Database connection OK';
        } catch (Throwable $e) {
            $errors[] = 'Could not connect to MySQL: '.$e->getMessage()
                .' — check the database name (cPanel prefixes it, e.g. myaccount_pms), the user and the password.';
        }
    }

    if (! $errors) {
        // Write .env ---------------------------------------------------------
        // Every value the user supplied goes through env_quote() here, at the
        // point of assembly. Never interpolate raw input into a template: a
        // newline in a password would inject a second, attacker-chosen line.
        // A nowdoc (<<<'ENV') does no interpolation at all, so the template can
        // never be corrupted by what the user typed. The values go in
        // afterwards through env_quote(), and strtr() makes a single pass, so
        // an inserted value is never re-scanned for further placeholders.
        $env = <<<'ENV'
APP_NAME=__APP_NAME__
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=__APP_URL__

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=__DB_HOST__
DB_PORT=__DB_PORT__
DB_DATABASE=__DB_DATABASE__
DB_USERNAME=__DB_USERNAME__
DB_PASSWORD=__DB_PASSWORD__

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=__SESSION_SECURE_COOKIE__
SESSION_SAME_SITE=lax

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=sync
CACHE_STORE=file

MAIL_MAILER=log
MAIL_FROM_ADDRESS=__MAIL_FROM_ADDRESS__
MAIL_FROM_NAME=__MAIL_FROM_NAME__

PMS_FRAME_ANCESTORS="'self'"
ENV;

        // A secure-only session cookie whenever the site is on HTTPS. It is
        // left off on plain HTTP, where a secure cookie would never be sent
        // back and nobody could log in.
        $secureCookie = str_starts_with(strtolower($appUrl), 'https://') ? 'true' : 'false';
        $env = strtr($env, [
            '__APP_NAME__' => env_quote($appName),
            '__APP_URL__' => env_quote($appUrl),
            '__DB_HOST__' => env_quote($dbHost),
            '__DB_PORT__' => env_quote($dbPort),
            '__DB_DATABASE__' => env_quote($dbName),
            '__DB_USERNAME__' => env_quote($dbUser),
            '__DB_PASSWORD__' => env_quote($dbPass),
            '__MAIL_FROM_ADDRESS__' => env_quote($adminEmail ?: 'noreply@'.(parse_url($appUrl, PHP_URL_HOST) ?: 'localhost')),
            '__MAIL_FROM_NAME__' => env_quote($appName),
            '__SESSION_SECURE_COOKIE__' => $secureCookie,
        ])."\n";

        if (@file_put_contents(ENV_FILE, $env) === false) {
            $errors[] = 'Could not write .env — check the permissions of the project folder.';
        } else {
            @chmod(ENV_FILE, 0644);
            $summary[] = 'Wrote .env (APP_ENV=production, APP_DEBUG=false)';
        }
    }

    // Boot Laravel and run the install steps --------------------------------
    if (! $errors) {
        try {
            clear_bootstrap_cache();

            require ROOT.'/build/vendor/autoload.php';
            $app = require ROOT.'/bootstrap/app.php';
            $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
            $kernel->bootstrap();

            // Application key
            $kernel->call('key:generate', ['--force' => true]);
            $summary[] = 'Generated APP_KEY';

            // Schema
            $code = $kernel->call('migrate', ['--force' => true]);
            if ($code !== 0) {
                throw new RuntimeException('migrate failed: '.trim($kernel->output()));
            }
            $summary[] = 'Ran all migrations';

            if ($seedDemo) {
                $summary[] = 'Seeding demo data — this is the slow step, please wait…';
                flush_now();

                $code = $kernel->call('db:seed', ['--force' => true]);
                if ($code !== 0) {
                    throw new RuntimeException('db:seed failed: '.trim($kernel->output()));
                }
                $summary[] = 'Seeded demo data (2 properties, demo logins)';
            } else {
                // Create the first property and its administrator.
                $hotel = App\Models\Hotel::firstOrCreate(
                    ['code' => $hotelCode],
                    [
                        'name' => $hotelName,
                        'business_date' => now()->toDateString(),
                        'country' => 'Ghana',
                        'currency' => $currency,
                        'currency_symbol' => $currencySymbol,
                        'timezone' => $timezone,
                    ]
                );

                App\Services\HotelProvisioner::provision($hotel);
                $summary[] = "Created property {$hotel->code} — {$hotel->name}";

                $admin = App\Models\User::firstOrNew(['username' => $adminUser]);
                $admin->name = $adminName;
                $admin->email = $adminEmail ?: null;
                $admin->password = $adminPass;   // 'hashed' cast on the model
                $admin->is_active = true;
                $admin->hotel_id = $hotel->id;
                $admin->is_super_admin = true;
                $admin->save();
                $summary[] = "Created administrator {$admin->username}";
            }

            // Upload and runtime folders the app writes into. public/uploads
            // and storage/app/backups are gitignored, so they are absent from
            // a fresh upload. (They were already created before the checks.)
            foreach (RUNTIME_DIRS as $d) {
                $abs = ROOT.'/'.$d;
                if (! is_dir($abs)) {
                    @mkdir($abs, 0755, true);
                }
            }
            $summary[] = 'Prepared writable folders (uploads, storage, cache)';

            // Cache the config only if it builds cleanly.
            $cacheNote = [];
            if ($kernel->call('config:cache') === 0) {
                $cacheNote[] = 'config';
            } else {
                $kernel->call('config:clear');
            }
            if ($kernel->call('route:cache') === 0) {
                $cacheNote[] = 'routes';
            } else {
                $kernel->call('route:clear');
            }
            if ($kernel->call('view:cache') === 0) {
                $cacheNote[] = 'views';
            }
            $summary[] = $cacheNote
                ? 'Cached: '.implode(', ', $cacheNote)
                : 'Skipped caching (the app runs fine uncached)';

            // Lock, so the installer cannot be replayed.
            @file_put_contents(LOCK_FILE, 'installed '.date('c').PHP_EOL);
            $done = true;
        } catch (Throwable $e) {
            $errors[] = get_class($e).': '.$e->getMessage()
                .'  (at '.str_replace(ROOT, '', $e->getFile()).':'.$e->getLine().')';
            $errors[] = 'Nothing is locked — correct the problem and submit again.';
        }
    }
}

function flush_now(): void
{
    if (ob_get_level() > 0) {
        @ob_flush();
    }
    @flush();
}

$phpBin = guess_php_bin();
$formValue = fn (string $k, string $d = '') => h((string) ($_POST[$k] ?? $d));

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Install · Nowills PMS</title>
<style>
  :root { color-scheme: light; }
  * { box-sizing: border-box; }
  body { margin:0; font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; background:#f1f5f9; color:#0f172a; }
  .wrap { max-width:900px; margin:0 auto; padding:28px 18px 60px; }
  header { background:#166534; color:#fff; padding:20px 18px; }
  header .in { max-width:900px; margin:0 auto; }
  h1 { margin:0; font-size:20px; }
  header p { margin:6px 0 0; opacity:.9; font-size:13px; }
  .card { background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:18px; margin:16px 0; }
  .card h2 { margin:0 0 12px; font-size:16px; }
  table { width:100%; border-collapse:collapse; font-size:14px; }
  td { padding:6px 8px; border-bottom:1px solid #f1f5f9; vertical-align:top; }
  td.k { width:44%; }
  .ok  { color:#15803d; font-weight:600; }
  .bad { color:#b91c1c; font-weight:600; }
  .warn{ color:#b45309; font-weight:600; }
  .hint { color:#64748b; font-size:12.5px; display:block; margin-top:2px; }
  .grid { display:grid; grid-template-columns:1fr 1fr; gap:12px 16px; }
  @media (max-width:640px){ .grid { grid-template-columns:1fr; } }
  label { display:block; font-size:13px; font-weight:600; margin-bottom:4px; }
  input[type=text],input[type=url],input[type=email],input[type=password],input[type=number],select {
    width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:7px; font-size:14px; background:#fff;
  }
  .full { grid-column:1/-1; }
  .btn { display:inline-block; background:#166534; color:#fff; border:0; border-radius:8px; padding:11px 20px; font-size:15px; font-weight:600; cursor:pointer; }
  .btn[disabled] { background:#94a3b8; cursor:not-allowed; }
  .note { background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:12px; font-size:13.5px; }
  .danger { background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:12px; font-size:13.5px; }
  .err { background:#fef2f2; border:1px solid #fecaca; color:#7f1d1d; border-radius:8px; padding:12px; margin:12px 0; font-size:14px; }
  .win { background:#f0fdf4; border:1px solid #bbf7d0; color:#14532d; border-radius:8px; padding:12px; margin:12px 0; font-size:14px; }
  code { background:#f1f5f9; padding:2px 6px; border-radius:5px; font-size:13px; }
  pre { background:#0f172a; color:#e2e8f0; padding:12px; border-radius:8px; overflow:auto; font-size:12.5px; }
  h3 { font-size:14px; margin:18px 0 8px; }
</style>
</head>
<body>
<header><div class="in">
  <h1>Nowills PMS — installer</h1>
  <p>Laravel <?= h(PHP_VERSION) ?> · <?= h(guess_url()) ?></p>
</div></header>

<div class="wrap">

<?php if (installed()): ?>
  <div class="card">
    <h2 class="ok">Already installed</h2>
    <p>This project is installed, so the installer has locked itself and will not run again.</p>
    <div class="danger"><strong>Delete <code>public/install.php</code> now.</strong> It is no longer needed and should not be left on a live server.</div>
    <p style="margin-top:14px">To reinstall from scratch, delete <code>storage/framework/installed.lock</code> and this file's lock, then reload. Your database is untouched by the installer.</p>
  </div>

<?php elseif ($done): ?>
  <div class="card">
    <h2 class="ok">Installation complete</h2>
    <?php foreach ($summary as $s): ?><div class="win"><?= h($s) ?></div><?php endforeach; ?>

    <h3>Next: delete this file</h3>
    <div class="danger">
      In cPanel File Manager go to <code>public/</code> and <strong>delete <code>install.php</code></strong>.
      Anyone who loads it can read your server configuration.
    </div>

    <h3>Log in</h3>
    <p>Open <a href="<?= h($formValue('app_url', guess_url())) ?>"><?= h($formValue('app_url', guess_url())) ?></a> and sign in with:</p>
    <table>
      <tr><td class="k">Hotel ID</td><td><code><?= $formValue('hotel_code') ?></code></td></tr>
      <tr><td class="k">Username</td><td><code><?= $formValue('admin_user') ?></code></td></tr>
      <tr><td class="k">Password</td><td>the one you just chose</td></tr>
    </table>

    <h3>Then set up the scheduler (1 cron entry)</h3>
    <p>cPanel → <em>Cron Jobs</em> → <em>Add New Cron Job</em>, once per minute:</p>
    <pre><?= h('* * * * * '.$phpBin.' '.ROOT.'/artisan schedule:run >> /dev/null 2>&1') ?></pre>
    <p class="hint">If that PHP path does not exist on your host, use <code>/usr/local/bin/php</code> or ask your host for the CLI PHP path.</p>

    <h3>Finish the production checklist</h3>
    <p>See <code>docs/INSTALL-CPANEL.md</code> — enable HTTPS, change the demo passwords if you seeded data, and store backups off-site.</p>
  </div>

<?php else: ?>

  <div class="card">
    <h2>1 · Server check</h2>
    <table>
      <?php foreach ($checks as $c): ?>
        <tr>
          <td class="k">
            <?= h($c['label']) ?>
            <?php if (! empty($c['optional'])): ?> <span class="hint" style="display:inline">(optional)</span><?php endif; ?>
          </td>
          <td>
            <span class="<?= $c['ok'] ? 'ok' : (empty($c['optional']) ? 'bad' : 'warn') ?>">
              <?= $c['ok'] ? 'OK' : 'FAIL' ?>
            </span>
            — <?= h($c['detail']) ?>
            <?php if (! $c['ok']): ?><span class="hint"><?= h($c['hint']) ?></span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
    <?php if ($checksOk): ?>
      <div class="win" style="margin-top:14px">All required checks passed<?= $blocking ? '' : ' — you can install.' ?></div>
    <?php else: ?>
      <div class="err" style="margin-top:14px">Fix the <strong>FAIL</strong> rows above, then reload this page.</div>
    <?php endif; ?>
  </div>

  <?php foreach ($errors as $e): ?><div class="err"><?= h($e) ?></div><?php endforeach; ?>

  <form method="post" class="card">
    <input type="hidden" name="action" value="install">
    <h2>2 · Database &amp; site</h2>
    <div class="note" style="margin-bottom:14px">
      Create the database first: cPanel → <em>MySQL® Databases</em>. cPanel prefixes names with your account
      (e.g. <code>myacct_pms</code>), so copy the <strong>full</strong> name it shows. Then add the user to the
      database with <em>ALL PRIVILEGES</em>.
    </div>
    <div class="grid">
      <div><label>Database name</label><input type="text" name="db_name" value="<?= $formValue('db_name') ?>" placeholder="myacct_pms" required></div>
      <div><label>Database user</label><input type="text" name="db_user" value="<?= $formValue('db_user') ?>" placeholder="myacct_pmsuser" required></div>
      <div><label>Database password</label><input type="password" name="db_pass" value=""></div>
      <div><label>Database host</label><input type="text" name="db_host" value="<?= $formValue('db_host', 'localhost') ?>" required></div>
      <div><label>Port</label><input type="number" name="db_port" value="<?= $formValue('db_port', '3306') ?>"></div>
      <div><label>Site URL</label><input type="url" name="app_url" value="<?= $formValue('app_url', guess_url()) ?>" required></div>
      <div class="full"><label>Property name</label><input type="text" name="app_name" value="<?= $formValue('app_name', 'Nowills PMS') ?>"></div>
    </div>

    <h2 style="margin-top:22px">3 · Your first property</h2>
    <div class="grid">
      <div><label>Hotel ID (used to log in)</label><input type="text" name="hotel_code" value="<?= $formValue('hotel_code', 'NOWILLS') ?>" placeholder="NOWILLS" required></div>
      <div><label>Property name</label><input type="text" name="hotel_name" value="<?= $formValue('hotel_name') ?>" placeholder="Nowills Grand Hotel" required></div>
      <div><label>Currency code</label><input type="text" name="currency" value="<?= $formValue('currency', 'GHS') ?>"></div>
      <div><label>Currency symbol</label><input type="text" name="currency_symbol" value="<?= $formValue('currency_symbol', 'GH₵') ?>"></div>
      <div class="full"><label>Time zone</label><input type="text" name="timezone" value="<?= $formValue('timezone', 'Africa/Accra') ?>"></div>
    </div>

    <h2 style="margin-top:22px">4 · Administrator account</h2>
    <div class="grid">
      <div><label>Full name</label><input type="text" name="admin_name" value="<?= $formValue('admin_name', 'Administrator') ?>"></div>
      <div><label>Username</label><input type="text" name="admin_user" value="<?= $formValue('admin_user', 'admin') ?>" required></div>
      <div><label>E-mail</label><input type="email" name="admin_email" value="<?= $formValue('admin_email') ?>"></div>
      <div><label>Password (min 8 characters)</label><input type="password" name="admin_pass" required></div>
    </div>

    <h2 style="margin-top:22px">5 · Demo data (optional)</h2>
    <div class="note">
      <label style="font-weight:400;display:flex;gap:8px;align-items:flex-start;margin:0">
        <input type="checkbox" name="seed_demo" value="1" <?= ! empty($_POST['seed_demo']) ? 'checked' : '' ?> style="width:auto;margin-top:3px">
        <span>
          <strong>Install the demo dataset</strong> — two properties, ~200 reservations, POS, ledgers and staff
          logins. Ideal for a trial or training. <strong>Do not use it for a live hotel:</strong> it contains
          fake guests and known demo passwords.
          <span class="hint">This is the slowest step — on shared hosting it can take a minute or more. Leave it
          unchecked for a clean, fast install of just your own property.</span>
        </span>
      </label>
    </div>

    <p style="margin-top:20px">
      <button class="btn" type="submit" <?= $checksOk ? '' : 'disabled' ?>>Install Nowills PMS</button>
    </p>
  </form>

  <div class="card">
    <h2>Before you start</h2>
    <ul style="margin:0;padding-left:20px;font-size:14px">
      <li>Upload the whole project (including <code>build/</code>) — this app keeps its Composer packages in <code>build/vendor</code>, so no Composer is needed on the server.</li>
      <li>Read <code>docs/INSTALL-CPANEL.md</code> for the document-root and cron setup.</li>
      <li>Delete <code>public/install.php</code> when you are done.</li>
    </ul>
  </div>

<?php endif; ?>
</div>
</body>
</html>
