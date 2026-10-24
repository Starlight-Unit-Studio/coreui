#!/usr/bin/env python3
"""Verify a CoreUI-generated ZIP writer in a disposable Python environment.

Reads only a synthetic test payload from stdin. Never touches CoreUI's live
artifact store and never imports model-generated source files.
"""
import json
import subprocess
import sys
import tempfile
import zipfile
from pathlib import Path


def fail(reason: str) -> None:
    print('CoreUI universal ZIP verification FAILED: ' + reason, file=sys.stderr)
    raise SystemExit(1)


def main() -> None:
    try:
        payload = json.load(sys.stdin)
    except (ValueError, UnicodeDecodeError):
        fail('payload is not valid JSON')
    if not isinstance(payload, dict):
        fail('payload is not an object')
    code = payload.get('code')
    expected = payload.get('expected')
    archive = payload.get('archive')
    if (not isinstance(code, str) or not isinstance(expected, dict)
            or not isinstance(archive, str) or len(code) > 200000
            or not archive.endswith('.zip') or '/' in archive or '\\' in archive
            or code.count("Path('/work/output')") != 1
            or not code.startswith('from pathlib import Path\nimport base64, zlib, zipfile, tempfile\n')
            or len(expected) < 1 or len(expected) > 10):
        fail('trusted writer contract failed')
    if any(not isinstance(name, str) or not isinstance(source, str)
           or name.startswith('/') or '\\' in name or '..' in name.split('/')
           for name, source in expected.items()):
        fail('invalid test fixture members')
    with tempfile.TemporaryDirectory(prefix='coreui_zip_pilot_') as td:
        root = Path(td)
        out = root / 'output'
        out.mkdir(mode=0o700)
        writer = root / 'writer.py'
        new_code = code.replace("Path('/work/output')", 'Path(' + repr(str(out)) + ')')
        writer.write_text(new_code, encoding='utf-8')
        try:
            proc = subprocess.run([sys.executable, '-I', str(writer)], cwd=root,
                                  capture_output=True, text=True, timeout=30,
                                  check=False)
        except subprocess.TimeoutExpired:
            fail('sandboxed writer timed out')
        if proc.returncode != 0:
            fail('writer exit code ' + str(proc.returncode))
        output_zip = out / archive
        if not output_zip.is_file() or output_zip.stat().st_size <= 0:
            fail('no ZIP artifact produced')
        try:
            with zipfile.ZipFile(output_zip) as z:
                if z.testzip() is not None:
                    fail('ZIP CRC failure')
                if sorted(z.namelist()) != sorted(expected):
                    fail('ZIP members differ from manifest')
                for path, source in expected.items():
                    if z.read(path) != source.encode('utf-8'):
                        fail('ZIP bytes differ: ' + path)
        except (OSError, zipfile.BadZipFile, KeyError):
            fail('cannot open or verify complete archive')
    print('CoreUI universal project ZIP writer selftest: OK')


if __name__ == '__main__':
    main()
