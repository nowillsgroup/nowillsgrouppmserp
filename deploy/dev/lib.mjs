// Shared runtime helpers for the WASM PHP development environment.
// PHP 8.5 (kirigami php-wasm) executed under Bun (JSPI), with the real
// repository mounted into the virtual filesystem at /app via NODEFS.
import { createPHPRuntime } from '@kirigami/php-wasm';
import { createSpawnHandler } from '@php-wasm/util';

export const REPO_ROOT = process.env.PMS_REPO ?? '/home/user/nowillsgrouppmserp';
export const APP_MOUNT = process.env.PMS_MOUNT ?? '/app';

// Real host-process execution for proc_open()/exec()/popen(): translate /app
// paths to the host repo and run the command through Bun. Anything the host
// cannot run fails gracefully with a non-zero exit instead of crashing PHP.
function makeSpawnHandler() {
  const toHost = (p) => (typeof p === 'string' && p.startsWith(APP_MOUNT) ? REPO_ROOT + p.slice(APP_MOUNT.length) : p);
  return createSpawnHandler(async (command, processApi, options) => {
    const argv = (Array.isArray(command) ? command : [command]).map(toHost);
    const stdin = processApi.flushStdin ? processApi.flushStdin() : '';
    const stdinBytes = stdin instanceof Uint8Array ? stdin : new TextEncoder().encode(String(stdin ?? ''));
    let cwd = toHost(options?.cwd ?? APP_MOUNT);
    try {
      await new Promise((r) => setTimeout(r, 1)); // let PHP attach to the streams
      const proc = Bun.spawnSync(argv, {
        cwd,
        stdin: stdinBytes,
        stdout: 'pipe',
        stderr: 'pipe',
        env: { ...process.env, ...(options?.env ?? {}) },
      });
      if (proc.stdout?.length) processApi.stdout(proc.stdout);
      if (proc.stderr?.length) processApi.stderr(proc.stderr);
      await new Promise((r) => setTimeout(r, 1));
      processApi.exit(proc.exitCode ?? 1);
    } catch (e) {
      processApi.stderr(String(e?.message ?? e));
      await new Promise((r) => setTimeout(r, 1));
      processApi.exit(1);
    }
  });
}

const DEFAULT_INI = {
  memory_limit: '2048M',
  max_execution_time: '0',
  error_reporting: 'E_ALL',
  display_errors: '1',
  log_errors: '1',
  variables_order: 'EGPCS',
  // CGI-style argc/argv makes the SAPI set $_SERVER['argv'] to a string,
  // which breaks Symfony's ArgvInput during HTTP boot (LoadEnvironmentVariables).
  register_argc_argv: 'Off',
  upload_max_filesize: '64M',
  post_max_size: '64M',
  // Restores $argv / $_SERVER['argv'] before every entry point so that
  // artisan and Symfony Console see a normal CLI invocation. No-op for
  // web requests (argv.json absent).
  auto_prepend_file: '/tmp/pms-prepend.php',
};

const PREPEND_SRC = `<?php
// Polyfills for extensions missing from the WASM build. Laravel implements its
// own session storage and only needs the contracts to exist.
if (!interface_exists('SessionHandlerInterface', false)) {
    interface SessionHandlerInterface
    {
        public function open(string $path, string $name);
        public function close();
        public function read(string $id);
        public function write(string $id, string $data);
        public function destroy(string $id);
        public function gc(int $max_lifetime);
    }
}
if (!interface_exists('SessionUpdateTimestampHandlerInterface', false)) {
    interface SessionUpdateTimestampHandlerInterface
    {
        public function validateId(string $id);
        public function updateTimestamp(string $id, string $data);
    }
}
if (!class_exists('SessionHandler', false)) {
    class SessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
    {
        public function open(string $path, string $name) { return true; }
        public function close() { return true; }
        public function read(string $id) { return ''; }
        public function write(string $id, string $data) { return true; }
        public function destroy(string $id) { return true; }
        public function gc(int $max_lifetime) { return 0; }
        public function validateId(string $id) { return true; }
        public function updateTimestamp(string $id, string $data) { return true; }
    }
}
// CLI stream constants are absent from the wasm SAPI; several vendor packages
// (e.g. Collision's error renderer) expect them.
if (!defined('STDIN')) { define('STDIN', fopen('php://stdin', 'r')); }
if (!defined('STDOUT')) { define('STDOUT', fopen('php://stdout', 'w')); }
if (!defined('STDERR')) { define('STDERR', fopen('php://stderr', 'w')); }
if (is_file('/tmp/pms-argv.json')) {
  $__argv = json_decode(file_get_contents('/tmp/pms-argv.json'), true) ?: [];
  $GLOBALS['argv'] = $__argv;
  $GLOBALS['argc'] = count($__argv);
  $_SERVER['argv'] = $__argv;
  $_SERVER['argc'] = count($__argv);
  unset($__argv);
}
if (!isset($_SERVER['argv']) || !is_array($_SERVER['argv'])) {
  $_SERVER['argv'] = [];
  $_SERVER['argc'] = 0;
}
@chdir(getenv('PWD') ?: '/app');
`;

export async function createPhp({ network = true } = {}) {
  const php = await createPHPRuntime({ network });
  if (!php.fileExists(APP_MOUNT)) php.mkdir(APP_MOUNT);
  await php.mount(APP_MOUNT, (instance, FS, path) => {
    FS.mount(FS.filesystems.NODEFS, { root: REPO_ROOT }, path);
    return () => {
      try {
        FS.unmount(path);
      } catch {
        /* already unmounted */
      }
    };
  });
  // Present as the CLI SAPI so artisan / Symfony Console behave normally.
  try {
    await php.setSapiName('cli');
  } catch {
    /* older runtimes may not expose this */
  }
  try {
    if (!php.fileExists('/tmp')) php.mkdir('/tmp');
    php.writeFile('/tmp/pms-prepend.php', PREPEND_SRC);
  } catch {
    /* best effort */
  }
  try {
    php.setIniValues(DEFAULT_INI);
  } catch {
    try {
      await php.setIniValues(DEFAULT_INI);
    } catch {
      /* ini tuning is best-effort */
    }
  }
  // Must run after any other SAPI/ini setup: strips "#!..." from entry scripts.
  try {
    php.setSkipShebang(true);
  } catch {
    /* best effort */
  }
  try {
    await php.setSpawnHandler(makeSpawnHandler());
  } catch {
    /* best effort */
  }
  return php;
}

// Serialize runs against one WASM instance (it cannot execute concurrently).
export function makeMutex() {
  let tail = Promise.resolve();
  return (fn) => {
    const run = tail.then(() => fn());
    tail = run.then(
      () => undefined,
      () => undefined
    );
    return run;
  };
}

// Runs a PHP entry point as the *main* script (so PHP strips its shebang and
// __DIR__ behaves), with argv restored by the auto_prepend_file above.
export async function runCli(php, scriptPath, argv = [], { cwd = APP_MOUNT, code = null, env = {} } = {}) {
  php.writeFile('/tmp/pms-argv.json', JSON.stringify([scriptPath ?? '-r', ...argv]));
  // Forward only safe env vars: the full host env includes TERM/COLUMNS/LINES
  // which make Symfony probe the terminal through proc_open (unsupported here).
  const passthrough = {};
  for (const [k, v] of Object.entries(process.env)) {
    if (v == null) continue;
    if (/^(PATH|LANG|LC_|TZ$|APP_|DB_|PMS_|TINKER|PORT|HOST|CI$)/.test(k)) passthrough[k] = String(v);
  }
  const opts = {
    env: { ...passthrough, PWD: cwd, HOME: '/tmp', ...env },
    $_SERVER: { PWD: cwd },
  };
  if (code != null) {
    opts.code = code;
  } else {
    opts.scriptPath = scriptPath;
  }
  const res = await php.runStream(opts);
  const [stdout, stderr, exitCode] = await Promise.all([
    res.stdoutText,
    res.stderrText,
    res.exitCode,
  ]);
  // Do not leak argv into later (web) runs.
  try {
    php.unlink('/tmp/pms-argv.json');
  } catch {
    /* ignore */
  }
  return { stdout, stderr, exitCode };
}
