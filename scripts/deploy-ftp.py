#!/usr/bin/env python3
"""Upload DocForge to the deploy FTP target.

Credentials are NEVER stored in this file. Provide them via environment
variables or a local, git-ignored `scripts/.deploy.env` (see
`scripts/.deploy.env.example`):

    FTP_HOST, FTP_USER, FTP_PASS, REMOTE_BASE
"""
import os
import sys
from ftplib import FTP


def _load_env_file(path):
    """Load simple KEY=VALUE lines from a local env file into os.environ."""
    if not os.path.isfile(path):
        return
    with open(path, 'r') as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith('#') or '=' not in line:
                continue
            key, val = line.split('=', 1)
            os.environ.setdefault(key.strip(), val.strip().strip('"').strip("'"))


_load_env_file(os.path.join(os.path.dirname(__file__), '.deploy.env'))

FTP_HOST = os.environ.get('FTP_HOST')
FTP_USER = os.environ.get('FTP_USER')
FTP_PASS = os.environ.get('FTP_PASS')
REMOTE_BASE = os.environ.get('REMOTE_BASE')
LOCAL_BASE = os.path.join(os.path.dirname(__file__), '..', 'web', 'docforge')

if not all([FTP_HOST, FTP_USER, FTP_PASS, REMOTE_BASE]):
    print('Missing FTP credentials. Set FTP_HOST, FTP_USER, FTP_PASS and '
          'REMOTE_BASE via environment or scripts/.deploy.env', file=sys.stderr)
    sys.exit(1)

UPLOADS = [
    ('home', '.'),           # site root: index.php, api/, includes/, install.php
    ('app', 'app'),
    ('storage', 'storage'),
    ('css', 'css'),
    ('js', 'js'),
    ('images', 'images'),
]

SKIP_DIRS = {'.git', '__pycache__'}
SKIP_FILES = {'.DS_Store'}


def ensure_dir(ftp, path):
    parts = [p for p in path.split('/') if p]
    cur = ''
    for p in parts:
        cur += '/' + p
        try:
            ftp.cwd(cur)
        except Exception:
            try:
                ftp.mkd(cur)
            except Exception:
                pass


def upload_tree(ftp, local_dir, remote_dir):
    count = 0
    for root, dirs, files in os.walk(local_dir):
        dirs[:] = [d for d in dirs if d not in SKIP_DIRS]
        rel = os.path.relpath(root, local_dir)
        remote_path = remote_dir if rel == '.' else remote_dir + '/' + rel.replace('\\', '/')
        ensure_dir(ftp, REMOTE_BASE + '/' + remote_path)
        for name in files:
            if name in SKIP_FILES:
                continue
            local_file = os.path.join(root, name)
            remote_file = REMOTE_BASE + '/' + remote_path + '/' + name
            with open(local_file, 'rb') as f:
                ftp.storbinary('STOR ' + remote_file, f)
            count += 1
            if count % 50 == 0:
                print('  uploaded %d files...' % count, flush=True)
    return count


def main():
    local_base = os.path.abspath(LOCAL_BASE)
    if not os.path.isdir(local_base):
        print('Missing:', local_base, file=sys.stderr)
        sys.exit(1)

    print('Connecting to', FTP_HOST)
    ftp = FTP(FTP_HOST)
    ftp.login(FTP_USER, FTP_PASS)
    ftp.set_pasv(True)
    ensure_dir(ftp, REMOTE_BASE)

    total = 0
    for local_sub, remote_sub in UPLOADS:
        src = os.path.join(local_base, local_sub)
        if not os.path.isdir(src):
            print('Skip missing:', src)
            continue
        print('Uploading', local_sub, '->', remote_sub)
        total += upload_tree(ftp, src, remote_sub)

    # Protect app and storage via .htaccess (already in repo)
    ftp.quit()
    print('Done. %d files uploaded.' % total)


if __name__ == '__main__':
    main()
