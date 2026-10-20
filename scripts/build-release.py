#!/usr/bin/env python3
"""Build an explicit installable candidate; never publish or scan Git history."""
import hashlib
import json
from pathlib import Path
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parents[1]
SLUG = 'acemedia-login-block'
VERSION = json.loads((ROOT / 'package.json').read_text())['version']
FILES = ('acemedia-login-block.php', 'readme.txt', 'LICENSE', 'composer.json')
TREES = ('build', 'includes', 'vendor')
DENY = {'AGENTS.md', 'CLAUDE.md', 'SKILL.md', '.env', '__pycache__', 'node_modules', 'tests', 'test', '_test'}
lock = json.loads((ROOT / 'composer.lock').read_text())
installed = json.loads((ROOT / 'vendor/composer/installed.json').read_text())
expected = {p['name']: p['version'] for p in lock['packages']}
actual = {p['name']: p['version'] for p in installed['packages']}
if actual != expected or installed.get('dev-package-names'):
    raise SystemExit('Vendor does not match locked production packages; run composer install --no-dev.')
for required in (*FILES, *TREES, 'vendor/autoload.php', 'build/acemedia-login.js', 'build/login-block.js'):
    if not (ROOT / required).exists():
        raise SystemExit(f'Missing release input: {required}')
paths = [ROOT / name for name in FILES]
for tree in TREES:
    for path in sorted((ROOT / tree).rglob('*')):
        relative = path.relative_to(ROOT)
        if path.is_symlink():
            raise SystemExit(f'Symlink is not a release input: {relative}')
        if any(part.startswith('.') or part in DENY for part in relative.parts):
            continue
        if path.is_file():
            paths.append(path)
output = ROOT / 'dist'
output.mkdir(exist_ok=True)
manifest = {}
with tempfile.TemporaryDirectory(prefix='acemedia-release-') as directory:
    stage = Path(directory) / SLUG
    for path in sorted(paths):
        relative = path.relative_to(ROOT)
        data = path.read_bytes()
        manifest[str(relative)] = hashlib.sha256(data).hexdigest()
        target = stage / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(data)
    archive_path = output / f'{SLUG}-{VERSION}.zip'
    with zipfile.ZipFile(archive_path, 'w', zipfile.ZIP_DEFLATED) as archive:
        for relative in sorted(manifest):
            entry = zipfile.ZipInfo(f'{SLUG}/{relative}', date_time=(2026, 1, 1, 0, 0, 0))
            entry.external_attr = 0o100644 << 16
            entry.compress_type = zipfile.ZIP_DEFLATED
            archive.writestr(entry, (stage / relative).read_bytes())
    with zipfile.ZipFile(archive_path) as archive:
        if archive.testzip() is not None or len(archive.namelist()) != len(manifest):
            raise SystemExit('Archive verification failed')
        for relative, digest in manifest.items():
            if hashlib.sha256(archive.read(f'{SLUG}/{relative}')).hexdigest() != digest:
                raise SystemExit('Archive manifest mismatch')
(output / f'{SLUG}-{VERSION}.manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
print(f'Built and verified {archive_path} ({len(manifest)} files). Candidate only; no publication performed.')
