# Remaining PHP migration

Branch: `feature/vps-remaining-php`, based on VPS main `1257a92`.
Matching branches exist in the Flutter, shared-host and Home Assistant repositories.
Source prepared only: no deployment, media transfer, scheduler installation or app build performed.
Do not merge until the user approves.

## Service layout

The existing PHP/Workerman container serves browser HTTP on port 8081 and app
WebSockets on port 8080, behind Caddy. No Apache or frontend-container changes.

- `https://ctr.coffeybean.com/recipes/{id}` renders public recipes, with canonical,
  Open Graph and JSON-LD metadata. Missing, deleted and blank recipes return 404.
  The existing add-to-household link opens the static Flutter app on InMotion.
- `/media/recipes/{filename}` serves managed photos and category images.
- `/api/v1/home-assistant/auth-config`, `/api/v1/households`, household connection
  create/list/revoke and connection self-revocation use short HTTPS requests.
  Home Assistant shopping updates continue over the existing WebSocket.
- App account/user/person/Firebase administration and image permissions use the
  account WebSocket scope, with progress and heartbeat handling. They can include
  slow Firebase calls, so execute in isolated workers. Confirmed writes finish
  after disconnect; their responses are not delivered to a new identity. Clients
  never automatically replay these deletes. Normal read jobs cancel on disconnect.
- `bin/purge-deleted-recipes.php` retains the existing retention policy, batches
  and database lock. A daily systemd timer replaces the shared-host cron.

MySQL, SMTP relay and static Flutter hosting stay on InMotion. No application
function calls back to InMotion PHP. External photo URLs stay external. Existing
Firebase, database, SMTP and AI credentials are reused.

## One-time cutover, when deployment is authorized

1. Copy the existing shared-host `config/home-assistant.local.json` privately to
   `/opt/services/ctr-backend/private/home-assistant.json` (mode 600). It contains
   the existing Firebase `api_key`, not a new credential. All other required
   private files already belong to the existing VPS setup. Never commit them.
2. Pause app writes during cutover. Remove only the InMotion cron command
   `/opt/cpanel/ea-php82/root/usr/bin/php /home/ACCOUNT/coffeybean.com/CoffeyTableRecipes/bin/purge-deleted-recipes.php`.
   Preserve every other cron entry. Do this before image transfer.
3. From the VPS host, run the shared-host repository's `tool/move-recipe-media.py`
   without flags for a read-only inventory, then with `--apply --vps-source PATH`
   pointing to this reviewed checkout. The private cPanel selector defaults to
   `/home/debian/.config/coffey-table-recipes/cpanel.json`; override `--config` if
   needed. Source images are under
   `/home/ACCOUNT/coffeybean.com/CoffeyTableRecipes/media/recipes/`; destination is
   `/opt/services/ctr-backend/state/media/recipes/`.
   The tool copies and hashes bytes, checks source stability, keeps newer existing
   VPS category artwork and refuses conflicting recipe photos. It then rewrites
   managed photo URLs/keys in the existing MySQL transaction and validates every
   referenced image. External URLs and recipe revision numbers are preserved.
   SQL failure rolls back the references; original files remain intact. The
   private `media-migration.json` receipt is required by the deployment preflight.
   The operator runs this production operation explicitly, never as a test.
4. Install locked Composer dependencies in this checkout and run `python3 deploy.py`
   on the VPS host. This copies source, CLI cleanup and public association files,
   restarts the existing backend and reloads its Caddy site. It does not build an
   image, touch the database schema or install a scheduler. Check public pages,
   image bytes, admin operations and HA setup/revocation before resuming writes.
5. Install the two `ops/ctr-recipe-cleanup.*` files into `/etc/systemd/system/`, run
   `systemctl daemon-reload`, then `systemctl enable --now ctr-recipe-cleanup.timer`.
   Check `systemctl list-timers ctr-recipe-cleanup.timer` and the first service log.
   Routine deployments do not repeat this setup.
6. Deploy the shared-host branch only after the VPS works. Its narrowed workflow
   uploads just `.htaccess`, `index.php`, `share.php` and the retired cleanup CLI;
   old application requests return 410. It does not deploy the Flutter app, run
   schema migrations, create cron jobs or create rollback archives. Old generated
   recipe links are intentionally unsupported. Historical PHP source stays in Git.
7. After checking the VPS replacements, run the media tool with `--retire-source`
   to trash only exact verified source image files. It verifies the entire set
   first; changed, missing or new files block retirement. Unrelated hosted files,
   database data and private configuration are retained.

Flutter and HA source point to the VPS. The Flutter cache namespace is retained
so queued edits survive the address change; cached owned-image URLs are resolved
to the VPS without rewriting queued operations. Release/build/install these
clients only when requested. Old installed clients cannot use retired endpoints.

Android and iOS association files use the existing released identities. Source
validation is not an installed-device link check: at the next authorized release,
check them against the actual APK certificate and signed iOS entitlements and test
OS link resolution. The new domain association files are not live until deployment.

## Validation

Disposable SQLite, fake Firebase/mail and loopback WebSocket/HTTP tests cover
renderer privacy/escaping, missing pages, HA setup/revocation, admin access,
deletion, worker progress/heartbeat/disconnect behavior, media migration rollback
and cleanup of both legacy and VPS storage keys. Python byte fixtures cover
transfer verification, collisions, newer VPS artwork, source changes and safe
retirement. No production mutations or real AI calls are used for these tests.

Verified on 2026-10-31 through `dev exec`:

- PHP: `tests/http.php` (32), `tests/remaining_accounts.php` (21),
  `tests/accounts.php` (25), `tests/authentication.php` (21),
  `tests/recipe_sharing.php` (48), `tests/source_links.php` (53),
  `tests/recipe_deletion.php` (43), `tests/recipe_variation.php` (92),
  `tests/meal_plans.php` (25 plus restaurant fixtures), `tests/media_migration.php`,
  `tests/category_images.php`, `tests/home_assistant_connections.php`,
  `tests/data_socket.php` and `tests/ai_jobs.php` all passed.
- Python wire suites: `account_websocket.py` (8), `data_websocket.py` (4) and
  `shopping_websocket.py` (3) passed; the latter includes the matching HA client.
  HTTP GET/HEAD framing, rendered-page privacy, association MIME, image bytes,
  progress/heartbeat and deletion completion after disconnect were checked.
- `python3 -m unittest discover -s tests -p test_deployment.py` (4) passed with
  fake deployment commands, preserving private configuration and state.
- PHP syntax lint and `git diff --check` passed. Fixture translation does not
  test MySQL locking/concurrency. Live deployment/media cutover, SMTP, scheduler
  execution and installed-device association checks remain for authorized rollout.
