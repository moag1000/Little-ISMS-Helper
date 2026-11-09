#!/usr/bin/env python3
"""Install vendor/ in a Claude cloud thread (or any box that reaches github.com only over git).

Why: the cloud proxy answers 403 on GitHub zipballs (api.github.com / codeload), so
`composer install` cannot download dists, and `--prefer-source` mirrors every repository with
its full history (20+ minutes; phpstan/phpstan has no source entry at all, its repository holds
a 30 MB phar per release). git over https works and a single commit fetches in about a second.

What it does, from composer.lock and nothing else (same versions as CI):
  1. fetches every locked commit shallowly (`git fetch --depth 1 <url> <ref>`, 16 in parallel)
     and packs it with `git archive` (honours export-ignore like a real dist) into var/cloud-vendor/
  2. writes a temporary composer.cloud.json/.lock whose dists point at these zips
  3. runs `composer install` from them (no network), then deletes the temporary files

PHP: the host may be older than the project (cloud: 8.3). Composer runs with
--ignore-platform-req=php; PHPStan runs on 8.3 with `phpstan.cloud.neon` (sets phpVersion 80400,
like CI). PHPUnit needs PHP >= 8.4: see scripts/README.md "Cloud-Threads".

Usage:  python3 scripts/setup/cloud_vendor.py            (about 2-4 minutes, cache is reused)
"""
from __future__ import annotations

import json
import os
import shutil
import subprocess
import sys
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CACHE = ROOT / 'var' / 'cloud-vendor'
# phpstan/phpstan is dist-only in composer.lock; the distribution repository carries the phar.
SOURCE_FALLBACK = {'phpstan/phpstan': 'https://github.com/phpstan/phpstan.git'}


def run(cmd: list[str], cwd: Path | None = None, env: dict[str, str] | None = None) -> None:
    subprocess.run(cmd, cwd=cwd, env=env, check=True, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)


def pack(package: dict) -> tuple[str, Path]:
    name = package['name']
    source = package.get('source') or {}
    url = source.get('url') or SOURCE_FALLBACK.get(name)
    ref = source.get('reference') or (package.get('dist') or {}).get('reference')
    if not url or not ref:
        raise RuntimeError(f'{name}: no git url/reference in composer.lock')
    target = CACHE / f"{name.replace('/', '--')}-{ref[:12]}.zip"
    if target.is_file() and target.stat().st_size > 0:
        return name, target
    work = CACHE / 'git' / name.replace('/', '--')
    shutil.rmtree(work, ignore_errors=True)
    work.mkdir(parents=True)
    run(['git', 'init', '-q'], cwd=work)
    for attempt in range(4):
        try:
            run(['git', 'fetch', '-q', '--depth', '1', url, ref], cwd=work)
            break
        except subprocess.CalledProcessError:
            if attempt == 3:
                raise
    tmp = target.with_suffix('.part')
    run(['git', 'archive', '--format=zip', f'--prefix={name.split("/")[1]}/', '-o', str(tmp), 'FETCH_HEAD'], cwd=work)
    tmp.rename(target)
    shutil.rmtree(work, ignore_errors=True)
    return name, target


def main() -> int:
    lock = json.loads((ROOT / 'composer.lock').read_text())
    packages = lock['packages'] + lock.get('packages-dev', [])
    CACHE.mkdir(parents=True, exist_ok=True)
    print(f'Fetching {len(packages)} locked packages over git ...', flush=True)
    with ThreadPoolExecutor(max_workers=16) as pool:
        zips = dict(pool.map(pack, packages))

    for package in packages:
        package['dist'] = {'type': 'zip', 'url': str(zips[package['name']]),
                           'reference': (package.get('source') or package.get('dist') or {}).get('reference'),
                           'shasum': ''}
        package.pop('source', None)

    json_tmp, lock_tmp = ROOT / 'composer.cloud.json', ROOT / 'composer.cloud.lock'
    shutil.copy(ROOT / 'composer.json', json_tmp)
    lock_tmp.write_text(json.dumps(lock, indent=4))
    env = dict(os.environ, COMPOSER='composer.cloud.json', COMPOSER_ALLOW_SUPERUSER='1')
    try:
        print('composer install from the local zips ...', flush=True)
        subprocess.run(['composer', 'install', '--prefer-dist', '--no-scripts', '--no-interaction',
                        '--ignore-platform-req=php', '--ignore-platform-req=ext-*'],
                       cwd=ROOT, env=env, check=True)
    finally:
        json_tmp.unlink(missing_ok=True)
        lock_tmp.unlink(missing_ok=True)
    # Regenerate the autoloader against the real composer.json (runtime plugin included).
    subprocess.run(['composer', 'dump-autoload', '--ignore-platform-req=php', '--ignore-platform-req=ext-*'],
                   cwd=ROOT, env=dict(os.environ, COMPOSER_ALLOW_SUPERUSER='1'), check=True)
    print('vendor/ ready. PHPStan: vendor/bin/phpstan analyse -c phpstan.cloud.neon --memory-limit=2G')
    return 0


if __name__ == '__main__':
    sys.exit(main())
