#!/usr/bin/env python3
"""Rebuild vendor/ from composer.lock without composer or packagist.

Sandbox recovery tool: packagist.org and the release-asset CDN are unreachable,
but every package in composer.lock has a GitHub dist zipball and codeload.github.com
is reachable. This script:

  1. downloads each production package from codeload ({org}/{repo}/zip/{ref}),
  2. extracts it into vendor/{package/name},
  3. generates a Composer-compatible autoloader (PSR-4 / PSR-0 / classmap / files)
     from the lock's autoload metadata, plus installed.json (package discovery)
     and installed.php (Composer\\InstalledVersions).

Usage:  python3 deploy/dev/rebuild-vendor.py [--dev]
  --dev  also install the packages-dev section (phpunit etc.; not needed to
         run artisan or the web app)
"""
import json
import os
import re
import shutil
import sys
import urllib.request
import zipfile
from io import BytesIO

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
VENDOR = os.path.join(ROOT, 'vendor')
COMPOSER_SRC = 'https://codeload.github.com/composer/composer/zip/2.10.3'
COMPOSER_DIR = 'composer-2.10.3'


def fetch(url, tries=3):
    for i in range(tries):
        try:
            req = urllib.request.Request(url, headers={'User-Agent': 'nowills-rebuild-vendor'})
            with urllib.request.urlopen(req, timeout=120) as r:
                return r.read()
        except Exception as e:
            if i == tries - 1:
                raise
            print(f'    retry {i + 1} after {e}')


def codeload_url(dist):
    """Map the lock's api.github.com zipball URL to a codeload URL (allowlisted)."""
    m = re.match(r'https://api\.github\.com/repos/([^/]+)/([^/]+)/zipball/(.+)', dist['url'])
    if m:
        return f'https://codeload.github.com/{m.group(1)}/{m.group(2)}/zip/{m.group(3)}'
    return dist['url']


def install_package(pkg):
    name = pkg['name']
    dest = os.path.join(VENDOR, *name.split('/'))
    if os.path.isdir(dest) and os.listdir(dest):
        print(f'  = {name} (cached)')
        return dest
    url = codeload_url(pkg['dist'])
    print(f'  ↓ {name}  {url.split("/zip/")[-1][:12]}')
    data = fetch(url)
    zf = zipfile.ZipFile(BytesIO(data))
    tmp = dest + '.tmp'
    if os.path.isdir(tmp):
        shutil.rmtree(tmp)
    os.makedirs(tmp, exist_ok=True)
    zf.extractall(tmp)
    entries = os.listdir(tmp)
    src = os.path.join(tmp, entries[0]) if len(entries) == 1 and os.path.isdir(os.path.join(tmp, entries[0])) else tmp
    os.makedirs(os.path.dirname(dest), exist_ok=True)
    if os.path.isdir(dest):
        shutil.rmtree(dest)
    shutil.move(src, dest)
    shutil.rmtree(tmp, ignore_errors=True)
    return dest


def php_path(rel):
    """ROOT-relative path → __DIR__-relative PHP expression (mount-independent)."""
    rel = rel.replace(os.sep, '/').lstrip('./')
    if rel.startswith('vendor/'):
        return RawPhp("__DIR__ . '/../" + rel[len('vendor/'):].rstrip('/') + "'")
    return RawPhp("__DIR__ . '/../../" + rel.rstrip('/') + "'")


def scan_classmap(paths):
    """Scan php files for class/interface/trait/enum declarations (composer classmap)."""
    out = {}
    for p in paths:
        base = p if os.path.isabs(p) else os.path.join(ROOT, p)
        if not os.path.exists(base):
            continue
        files = []
        if os.path.isfile(base):
            files = [base]
        else:
            for dirpath, _dirnames, filenames in os.walk(base):
                for fn in filenames:
                    if fn.endswith('.php'):
                        files.append(os.path.join(dirpath, fn))
        for f in files:
            try:
                text = open(f, encoding='utf-8', errors='ignore').read(65536)
            except OSError:
                continue
            ns = re.search(r'^namespace\s+([^;{\s]+)', text, re.M)
            for m in re.finditer(r'^\s*(?:abstract\s+|final\s+)?(class|interface|trait|enum)\s+(\w+)', text, re.M):
                fqcn = (ns.group(1) + '\\' if ns else '') + m.group(2)
                out[fqcn] = php_path(os.path.relpath(f, ROOT))
    return out


class RawPhp(str):
    """PHP expression emitted verbatim into generated files."""


def dump_php_array(obj, indent=0):
    """PHP array literal from Python data (strings, lists, dicts, None, bool, int)."""
    pad = '    ' * indent
    pad_in = '    ' * (indent + 1)
    if isinstance(obj, RawPhp):
        return str(obj)
    if obj is None:
        return 'null'
    if isinstance(obj, bool):
        return 'true' if obj else 'false'
    if isinstance(obj, int):
        return str(obj)
    if isinstance(obj, str):
        return "'" + obj.replace('\\', '\\\\').replace("'", "\\'") + "'"
    if isinstance(obj, list):
        if not obj:
            return '[]'
        return '[\n' + ',\n'.join(pad_in + dump_php_array(x, indent + 1) for x in obj) + ',\n' + pad + ']'
    if isinstance(obj, dict):
        if not obj:
            return '[]'
        rows = []
        for k, v in obj.items():
            rows.append(pad_in + dump_php_array(str(k), indent + 1) + ' => ' + dump_php_array(v, indent + 1))
        return '[\n' + ',\n'.join(rows) + ',\n' + pad + ']'
    raise TypeError(f'cannot serialize {type(obj)}')


def main():
    want_dev = '--dev' in sys.argv
    lock = json.load(open(os.path.join(ROOT, 'composer.lock')))
    root_json = json.load(open(os.path.join(ROOT, 'composer.json')))
    packages = list(lock.get('packages', []))
    dev_packages = list(lock.get('packages-dev', []))
    if want_dev:
        packages += dev_packages

    print(f'==> Installing {len(packages)} packages from composer.lock')
    for pkg in packages:
        install_package(pkg)

    print('==> Fetching Composer autoload sources (ClassLoader, InstalledVersions)')
    zdata = fetch(COMPOSER_SRC)
    zf = zipfile.ZipFile(BytesIO(zdata))
    com_dir = os.path.join(VENDOR, 'composer')
    os.makedirs(com_dir, exist_ok=True)
    for rel, out in [
        (f'{COMPOSER_DIR}/src/Composer/Autoload/ClassLoader.php', 'ClassLoader.php'),
        (f'{COMPOSER_DIR}/src/Composer/InstalledVersions.php', 'InstalledVersions.php'),
    ]:
        with zf.open(rel) as fh:
            open(os.path.join(com_dir, out), 'wb').write(fh.read())

    print('==> Generating autoloader')
    psr4, psr0, files, classmap_paths = {}, {}, [], []
    root_autoload = {}
    for key in ('autoload', 'autoload-dev'):
        if want_dev or key == 'autoload':
            for k, v in (root_json.get(key) or {}).items():
                if isinstance(v, dict):
                    merged = root_autoload.setdefault(k, {})
                    for ns, paths in v.items():
                        merged.setdefault(ns, []).extend(paths if isinstance(paths, list) else [paths])
                else:
                    root_autoload.setdefault(k, []).extend(v if isinstance(v, list) else [v])

    def merge_autoload(auto, prefix_name):
        for ns, paths in (auto.get('psr-4') or {}).items():
            psr4.setdefault(ns, [])
            for p in (paths if isinstance(paths, list) else [paths]):
                full = os.path.join(prefix_name, p) if prefix_name else p
                psr4[ns].append(full)
        for ns, paths in (auto.get('psr-0') or {}).items():
            psr0.setdefault(ns, [])
            for p in (paths if isinstance(paths, list) else [paths]):
                psr0[ns].append(os.path.join(prefix_name, p) if prefix_name else p)
        for f in (auto.get('files') or []):
            files.append(os.path.join(prefix_name, f) if prefix_name else f)
        for c in (auto.get('classmap') or []):
            classmap_paths.append(os.path.join(prefix_name, c) if prefix_name else c)

    merge_autoload(root_autoload, '')
    for pkg in packages:
        merge_autoload(pkg.get('autoload') or {}, os.path.join('vendor', *pkg['name'].split('/')))

    classmap = scan_classmap(classmap_paths)

    def php_return(obj):
        return "<?php\n\n// generated by deploy/dev/rebuild-vendor.py\nreturn " + dump_php_array(obj) + ";\n"

    open(os.path.join(com_dir, 'autoload_psr4.php'), 'w').write(php_return({k: [php_path(p) for p in v] for k, v in psr4.items()}))
    open(os.path.join(com_dir, 'autoload_namespaces.php'), 'w').write(php_return({k: [php_path(p) for p in v] for k, v in psr0.items()}))
    open(os.path.join(com_dir, 'autoload_classmap.php'), 'w').write(php_return(classmap))
    open(os.path.join(com_dir, 'autoload_files.php'), 'w').write(php_return([php_path(f) for f in files]))

    # installed.json — what PackageManifest reads for package discovery
    installed = {
        'packages': [
            dict(p, **{'install-path': '../' + p['name'], 'installation-source': 'dist'})
            for p in lock.get('packages', [])
        ],
        'dev': want_dev,
        'dev-package-names': [p['name'] for p in dev_packages],
    }
    open(os.path.join(com_dir, 'installed.json'), 'w').write(json.dumps(installed, indent=4))

    # installed.php — what Composer\InstalledVersions reads
    versions = {}
    for p in (lock.get('packages', []) + (dev_packages if want_dev else [])):
        versions[p['name']] = {
            'pretty_version': p.get('version', ''),
            'version': p.get('version_normalized', ''),
            'reference': (p.get('dist') or {}).get('reference') or (p.get('source') or {}).get('reference'),
            'type': p.get('type', 'library'),
            'install_path': f"__DIR__ . '/../{p['name']}'",
            'aliases': [],
            'dev_requirement': p in dev_packages,
        }
    root_name = root_json.get('name', 'nowills/pms')
    versions[root_name] = {
        'pretty_version': '1.0.0+no-version-set',
        'version': '1.0.0.0',
        'reference': None,
        'type': root_json.get('type', 'project'),
        'install_path': "__DIR__ . '/../../'",
        'aliases': [],
        'dev': True,
    }
    rows = []
    for name in sorted(versions):
        v = dict(versions[name])
        rows.append(dump_php_array(name, 3) + ' => ' + dump_php_array(v, 3))
    open(os.path.join(com_dir, 'installed.php'), 'w').write(
        "<?php return array(\n    'root' => array(\n"
        f"        'name' => {dump_php_array(root_name, 2)},\n"
        "        'pretty_version' => '1.0.0+no-version-set',\n        'version' => '1.0.0.0',\n"
        "        'reference' => null,\n"
        f"        'type' => {dump_php_array(root_json.get('type', 'project'), 2)},\n"
        "        'install_path' => __DIR__ . '/../../',\n        'aliases' => array(),\n        'dev' => true,\n    ),\n"
        "    'versions' => array(\n        " + ',\n        '.join(rows) + ",\n    ),\n);\n"
    )

    open(os.path.join(VENDOR, 'autoload.php'), 'w').write(
        """<?php
// Composer-compatible autoloader (generated by deploy/dev/rebuild-vendor.py).
$vendorDir = dirname(__DIR__);
require __DIR__ . '/composer/ClassLoader.php';
require __DIR__ . '/composer/InstalledVersions.php';
$loader = new \\Composer\\Autoload\\ClassLoader($vendorDir);
foreach (require __DIR__ . '/composer/autoload_psr4.php' as $ns => $paths) {
    $loader->setPsr4($ns, $paths);
}
foreach (require __DIR__ . '/composer/autoload_namespaces.php' as $prefix => $paths) {
    $loader->set($prefix, $paths);
}
if ($classmap = require __DIR__ . '/composer/autoload_classmap.php') {
    $loader->addClassMap($classmap);
}
$loader->register(true);
foreach (require __DIR__ . '/composer/autoload_files.php' as $file) {
    require_once $file;
}
return $loader;
"""
    )
    print(f'OK — vendor/ rebuilt: {len(packages)} packages, {len(classmap)} classmap entries, {len(files)} files')


if __name__ == '__main__':
    main()
