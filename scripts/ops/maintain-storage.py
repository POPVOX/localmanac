#!/usr/bin/env python3
"""Inspect disk usage and prune abandoned PHP downloads on the Forge host.

Dry-run by default. Run as root with --apply to prune, or --check to only
check capacity. Disk warnings use the application's existing error reporting.
"""

import argparse
import glob
import json
import os
import pwd
import shutil
import stat
import subprocess
import syslog
import time
from pathlib import Path


def open_file_inodes():
    opened = set()
    for fd in glob.glob('/proc/[0-9]*/fd/*'):
        try:
            info = os.stat(fd)
            opened.add((info.st_dev, info.st_ino))
        except (FileNotFoundError, ProcessLookupError):
            pass
    return opened


def cleanup(directory, owner_uid, cutoff, opened, apply=False):
    count = size = 0
    for path in Path(directory).glob('php*'):
        try:
            info = path.lstat()
        except FileNotFoundError:
            continue
        if not (stat.S_ISREG(info.st_mode) and info.st_uid == owner_uid
                and max(info.st_mtime, info.st_ctime, info.st_atime) < cutoff
                and (info.st_dev, info.st_ino) not in opened):
            continue
        if apply:
            try:
                current = path.lstat()
                if current != info:
                    continue
                path.unlink()
            except FileNotFoundError:
                continue
        count += 1
        size += info.st_size
    return {'files': count, 'bytes': size, 'applied': apply}


def check_capacity():
    usage = shutil.disk_usage('/')
    percent = round(100 * usage.used / usage.total, 1)
    if percent >= 75:
        # Report at most once per hour through the existing Nightwatch/log stack.
        php = r'''chdir('/home/forge/localmanac.com/current');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (Illuminate\Support\Facades\Cache::add('ops:disk-warning', true, 3600)) {
    report(new RuntimeException('Server root disk usage is '.$argv[1].'% (warning threshold 75%).'));
}'''
        syslog.syslog(syslog.LOG_WARNING, f'LocAlmanac disk usage: {percent}%')
        subprocess.run(['sudo', '-u', 'forge', 'php', '-r', php, str(percent)], check=True)
    return percent


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument('--apply', action='store_true')
    mode.add_argument('--check', action='store_true')
    args = parser.parse_args()
    if os.geteuid() != 0:
        parser.error('Run as root so open files can be checked across every worker.')
    result = {}
    if not args.check:
        result = cleanup('/tmp', pwd.getpwnam('forge').pw_uid,
                         time.time() - 48 * 3600, open_file_inodes(), args.apply)
    result['disk_used_percent'] = check_capacity()
    print(json.dumps(result))
    if args.apply:
        syslog.syslog(syslog.LOG_INFO, 'LocAlmanac storage cleanup: ' + json.dumps(result))


if __name__ == '__main__':
    main()
