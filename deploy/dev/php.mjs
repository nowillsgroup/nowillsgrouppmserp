#!/usr/bin/env node
// php — WASM PHP 8.5 CLI shim (runs under Bun).
//
// Usage (subset of the real php CLI):
//   php artisan <command> [options]     (cwd is the repo root)
//   php <script.php> [args...]
//   php -f <script.php> [args...]
//   php -r '<code>'
//   php -l <script.php>   (syntax check)
//   php -v | php -m
import { createPhp, runCli, APP_MOUNT } from './lib.mjs';

const args = process.argv.slice(2);

function finish(result) {
  // The wasm SAPI does not strip entry-script shebangs — filter the banner.
  const out = (result.stdout ?? '').replace(/^#![^\n]*\n/, '');
  process.stdout.write(out);
  process.stderr.write(result.stderr ?? '');
  process.exit(result.exitCode ?? 0);
}

async function main() {
  if (args.length === 0) {
    process.stderr.write('Usage: php [-l | -r code | -f script | script] [args...]\n');
    process.exit(2);
  }

  const php = await createPhp();

  if (args[0] === '-v' || args[0] === '--version') {
    const r = await runCli(php, null, [], {
      code: `<?php echo "PHP " . PHP_VERSION . " (wasm, kirigami build)\\n";`,
    });
    return finish(r);
  }

  if (args[0] === '-m') {
    const r = await runCli(php, null, [], {
      code: `<?php sort($e = get_loaded_extensions()); echo implode("\\n", $e), "\\n";`,
    });
    return finish(r);
  }

  if (args[0] === '-l' || args[0] === '--syntax-check') {
    const target = args[1];
    if (!target) {
      process.stderr.write('Usage: php -l <file.php>\n');
      process.exit(2);
    }
    const fsPath = target.startsWith('/') ? target : `/app/${target}`;
    const r = await runCli(php, null, [], {
      code: `<?php
$__f = ${JSON.stringify(fsPath)};
try {
    token_get_all(file_get_contents($__f), TOKEN_PARSE);
    echo "No syntax errors detected in {$__f}\\n";
    exit(0);
} catch (ParseError $__e) {
    file_put_contents('php://stderr', "PHP Parse error:  " . $__e->getMessage() . " in {$__f} on line " . $__e->getLine() . "\\n");
    exit(255);
}`,
    });
    return finish(r);
  }

  if (args[0] === '-r' || args[0] === '--run') {
    const code = args[1] ?? '';
    const restJson = JSON.stringify(args.slice(2)).replace(/'/g, "\\'");
    const r = await runCli(php, null, args.slice(2), {
      code: `<?php
$argv = array_merge(['-r'], json_decode('${restJson}', true));
$GLOBALS['argv'] = $argv; $GLOBALS['argc'] = count($argv);
$_SERVER['argv'] = $argv; $_SERVER['argc'] = count($argv);
${code}`,
    });
    return finish(r);
  }

  let scriptArg = null;
  let rest = args;
  if (args[0] === '-f' || args[0] === '--file') {
    scriptArg = args[1];
    rest = args.slice(2);
  } else if (args[0].startsWith('-')) {
    process.stderr.write(`Unknown option: ${args[0]}\n`);
    process.exit(2);
  } else {
    scriptArg = args[0];
    rest = args.slice(1);
  }

  // Resolve the script path against the repo root (the mounted cwd).
  let fsScript = scriptArg;
  if (!fsScript.startsWith('/')) {
    fsScript = `${APP_MOUNT}/${fsScript}`;
  }
  fsScript = fsScript.replace(/\/\.\//g, '/');

  const r = await runCli(php, fsScript, rest, { cwd: APP_MOUNT });
  finish(r);
}

main().catch((err) => {
  process.stderr.write(String(err?.stack ?? err) + '\n');
  process.exit(1);
});
