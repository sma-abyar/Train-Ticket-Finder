"""Build a hosting-friendly upload: regular files 0644, directories 0755."""
from pathlib import Path, PurePosixPath
import argparse
import hashlib
import stat
import time
import zipfile

ROOT = Path(__file__).resolve().parents[1]
FILES = '''bot.php check_tickets.php cron_probe.php runFetchTickets.php database.php
diagnose_notifications.php notification_diagnostics.php expired_trips.php send_queue.php telegram_delivery.php
telegram_webapp_auth.php telegram-alerts.php telegram-app.php miniapp_assets.php
ticket_search.php train_follow_bot.php train_follows.php traveler.php travelerlist.php
trip_app.php trip_dates.php trip_filter_ui.php trip_preferences.php trip.php user.php traveler_list_storage.php
telegram-app.html telegram-app.css telegram-app.js miniapp-calendar.js
telegram-alerts.html telegram-alerts.css telegram-alerts.js train_cities.json
fonts/webfonts/Vazirmatn[wght].woff2 licenses/jalaali-js.txt LICENSE
MINI_APP_DEPLOY.md NOTIFICATION_DELIVERY.md MINI_APP_ASSETS_FIX.md MINI_APP_REDESIGN.md CRON_PROBE.md'''.split()


def build_upload(destination, root=ROOT):
    root = Path(root).resolve()
    timestamp = time.localtime()[:6]
    manifest = []
    directories = set()
    for name in FILES:
        directories.update(str(p) + '/' for p in PurePosixPath(name).parents if str(p) != '.')

    def write(archive, name, data, directory=False):
        entry = zipfile.ZipInfo(name, timestamp)
        entry.create_system = 3  # Explicit Unix metadata; never ZipInfo's default 0600.
        mode = (stat.S_IFDIR | 0o755) if directory else (stat.S_IFREG | 0o644)
        entry.external_attr = (mode << 16) | (0x10 if directory else 0)
        entry.compress_type = zipfile.ZIP_DEFLATED
        archive.writestr(entry, data)

    with zipfile.ZipFile(destination, 'w') as archive:
        for name in sorted(directories):
            write(archive, name, b'', True)
        for name in sorted(FILES):
            path = (root / name).resolve()
            if not path.is_relative_to(root) or path.suffix in ('.db', '.env'):
                raise ValueError('Unsafe upload path')
            data = path.read_bytes()
            write(archive, name, data)
            manifest.append(hashlib.sha256(data).hexdigest() + '  ' + name)
        write(archive, 'MANIFEST.sha256', ('\n'.join(manifest) + '\n').encode())


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('output', type=Path)
    args = parser.parse_args()
    build_upload(args.output)
    print(args.output.resolve())
