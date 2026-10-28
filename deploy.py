"""Copy this service to the VPS and restart it. No cPanel access."""
from pathlib import Path
import shutil
import subprocess

ROOT = Path(__file__).resolve().parent
HOME = Path('/opt/services/ctr-backend')
CODE = HOME / 'code'


def run(*args):
    subprocess.run(args, cwd=ROOT, check=True)


def deploy():
    for name in ('database.json', 'firebase.json'):
        if not (HOME / 'private' / name).is_file():
            raise RuntimeError('Missing existing private configuration: ' + name)
    if not (ROOT / 'vendor/autoload.php').is_file():
        raise RuntimeError('Run dev exec composer install --no-dev in this checkout first')
    for name in ('src', 'server.php', 'bootstrap.php', 'composer.lock', 'compose.yaml'):
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
    for name in ('src', 'vendor'):
        shutil.copytree(ROOT / name, CODE / name)
    for name in ('bootstrap.php', 'server.php', 'composer.lock'):
        shutil.copy2(ROOT / name, CODE / name)
    run('sudo', '-n', 'install', '-m', '644', compose, str(HOME / 'compose.yaml'))
    run('sudo', '-n', 'docker', 'compose', '-f', str(HOME / 'compose.yaml'), 'up', '-d', '--no-build')
    run('sudo', '-n', 'docker', 'compose', '-f', str(HOME / 'compose.yaml'),
        'exec', '-T', 'backend', 'php', '-r',
        "$s=@fsockopen('127.0.0.1',8080);exit($s===false?1:0);")
    print('VPS source copied; backend restarted and listening.')


if __name__ == '__main__':
    deploy()
