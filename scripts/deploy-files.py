#!/usr/bin/env python3
"""Targeted FTP upload of specific files (avoids re-uploading vendor/).

Usage: python3 scripts/deploy-files.py <local_path> [<local_path> ...]
Local paths are relative to the repo root and must live under web/docforge/.
Remote destination mirrors the deploy-ftp.py layout (home -> site root).
Credentials come from env or scripts/.deploy.env (never committed).
"""
import os
import sys
from ftplib import FTP


def _load_env_file(path):
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
REPO_ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
WEB_ROOT = os.path.join(REPO_ROOT, 'web', 'docforge')

if not all([FTP_HOST, FTP_USER, FTP_PASS, REMOTE_BASE]):
    print('Missing FTP credentials (FTP_HOST, FTP_USER, FTP_PASS, REMOTE_BASE).',
          file=sys.stderr)
    sys.exit(1)


def remote_for(local_path):
    """Map a local web/docforge/... path to its remote path."""
    rel = os.path.relpath(os.path.abspath(local_path), WEB_ROOT).replace('\\', '/')
    # home/* lands at the site root; everything else keeps its subtree.
    if rel.startswith('home/'):
        rel = rel[len('home/'):]
    return REMOTE_BASE + '/' + rel


def ensure_dir(ftp, path):
    cur = ''
    for p in [p for p in path.split('/') if p]:
        cur += '/' + p
        try:
            ftp.cwd(cur)
        except Exception:
            try:
                ftp.mkd(cur)
            except Exception:
                pass


def main():
    files = sys.argv[1:]
    if not files:
        print('No files given.', file=sys.stderr)
        sys.exit(1)

    print('Connecting to', FTP_HOST)
    ftp = FTP(FTP_HOST)
    ftp.login(FTP_USER, FTP_PASS)
    ftp.set_pasv(True)

    for local in files:
        if not os.path.isfile(local):
            print('Skip missing:', local)
            continue
        remote = remote_for(local)
        ensure_dir(ftp, os.path.dirname(remote))
        with open(local, 'rb') as f:
            ftp.storbinary('STOR ' + remote, f)
        print('  ->', remote)

    ftp.quit()
    print('Done. %d file(s) uploaded.' % len(files))


if __name__ == '__main__':
    main()
