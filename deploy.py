"""Copy this service to the VPS and restart it. No cPanel access."""
from pathlib import Path
import json
import shutil
import subprocess

ROOT = Path(__file__).resolve().parent
HOME = Path('/opt/services/ctr-backend')
CODE = HOME / 'code'


def run(*args):
    subprocess.run(args, cwd=ROOT, check=True)


def verify_cpu_policy():
    settings = json.loads(subprocess.check_output(
        ['sudo', '-n', 'docker', 'inspect', 'ctr-backend',
         '--format', '{{json .HostConfig}}'], text=True))
    cpu_max = subprocess.check_output(
        ['sudo', '-n', 'docker', 'exec', 'ctr-backend',
         'cat', '/sys/fs/cgroup/cpu.max'], text=True).strip()
    if settings['CpuShares'] != 1024 or cpu_max.split()[0] != 'max':
        raise RuntimeError('VPS CPU policy requires CpuShares=1024 and unlimited cpu.max')
    print('CPU policy verified: CpuShares=1024; cpu.max=' + cpu_max)


def deploy():
    for name in ('database.json', 'firebase.json', 'timer-push.json', 'recipe-ai.local.json', 'smtp.json', 'firebase-admin.json', 'child-mode-sql-import.json', 'home-assistant.json', 'media-migration.json'):
        if not (HOME / 'private' / name).is_file():
            raise RuntimeError('Missing existing private configuration: ' + name)
    manifest = json.loads((HOME / 'private/media-migration.json').read_text())
    if manifest.get('references_updated') is not True:
        raise RuntimeError('Complete the verified media transfer before deploying this cutover')
    if not (ROOT / 'vendor/autoload.php').is_file():
        raise RuntimeError('Run dev exec composer install --no-dev in this checkout first')
    for name in ('src', 'bin/purge-deleted-recipes.php', 'associations/assetlinks.json', 'associations/apple-app-site-association', 'assets/email/coffey_table.jpg', 'server.php', 'ai-worker.php', 'account-worker.php', 'bootstrap.php', 'composer.lock', 'compose.yaml', 'backend.caddy'):
        if not (ROOT / name).exists():
            raise RuntimeError('Incomplete source checkout: ' + name)

    compose = str(ROOT / 'compose.yaml')
    run('sudo', '-n', 'install', '-d', '-m', '755', '-o', '1000', '-g', '1000', str(CODE))
    run('sudo', '-n', 'docker', 'compose', '-f', compose, 'stop', 'backend')
    # code/ contains only replaceable source. private/ and state/ are siblings.
    for path in CODE.iterdir():
        if path.is_dir() and not path.is_symlink():
            shutil.rmtree(path)
        else:
            path.unlink()
    for name in ('src', 'vendor', 'assets', 'bin', 'associations'):
        shutil.copytree(ROOT / name, CODE / name)
    for name in ('bootstrap.php', 'server.php', 'ai-worker.php', 'account-worker.php', 'composer.lock'):
        shutil.copy2(ROOT / name, CODE / name)
    run('sudo', '-n', 'install', '-m', '644', compose, str(HOME / 'compose.yaml'))
    run('sudo', '-n', 'docker', 'compose', '-f', str(HOME / 'compose.yaml'), 'up', '-d', '--no-build')
    run('sudo', '-n', 'docker', 'compose', '-f', str(HOME / 'compose.yaml'),
        'exec', '-T', 'backend', 'php', '-r',
        "$s=@fsockopen('127.0.0.1',8080);exit($s===false?1:0);")
    # The same site gains a narrowly scoped route for persistent recipe uploads.
    run('sudo', '-n', 'install', '-m', '644', str(ROOT / 'backend.caddy'),
        '/opt/vps/caddy/conf/sites/ctr-backend.caddy')
    run('sudo', '-n', 'docker', 'exec', 'edge-caddy-1', 'caddy', 'reload',
        '--config', '/etc/caddy/Caddyfile')
    verify_cpu_policy()
    print('VPS source copied; backend restarted and listening.')


if __name__ == '__main__':
    deploy()
