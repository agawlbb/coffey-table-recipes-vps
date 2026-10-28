"""Check that a routine code copy preserves credentials and persisted data."""
import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('backend_deploy', ROOT / 'deploy.py')
deploy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(deploy)


class DeploymentTests(unittest.TestCase):
    def test_copy_preserves_private_state_and_does_not_build_or_use_cpanel(self):
        with tempfile.TemporaryDirectory() as directory:
            source = Path(directory) / 'source'
            home = Path(directory) / 'backend'
            code = home / 'code'
            for name in ('src/ShoppingSocket.php', 'vendor/autoload.php', 'bootstrap.php',
                         'server.php', 'composer.lock', 'compose.yaml'):
                path = source / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text('fixture')
            for name in ('private/database.json', 'private/firebase.json',
                         'state/pending.json', 'code/obsolete.php'):
                path = home / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text('keep')
            with patch.object(deploy, 'ROOT', source), patch.object(deploy, 'HOME', home), \
                 patch.object(deploy, 'CODE', code), patch.object(deploy, 'run') as run, \
                 patch.object(deploy, 'verify_cpu_policy') as verify_cpu:

                deploy.deploy()
                verify_cpu.assert_called_once_with()
            self.assertEqual((home / 'state/pending.json').read_text(), 'keep')
            self.assertEqual((home / 'private/database.json').read_text(), 'keep')
            self.assertFalse((code / 'obsolete.php').exists())
            self.assertTrue((code / 'src/ShoppingSocket.php').is_file())
            commands = [call.args for call in run.call_args_list]
            self.assertFalse(any('build' in call for call in commands))
            self.assertFalse(any('cpanel' in str(call).lower() for call in commands))
            self.assertLess(next(i for i, c in enumerate(commands) if 'stop' in c),
                            next(i for i, c in enumerate(commands) if 'up' in c))

    def test_effective_cpu_policy_accepts_shares_and_rejects_hard_quota(self):
        for shares, cpu_max, valid in (
            (1024, 'max 100000', True),
            (128, 'max 100000', False),
            (1024, '50000 100000', False),
        ):
            with self.subTest(shares=shares, cpu_max=cpu_max), \
                 patch.object(deploy.subprocess, 'check_output', side_effect=[
                     '{"CpuShares":' + str(shares) + '}', cpu_max]):
                if valid:
                    deploy.verify_cpu_policy()
                else:
                    with self.assertRaisesRegex(RuntimeError, 'CPU policy'):
                        deploy.verify_cpu_policy()

    def test_missing_dependencies_stops_before_changing_deployment(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'private').mkdir()
            for name in ('database.json', 'firebase.json'):
                (root / 'private' / name).write_text('fixture')
            with patch.object(deploy, 'ROOT', root), patch.object(deploy, 'HOME', root), \
                 patch.object(deploy, 'run') as run:
                with self.assertRaisesRegex(RuntimeError, 'composer install'):
                    deploy.deploy()
                run.assert_not_called()


if __name__ == '__main__':
    unittest.main()
