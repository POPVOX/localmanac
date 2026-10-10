import importlib.util
import os
import tempfile
import time
import unittest
from pathlib import Path
from unittest.mock import patch

spec = importlib.util.spec_from_file_location(
    'maintenance', Path(__file__).parents[2] / 'scripts/ops/maintain-storage.py')
maintenance = importlib.util.module_from_spec(spec)
spec.loader.exec_module(maintenance)


class CleanupTest(unittest.TestCase):
    def test_cleanup_preserves_open_recent_unrelated_and_symlinked_files(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            for name in ['phpOld', 'phpOpen', 'phpRecent', 'retained.pdf']:
                (root / name).write_text('document')
            (root / 'phpLink').symlink_to(root / 'retained.pdf')
            (root / 'phpDirectory').mkdir()
            cutoff = time.time() + 1
            os.utime(root / 'phpRecent', (cutoff + 1, cutoff + 1))
            opened = (root / 'phpOpen').stat()
            active = {(opened.st_dev, opened.st_ino)}
            self.assertEqual(maintenance.cleanup(root, os.getuid(), cutoff, active)['files'], 1)
            self.assertTrue((root / 'phpOld').exists())
            self.assertEqual(maintenance.cleanup(root, os.getuid() + 1, cutoff, active, True)['files'], 0)
            self.assertEqual(maintenance.cleanup(root, os.getuid(), cutoff, active, True)['files'], 1)
            self.assertFalse((root / 'phpOld').exists())
            for name in ['phpOpen', 'phpRecent', 'retained.pdf', 'phpLink', 'phpDirectory']:
                self.assertTrue((root / name).exists())

    @patch.object(maintenance.shutil, 'disk_usage')
    @patch.object(maintenance.subprocess, 'run')
    def test_reports_only_at_warning_threshold(self, run, disk_usage):
        disk_usage.return_value = type('Usage', (), {'used': 74, 'total': 100})()
        self.assertEqual(maintenance.check_capacity(), 74)
        run.assert_not_called()
        disk_usage.return_value = type('Usage', (), {'used': 75, 'total': 100})()
        self.assertEqual(maintenance.check_capacity(), 75)
        run.assert_called_once()


if __name__ == '__main__':
    unittest.main()
