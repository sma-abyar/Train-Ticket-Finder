"""Regression: unpacking the upload must not make public assets owner-only."""
from pathlib import Path
import hashlib
import io
import stat
import sys
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'tools'))
from build_upload import build_upload

buffer = io.BytesIO()
build_upload(buffer)
buffer.seek(0)
with zipfile.ZipFile(buffer) as archive:
    assert archive.testzip() is None
    for item in archive.infolist():
        mode = item.external_attr >> 16
        assert item.create_system == 3, item.filename
        assert stat.S_IMODE(mode) == (0o755 if item.is_dir() else 0o644), item.filename
        assert not item.filename.endswith(('.db', '.env', '.lock')), item.filename
        assert Path(item.filename).name != '.env'
    for row in archive.read('MANIFEST.sha256').decode().splitlines():
        digest, name = row.split('  ', 1)
        assert hashlib.sha256(archive.read(name)).hexdigest() == digest
print('PASS Upload: explicit 0644 files, 0755 directories, hashes and no credentials/database')
