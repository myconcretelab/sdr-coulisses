#!/usr/bin/env python3
"""Build a clean, installable ZIP; no theme assets or local installation data."""
import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess
import zipfile

root = Path(__file__).resolve().parent.parent
parser = argparse.ArgumentParser()
parser.add_argument('--output', type=Path, required=True)
parser.add_argument('--tag', help='Require the release tag to match the plugin version.')
args = parser.parse_args()
subprocess.run(['php', str(root / 'tools/check-documentation.php'), '--check'], check=True)
version = re.search(r'^ \* Version: ([0-9.]+)$', (root / 'sdr-coulisses.php').read_text(), re.M).group(1)
header = (root / 'sdr-coulisses.php').read_text()
readme = (root / 'readme.txt').read_text()
if args.tag and args.tag != f'v{version}':
    parser.error('The tag must match the plugin version: v' + version)
if not re.search(r'^Stable tag: ' + re.escape(version) + r'$', readme, re.M):
    parser.error('Stable tag in readme.txt must match the plugin version.')
notes = re.search(r'^= ' + re.escape(version) + r' =\n(.*?)(?=^= |\Z)', readme, re.M | re.S)
if not notes:
    parser.error('The changelog must describe the current version.')
output = args.output.expanduser().resolve()
output.mkdir(parents=True, exist_ok=True)
archive = output / f'coulisse-{version}.zip'
directories = ['assets', 'blocks', 'documentation', 'emails', 'includes', 'relances', 'reminders', 'templates']
files = [root / name for name in ['sdr-coulisses.php', 'editor.php', 'LICENSE.txt', 'README.md', 'readme.txt']]
for directory in directories:
    files.extend(path for path in (root / directory).rglob('*') if path.is_file() and not path.name.startswith('.'))
files = [path for path in files if path.name not in ['review.json', 'README.md'] or path.parent == root]
with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as bundle:
    for path in sorted(set(files)):
        bundle.write(path, 'sdr-coulisses/' + path.relative_to(root).as_posix())
digest = hashlib.sha256(archive.read_bytes()).hexdigest()
(output / (archive.name + '.sha256')).write_text(digest + '  ' + archive.name + '\n')
(output / 'release.json').write_text(json.dumps({'name': 'Coulisse', 'version': version, 'filename': archive.name, 'sha256': digest,
    'requires_wordpress': re.search(r'^ \* Requires at least: ([0-9.]+)$', header, re.M).group(1),
    'requires_php': re.search(r'^ \* Requires PHP: ([0-9.]+)$', header, re.M).group(1)}, indent=2) + '\n')
(output / 'notes.md').write_text(notes.group(1).strip() + '\n')
print(archive)
