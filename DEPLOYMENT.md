# Routine VPS source deployment

Run preparation, dependency installation, selected project tests and Git inside
the shared development container, from the reviewed checkout intended for this
deployment. Use a clean reviewed commit/feature branch; do not silently include
uncommitted or unrelated work. Deployment requires human authorization; editing
or pushing source does not authorize publication.

Use standard rsync and ssh vps-host. This alias is the unprivileged vps-deploy
Unix account, not an administrator shell or a deployment program. Never use the
removed custom vps-deploy command. Do not copy Compose, Dockerfiles, .dockerignore,
credentials or runtime data during routine source deployment. Those host files
are protected. Changes to them, schema migrations and access changes require
separately authorized independent administration. The current infrastructure
and permission reference is
[vps_admin RUNBOOK](https://github.com/agawlbb/vps_admin/blob/work/RUNBOOK.md#restricted-deployment-access-november-8-2026).

Prepare the locked runtime dependencies in development:

    composer install --no-dev --prefer-dist --no-interaction

Run only the focused checks for the requested change. Do not run a full suite
automatically. Never use production data for development tests.
Routine deployment does not rebuild the PHP runtime or copy Compose/Caddy.
Do not use the old host-only deploy.py for this restricted-account path.

Validate Compose, stop only backend, transfer prepared code, then start it.
A failed transfer must be resolved before restarting; downtime is acceptable.
Private settings and persistent media/state are outside the code directory.

    ssh vps-host 'sudo -n /usr/bin/docker compose --env-file /dev/null -f /opt/services/ctr-backend/compose.yaml config --quiet'
    ssh vps-host 'sudo -n /usr/bin/docker compose --env-file /dev/null -f /opt/services/ctr-backend/compose.yaml stop backend'

    rsync -rlt --omit-dir-times --delete --exclude __pycache__ --exclude "*.pyc" --exclude ".env*" src/ vps-host:/opt/services/ctr-backend/code/src/
    rsync -rlt --omit-dir-times --delete --exclude __pycache__ --exclude "*.pyc" --exclude ".env*" vendor/ vps-host:/opt/services/ctr-backend/code/vendor/
    rsync -rlt --omit-dir-times --delete --exclude __pycache__ --exclude "*.pyc" --exclude ".env*" assets/ vps-host:/opt/services/ctr-backend/code/assets/
    rsync -rlt --omit-dir-times --delete --exclude __pycache__ --exclude "*.pyc" --exclude ".env*" bin/ vps-host:/opt/services/ctr-backend/code/bin/
    rsync -rlt --omit-dir-times --delete --exclude __pycache__ --exclude "*.pyc" --exclude ".env*" associations/ vps-host:/opt/services/ctr-backend/code/associations/
    rsync -rlt --omit-dir-times bootstrap.php server.php ai-worker.php account-worker.php composer.lock vps-host:/opt/services/ctr-backend/code/

    ssh vps-host 'sudo -n /usr/bin/docker compose --env-file /dev/null -f /opt/services/ctr-backend/compose.yaml up -d --no-build --no-deps --wait --wait-timeout 120 backend'
    ssh vps-host 'sudo -n /usr/bin/docker inspect --format {{.State.Status}} ctr-backend'
    ssh vps-host 'sudo -n /usr/bin/docker logs --tail 60 ctr-backend'

Check https://ctr.coffeybean.com/health and affected read-only public behavior.
Authenticated account/shopping/timer checks must use authorized test identities;
never change live recipes or send notifications as an unsolicited smoke test.
The fixed source/state/private mounts and production CPU/memory policy are
unchanged. The service has no Docker healthcheck, so running is not a health claim.
Do not rerun migrations, image builds, media cutover, credential imports or Caddy
reloads for routine source deployment. Those need separate administration.
Source rollback repeats this procedure from canonical main, retaining current data.
