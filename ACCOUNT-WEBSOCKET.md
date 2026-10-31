# VPS account functions

The `feature/vps-account-auth` branches in the app, InMotion and VPS repositories
move account bootstrap, first household creation, verification mail and invitations
to `wss://ctr.coffeybean.com/shopping`. Bases: app `6a4227a`, InMotion `42ab625`,
VPS `287dc4d`. The test deployment is recorded below; the branches remain unmerged.

Firebase still handles password/Google authentication, refresh tokens and password
resets. The app sends Firebase ID tokens, never passwords, to the VPS. No screen or
sign-in flow changes. Existing MySQL data stays on InMotion; no schema/data migration.

## Transport

Authenticate with `{ "v": 1, "type": "auth", "scope": "account", "token": "<Firebase ID token>" }`.
Do not select a household. A valid new Firebase identity may open the connection
before its local SQL user exists. Every request rechecks the identity and disabled
account state; provisioning a missing SQL user also checks the live Firebase user.
Reauthentication and disconnect cancel the connection's outstanding account worker.

Use the existing request/response protocol. An absent/null `body` reads; an object
body writes. Only these paths are accepted on an account connection:

- `/me`: read user, child-mode state, memberships and pending invitations.
- `/households`: read memberships or create the first household with `name` and `person_name`.
- `/me/verification-email`: write `{}`; recipient/UID come from the verified identity.
- `/households/{id}/people/{id}/invitations`: write `email`; requires owner/admin.
- `/invitations/{id}/respond`: write `decision` (`accept` or `decline`); verified matching email required.

The worker runs separately from the socket loop, preserving heartbeats during SMTP
or Firebase calls. The client allows 90 seconds; the worker bridge stops at 85.
Mail sending does not grant membership. Existing invitation expiry, duplicate,
rate-limit, failed-delivery and transactional acceptance safeguards are retained.

Child-mode PIN files, PIN changes/resets, account administration and HA connection
management remain on InMotion. Account requests check the existing authenticated
`/me/child-mode` endpoint and fail closed on a policy outage. This is a remaining
InMotion dependency during sign-in. Database access also remains hosted there.
The retired InMotion account routes return `410 account_moved`.

## Private configuration before deployment

Reuse the currently working credentials; do not create another Firebase project or
send directly from the VPS IP. Copy files privately, outside Git and web directories:

| Existing InMotion file (relative to deployed CoffeyTableRecipes directory) | VPS destination |
| --- | --- |
| `config/smtp.local.json` | `/opt/services/ctr-backend/private/smtp.json` |
| Firebase service-account file selected by `firebase_service_account_file` in `config/local.php` (default `config/firebase-admin.local.json`) | `/opt/services/ctr-backend/private/firebase-admin.json` |

Keep mode `600`, readable by the existing backend container user. SMTP JSON is the
settings object itself: host, port, encryption, username, password, from_email and
from_name. Existing SSL/465 and sender defaults apply to omitted fields. Continue
using authenticated SMTP with certificate verification through the existing mail
server. Moving the SMTP client does not make the VPS the outbound mail server.

`private/firebase.json` remains the public project selector. `timer-push.json` is
the separate notification credential. Neither replaces the account credential.
The account credential must match that project and retain the existing lookup and
email-action-link permissions. Never put it in an app build. Keep InMotion's key
for retained account administration and policy checks.

Install locked Composer dependencies in the VPS task checkout, then deploy its
source using `deploy.py` when authorized. PHPMailer and the email artwork are
included; no runtime image rebuild is needed. Deployment checks required files
before stopping the backend and preserves private/state directories. Deploy VPS
before retiring InMotion routes and publishing an app with the new routing.
Do not deploy the InMotion retirement alone: the installed older app still uses it.

Offline validation uses disposable SQLite, signed test JWTs, fake Firebase/mail
callbacks and real loopback WebSockets. It does not prove live SMTP authentication,
Firebase permissions, inbox delivery or the published app's sign-in. Those remain
deployment checks. No real messages were sent by the tests. The deployment access
checks below cover SMTP authentication and Firebase lookup, not actual delivery.

## Test deployment, 2026-10-31

The user requested deploying this branch and building an Android test APK.
Deployed VPS runtime source: `cecdda0d116ed3c27ff785b71369474f679f8495`.
Reused the InMotion SMTP settings and the existing account credential selected by
its local configuration, copying them once into the private paths above with mode
600. Project binding matched the existing VPS Firebase selector; no keys were
created or logged. InMotion's runtime and the hosted app were not deployed.

`python3 deploy.py` copied source/dependencies and restarted `ctr-backend`.
All 47 deployed PHP, lock and mail-asset file hashes matched this source checkout.
CPU shares remained 1024 and effective `cpu.max` was `max 100000`. The existing
runtime image was reused; no rollback image or release directory was created.

Live checks passed: Firebase account lookup with a reserved nonexistent recipient;
SMTP TLS connection and authentication, followed by QUIT without sending mail;
public account WebSocket rejection of an invalid token (401); and public-scope
readiness/heartbeat. An email-action request with `returnOobLink=true` and the same
nonexistent recipient returned HTTP 400 `USER_NOT_FOUND`, rather than a permission
denial. No real user, verification link or email was created by these probes.
Fresh-user signup, real recipient delivery and signed-in device behavior remain
for the requested Android test. Child-mode policy still comes from InMotion.

## Verified on this branch

- `php tests/accounts.php`: 26 checks of provisioning, deleted/disabled identities,
  child policy, household setup, invitation access/acceptance and SMTP failures.
- `php tests/account_configuration.php`: private paths, existing defaults and wrong-project rejection.
- `php tests/verification.php` and `php tests/invitation-mail.php`: 14 and 15 checks, no mail sent.
- `php tests/authentication.php --identity-only`: 21 signed identity checks.
- `php tests/ai_jobs.php` and `php tests/data_socket.php`: worker lifecycle and existing data operations.
- `python -m unittest discover -s tests -p account_websocket.py` using the existing
  aiohttp environment: 5 tests of real sockets, new-user setup, mail heartbeats,
  reauthentication cancellation, scope restrictions and policy outages.
- The same runner with `ai_websocket.py` (8 tests) and `data_websocket.py` (3 tests)
  preserves AI progress and existing household socket behavior.
- `python3 -m unittest discover -s tests -p test_deployment.py`: 4 mocked tests;
  no deployment executed. `composer validate --no-check-publish` and changed PHP syntax checks passed.
