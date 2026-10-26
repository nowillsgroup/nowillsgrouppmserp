<?php

/**
 * Nowills PMS — static system audit.
 *
 *   deploy/dev/bin/php deploy/dev/audit/system_audit.php [--json]
 *
 * One process, no HTTP: parses every PHP file, reads the route table, the
 * migrations and the model tree, and reports the classes of defect that a
 * green page sweep cannot see. Each finding carries a severity and the file
 * and line where it lives, so the report can be acted on rather than admired.
 *
 * S = severe (breaks the app, leaks data or blocks a release)
 * H = high     (wrong behaviour in a real scenario, or a production trap)
 * M = medium   (latent: bites on a different host / version / dataset)
 * L = low      (tidiness, documentation, dead code)
 */

$root = dirname(__DIR__, 3);
require $root.'/build/vendor/autoload.php';
$json = in_array('--json', $argv, true);

$findings = [];
$counts = ['S' => 0, 'H' => 0, 'M' => 0, 'L' => 0];

function add(string $sev, string $area, string $what, string $where = '', string $fix = ''): void
{
    global $findings, $counts;
    $findings[] = compact('sev', 'area', 'what', 'where', 'fix');
    $counts[$sev]++;
}

function walk(string $dir, callable $fn, string $ext = 'php'): void
{
    if (! is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === $ext) {
            $fn($f->getPathname(), $f);
        }
    }
}

function rel(string $p): string
{
    global $root;

    return ltrim(str_replace($root, '', $p), '/');
}

/* ── 1. Every PHP file parses ──────────────────────────────────────────────── */
$phpFiles = 0;
foreach (['app', 'routes', 'config', 'database', 'public', 'bootstrap', 'deploy'] as $d) {
    walk($root.'/'.$d, function ($path) use (&$phpFiles) {
        if (str_contains($path, '/node_modules/')) {
            return;
        }
        $phpFiles++;
        try {
            token_get_all(file_get_contents($path), TOKEN_PARSE);
        } catch (Throwable $e) {
            add('S', 'Syntax', 'does not parse: '.$e->getMessage(), rel($path).':'.$e->getLine(), 'Fix the syntax; nothing in this file can run.');
        }
    });
}

/* ── 2. Blade compiles ─────────────────────────────────────────────────────── */
$blade = 0;
walk($root.'/resources/views', function ($path) use (&$blade) {
    $blade++;
    $s = file_get_contents($path);
    // Stack-walk the directives Blade actually compiles into PHP. A plain
    // open/close count misses the commonest fatal: a stray @else whose @if was
    // already closed (that is what 500'd /robita/devices in v7.56).
    $s = preg_replace('/\{\{--.*?--\}\}/s', '', $s);   // Blade comments
    $s = preg_replace('/@php.*?@endphp/s', '', $s);    // raw PHP blocks
    preg_match_all('/(?<!@)@(if|elseif|else|endif|unless|endunless|foreach|endforeach|forelse|empty|endforelse|for|endfor|while|endwhile|switch|case|default|endswitch|hasSection|sectionMissing|isset|endisset|endempty)(\()?/', $s, $mm, PREG_SET_ORDER);
    $stack = [];
    $closers = ['endif' => 'if', 'endunless' => 'unless', 'endforeach' => 'foreach', 'endforelse' => 'forelse',
        'endfor' => 'for', 'endwhile' => 'while', 'endswitch' => 'switch'];
    $branches = ['if', 'unless', 'hasSection', 'sectionMissing', 'isset']; // @endif / @endisset
    $closers['endisset'] = 'isset';
    $closers['endempty'] = 'empty';
    foreach ($mm as $hit) {
        $d = $hit[1];
        $paren = ! empty($hit[2]);
        // `@empty($x) … @endempty` is a block; a bare `@empty` inside @forelse is its separator
        $openers = ['if', 'unless', 'foreach', 'forelse', 'for', 'while', 'switch', 'hasSection', 'sectionMissing', 'isset'];
        if ($d === 'empty' && $paren) {
            $stack[] = 'empty';
            continue;
        }
        if (in_array($d, $openers, true)) {
            $stack[] = $d;
        } elseif (in_array($d, ['elseif', 'else'], true)) {
            $top = end($stack);
            if (! in_array($top, $branches, true)) {
                add('H', 'Views', "@{$d} has no open @if — the compiled view is a PHP syntax error (500 on render)", rel($path), 'Balance the branch or delete the stray @else.');
            }
        } elseif ($d === 'empty') {
            if (end($stack) !== 'forelse') {
                add('H', 'Views', '@empty outside @forelse', rel($path), 'Fix the loop.');
            }
        } elseif ($d === 'case' || $d === 'default') {
            if (end($stack) !== 'switch') {
                add('M', 'Views', "@{$d} outside @switch", rel($path), 'Check the surrounding block.');
            }
        } elseif (isset($closers[$d])) {
            $top = array_pop($stack);
            $ok = $closers[$d] === 'if' ? in_array($top, $branches, true) : $top === $closers[$d];
            if (! $ok) {
                add('H', 'Views', "@{$d} closes ".($top ? "@{$top}" : 'nothing').' — unbalanced blocks', rel($path), 'Balance the directives.');
            }
        }
    }
    if ($stack) {
        add('H', 'Views', 'unclosed Blade block(s): @'.implode(', @', $stack), rel($path), 'Add the matching @end… directive.');
    }
}, 'php');

/* ── 3. Route table ────────────────────────────────────────────────────────── */
$routeJson = null;
$args = array_values(array_filter($argv, fn ($a) => ! str_starts_with((string) $a, '--')));
foreach (array_filter([$args[1] ?? null, __DIR__.'/.routes.json', '/tmp/routes.json']) as $candidate) {
    if ($candidate && is_file($candidate)) {
        $routeJson = $candidate;
        break;
    }
}
$routes = $routeJson ? (json_decode((string) file_get_contents($routeJson), true) ?: []) : [];
if (! $routes) {
    add('M', 'Routing', 'no route table supplied — run: php artisan route:list --json > /tmp/routes.json first', '');
}
$seen = [];
$names = [];
foreach ($routes as $r) {
    $methods = is_array($r['method'] ?? null) ? $r['method'] : explode('|', (string) ($r['method'] ?? 'GET'));
    foreach ($methods as $m) {
        $key = $m.' '.$r['uri'];
        if (isset($seen[$key])) {
            add('H', 'Routing', "duplicate route {$key} (first: {$seen[$key]}, again: ".($r['name'] ?? '—').')', 'routes/');
        } else {
            $seen[$key] = $r['name'] ?? '—';
        }
    }
    if (! empty($r['name'])) {
        $names[$r['name']][] = $r['uri'];
    }
}
foreach ($names as $n => $uris) {
    if (count($uris) > 1) {
        add('M', 'Routing', "route name reused: {$n} → ".implode(', ', $uris), 'routes/');
    }
}

/* ── 4. env() outside config/ ───────────────────────────────────────────────
 * The single most common Laravel production trap: once `config:cache` has run
 * (the installer does it), env() returns null everywhere except config/, so a
 * feature silently switches off. */
walk($root.'/app', function ($path) {
    $lines = file($path);
    foreach ($lines as $i => $ln) {
        if (preg_match('/\benv\s*\(/', $ln) && ! str_contains($ln, '//')) {
            // ignores `env` used as a method name on an object, e.g. $x->env(
            if (preg_match('/(?<![\w>$:])env\s*\(\s*[\'"]/', $ln)) {
                add('H', 'Config', 'env() called outside config/ — returns null once config is cached', rel($path).':'.($i + 1), 'Move the value into a config file (config/pms.php) and read it with config().');
            }
        }
    }
});

/* ── 4b. PHP 8.4 implicit-nullable parameters ───────────────────────────────
 * `Foo $x = null` is deprecated in 8.4 ("the explicit nullable type must be
 * used instead"). Only real parameter lists are considered: anchoring each
 * parameter at its start/after a comma is what keeps untyped `$x = null` out. */
walk($root.'/app', function ($path) {
    $src = file_get_contents($path);
    if (! str_contains($src, '= null')) {
        return;
    }
    $re = '/(?:^|,)\s*(\??)([A-Za-z_\\\\][\w\\\\|]*)\s+(&?\$[A-Za-z_]\w*)\s*=\s*null/';
    foreach (explode('function', $src) as $chunk) {
        $open = strpos($chunk, '(');
        if ($open === false) {
            continue;
        }
        $depth = 0;
        $params = null;
        for ($i = $open, $n = strlen($chunk); $i < $n; $i++) {
            if ($chunk[$i] === '(') {
                $depth++;
            } elseif ($chunk[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    $params = substr($chunk, $open + 1, $i - $open - 1);
                    break;
                }
            }
        }
        if ($params === null || ! preg_match_all($re, $params, $mm, PREG_SET_ORDER)) {
            continue;
        }
        foreach ($mm as $m) {
            if ($m[1] === '?' || stripos($m[2], 'null') !== false) {
                continue;
            }
            add('L', 'Runtime', "implicitly nullable parameter {$m[2]} {$m[3]} = null — deprecated in PHP 8.4", rel($path), "Write ?{$m[2]} {$m[3]} = null.");
        }
    }
}, 'php');

/* ── 5. Migrations ─────────────────────────────────────────────────────────── */
$migDir = $root.'/database/migrations';
$migFiles = glob($migDir.'/*.php') ?: [];
$createMigrations = 0;
foreach ($migFiles as $f) {
    $s = file_get_contents($f);
    $name = basename($f);
    if (! preg_match('/function\s+down\s*\(/', $s)) {
        add('L', 'Migrations', 'no down() — cannot be rolled back', 'database/migrations/'.$name);
    }
    if (preg_match_all('/Schema::create\(\s*[\'"]([a-z0-9_]+)[\'"]/', $s, $m)) {
        foreach ($m[1] as $table) {
            $createMigrations++;
            // main claimed hasTable guards were added: verify for matching table
            if (! preg_match('/hasTable\(\s*[\'"]'.preg_quote($table, '/').'[\'"]/', $s)) {
                add('M', 'Migrations', "Schema::create('{$table}') has no hasTable() guard — re-running against an existing database fails", 'database/migrations/'.$name, 'Wrap in if (! Schema::hasTable(...)).');
            }
        }
    }
    // MySQL identifiers are capped at 64 characters: a generated index name
    // longer than that fails on MySQL while SQLite accepts it silently.
    // Migration files often create several tables: the index belongs to the
    // *nearest preceding* Schema::create, so match on byte offsets.
    $creates = [];
    if (preg_match_all('/Schema::(?:create|table)\(\s*[\'"]([a-z0-9_]+)[\'"]/', $s, $cm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        foreach ($cm as $c) {
            $creates[] = [$c[1][1], $c[1][0]]; // offset, table
        }
    }
    if (preg_match_all('/(?:unique|index)\(\s*\[([^\]]+)\](?:\s*,\s*[\'"]([^\'"]+)[\'"])?/', $s, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($mm as $setFull) {
            $offset = $setFull[0][1];
            $set = array_map(fn ($g) => is_array($g) ? $g[0] : $g, $setFull);
            if (! empty($set[2])) {
                continue; // explicit name — only length matters
            }
            $table = null;
            foreach ($creates as [$cOffset, $cTable]) {
                if ($cOffset < $offset) {
                    $table = $cTable; // keep the last create before this index
                }
            }
            $cols = array_map(fn ($c) => trim($c, " '\""), explode(',', $set[1]));
            $generated = strtolower($table.'_'.implode('_', $cols).'_'.(str_contains($set[0], 'unique') ? 'unique' : 'index'));
            if ($table && strlen($generated) > 64) {
                add('H', 'Migrations', "generated index name {$generated} is ".strlen($generated).' chars — MySQL refuses names over 64', 'database/migrations/'.$name, 'Pass an explicit short name as the second argument.');
            }
        }
    }
}
if (! $migFiles) {
    add('S', 'Migrations', 'no migrations found', 'database/migrations');
}

/* ── 6. Tenant isolation: hotel_id without the global scope ────────────────── */
$models = [];
foreach (glob($root.'/app/Models/*.php') ?: [] as $f) {
    $s = file_get_contents($f);
    $class = basename($f, '.php');
    $hasTrait = preg_match('/use\s+[^;]*BelongsToHotel/', $s) === 1;
    $hotelId = preg_match('/[\'"](hotel_id)[\'"]/', $s) === 1 || preg_match('/hotel_id/', $s) === 1;
    $models[$class] = ['trait' => $hasTrait, 'hotel' => $hotelId, 'file' => $f];
}
// Which tables actually carry hotel_id?
$dbTables = [];
try {
    $pdo = new PDO('sqlite:'.$root.'/database/database.sqlite');
    foreach ($pdo->query("select name from sqlite_master where type='table'") as $row) {
        $t = $row['name'];
        $cols = $pdo->query('pragma table_info("'.$t.'")')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $c) {
            if ($c['name'] === 'hotel_id') {
                $dbTables[$t] = true;
            }
        }
    }
} catch (Throwable $e) {
    add('M', 'Database', 'could not open the dev database for static cross-checks: '.$e->getMessage(), 'database/database.sqlite');
}
$unscoped = [];
foreach ($models as $class => $m) {
    if ($m['trait'] || ! $m['hotel']) {
        continue;
    }
    $table = \Illuminate\Support\Str::snake(\Illuminate\Support\Str::pluralStudly($class));
    if (isset($dbTables[$table])) {
        $unscoped[] = $class.' ('.$table.')';
    }
}
if ($unscoped) {
    add('H', 'Tenant isolation', count($unscoped).' hotel-scoped model(s) without the BelongsToHotel scope: '.implode(', ', array_slice($unscoped, 0, 12)).(count($unscoped) > 12 ? ' …' : ''), 'app/Models', 'Add the trait, or justify the exception (a hotel can read another hotel\'s rows through it).');
}

/* ── 7. Unescaped Blade output ─────────────────────────────────────────────── */
$raw = 0;
walk($root.'/resources/views', function ($path) use (&$raw) {
    $s = file_get_contents($path);
    if (preg_match_all('/\{!!\s*(.+?)\s*!!\}/s', $s, $m)) {
        foreach ($m[1] as $expr) {
            // status_badge()/badge helpers build vetted HTML by design
            if (preg_match('/badge|icon|<x-|nl2br|money|number_format|e\(/', $expr)) {
                continue;
            }
            $raw++;
        }
    }
}, 'php');
if ($raw > 0) {
    add('M', 'Views', "{$raw} raw {!! !!} output(s) that are not a known-safe helper", 'resources/views', 'Confirm each one cannot carry guest-supplied text (XSS).');
}

/* ── 8. Left-over debugging ────────────────────────────────────────────────── */
$debug = [];
foreach (['app', 'routes', 'resources/views'] as $d) {
    walk($root.'/'.$d, function ($path) use (&$debug) {
        foreach (file($path) as $i => $ln) {
            if (preg_match('/(?<![\w>])(dd|dump|var_dump|print_r)\s*\(/', $ln) && ! str_contains($ln, '//')) {
                $debug[] = rel($path).':'.($i + 1);
            }
        }
    });
}
if ($debug) {
    add('H', 'Debugging', count($debug).' debug call(s) left in shipped code: '.implode(', ', array_slice($debug, 0, 8)), '', 'Remove — dd() prints internals to the browser.');
}

/* ── 9. Secrets in the repository ─────────────────────────────────────────── */
$secretPatterns = [
    'private key' => '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/',
    'AWS key' => '/AKIA[0-9A-Z]{16}/',
    'Stripe live key' => '/sk_live_[0-9a-zA-Z]{20,}/',
    'Paystack live key' => '/sk_live_[a-z0-9]{20,}/',
    'generic bearer' => '/(?:api[_-]?secret|access[_-]?token)\s*[=:]\s*[\'"][A-Za-z0-9_\-]{24,}[\'"]/i',
];
$hits = [];
foreach (['app', 'config', 'database', 'resources', 'routes', 'public', 'deploy', 'docs'] as $d) {
    walk($root.'/'.$d, function ($path) use ($secretPatterns, &$hits) {
        if (str_contains($path, '/node_modules/') || str_ends_with($path, 'system_audit.php')) {
            return;
        }
        $s = @file_get_contents($path) ?: '';
        foreach ($secretPatterns as $label => $rx) {
            if (preg_match($rx, $s)) {
                $hits[] = rel($path).' ('.$label.')';
            }
        }
    });
}
if ($hits) {
    add('S', 'Secrets', 'possible credential material committed: '.implode(', ', array_slice(array_unique($hits), 0, 6)), '', 'Rotate and remove; history keeps it forever.');
}
foreach (['.env', '.env.production', '.env.backup'] as $f) {
    if (is_file($root.'/'.$f) && trim((string) shell_exec('git -C '.escapeshellarg($root).' ls-files --error-unmatch '.escapeshellarg($f).' 2>/dev/null')) !== '') {
        add('S', 'Secrets', $f.' is tracked by git', $f, 'Remove from the index and rotate APP_KEY + DB password.');
    }
}

/* ── 10. Raw SQL built from variables ─────────────────────────────────────── */
$rawSql = [];
foreach (['app'] as $d) {
    walk($root.'/'.$d, function ($path) use (&$rawSql) {
        foreach (file($path) as $i => $ln) {
            if (preg_match('/DB::(?:select|statement|unprepared|raw)\s*\(\s*["\'].*\$/', $ln)) {
                $rawSql[] = rel($path).':'.($i + 1);
            }
        }
    });
}
if ($rawSql) {
    add('M', 'SQL', count($rawSql).' raw SQL statement(s) built with interpolation: '.implode(', ', array_slice($rawSql, 0, 8)), '', 'Use bindings; check whether the interpolated value is user input.');
}

/* ── 11. Route authorisation coverage ───────────────────────────────────────
 * A write route behind `auth` but with no `perm:` middleware and no ability
 * middleware is a candidate for a missing authorisation check. Public writes
 * (login, booking engine, webhooks, kiosk) are listed separately — they are
 * meant to be unauthenticated. */
$authWrites = [];
$publicWrites = [];
foreach ($routes as $r) {
    $methods = is_array($r['method'] ?? null) ? $r['method'] : explode('|', (string) ($r['method'] ?? ''));
    $writes = array_intersect($methods, ['POST', 'PUT', 'PATCH', 'DELETE']);
    if (! $writes) {
        continue;
    }
    $mw = $r['middleware'] ?? [];
    $mw = is_array($mw) ? implode(' ', $mw) : (string) $mw;
    $uri = $r['uri'] ?? '';
    if (! preg_match('/\bauth\b/', $mw)) {
        $publicWrites[] = $uri;
        continue;
    }
    if (preg_match('/perm:|can:|ability|role:|twofactor|tenant/', $mw) && preg_match('/perm:/', $mw)) {
        continue;
    }
    $authWrites[] = $uri.($r['name'] ? ' ('.$r['name'].')' : '');
}
$authWrites = array_values(array_unique($authWrites));
if ($authWrites) {
    add('H', 'Authorisation', count($authWrites).' authenticated write route(s) with no perm: middleware: '.implode(', ', array_slice($authWrites, 0, 12)).(count($authWrites) > 12 ? ' …' : ''), 'routes/web.php', 'Add perm:, or confirm the controller checks ownership.');
}

/* ── 12. Password / token handling ────────────────────────────────────────── */
walk($root.'/app', function ($path) {
    $s = file_get_contents($path);
    if (preg_match('/(?:password|passwd)\s*[=,)]\s*(?:md5|sha1)\s*\(/i', $s)) {
        add('S', 'Security', 'a password hashed with md5()/sha1()', rel($path), 'Use Hash::make (bcrypt/argon2).');
    }
    if (preg_match('/[\'"]password[\'"]\s*=>\s*[^\n]*\$request->/', $s) || preg_match('/\$request->input\([\'"]password[\'"]\)/', $s)) {
        // fine with the hashed cast, flag only when stored raw
        if (preg_match('/(?:password|passwd)\s*=\s*\$request/', $s) && ! str_contains($s, 'Hash::')) {
            add('M', 'Security', 'a request password assigned directly to a model', rel($path), 'Confirm the model casts it as hashed.');
        }
    }
});

/* ── 13. Documentation drift ──────────────────────────────────────────────── */
$version = null;
if (preg_match("/'version'\s*=>\s*'([0-9.]+)'/", file_get_contents($root.'/config/pms.php'), $m)) {
    $version = $m[1];
}
$docVersions = [];
foreach (glob($root.'/docs/*.md') ?: [] as $f) {
    $text = file_get_contents($f);
    // a doc is only drifted if it names a version and never mentions the current one
    if (preg_match('/\bVersion ([0-9]+\.[0-9]+\.[0-9]+)/', $text, $m) && ! str_contains($text, (string) $version)) {
        $docVersions[basename($f)] = $m[1];
    }
}
foreach ($docVersions as $f => $v) {
    if ($v !== $version) {
        add('L', 'Documentation', "{$f} states version {$v}, config('pms.version') is {$version}", 'docs/'.$f);
    }
}

/* ── 14. Files the install guides tell you to use ─────────────────────────── */
foreach (['public/install.php', 'artisan', 'build/vendor/autoload.php', '.htaccess', 'public/.htaccess'] as $f) {
    if (! is_file($root.'/'.$f)) {
        add('S', 'Packaging', "{$f} is missing from the repository — the documented install will fail", $f);
    }
}

/* ── Output ───────────────────────────────────────────────────────────────── */
if ($json) {
    echo json_encode(['findings' => $findings, 'counts' => $counts, 'php' => $phpFiles, 'blade' => $blade, 'routes' => count($routes), 'migrations' => count($migFiles)], JSON_PRETTY_PRINT)."\n";
    exit($counts['S'] > 0 ? 1 : 0);
}

$order = ['S' => 'SEVERE', 'H' => 'HIGH', 'M' => 'MEDIUM', 'L' => 'LOW'];
echo "\n=== Nowills PMS static audit ===\n";
echo "PHP files parsed: {$phpFiles} · Blade views scanned: {$blade} · routes: ".count($routes).' · migrations: '.count($migFiles)."\n";
foreach ($order as $sev => $label) {
    $group = array_values(array_filter($findings, fn ($f) => $f['sev'] === $sev));
    if (! $group) {
        continue;
    }
    echo "\n--- {$label} (".count($group).") ---\n";
    foreach ($group as $f) {
        echo '  · ['.$f['area'].'] '.$f['what'].($f['where'] ? '  @ '.$f['where'] : '')."\n";
        if ($f['fix']) {
            echo '      fix: '.$f['fix']."\n";
        }
    }
}
echo "\nTotals: {$counts['S']} severe · {$counts['H']} high · {$counts['M']} medium · {$counts['L']} low\n";

exit($counts['S'] > 0 ? 1 : 0);
