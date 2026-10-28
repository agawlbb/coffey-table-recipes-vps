# Initial extraction validation — 2026-10-28

Source was extracted from server migration commit
`f28caaa4e8e1c5d1c13b341f87ef0a0056a9a3c5`. Runtime classes and the WebSocket
server retain the deployed behavior. Bootstrap, deployment paths and tests now
use this independent repository; no code is loaded from the inMotion checkout.

Verified using the existing shared development environment:

- 21 signed Firebase identity checks.
- PHP fixtures for HA credentials, socket access/isolation/revocation, shopping
  transaction replay/merge/deletion and persistent sync requests.
- All three real WebSocket tests, including the actual HA integration client.
- Two deployer tests: preserve private/state data while replacing code, and stop
  before mutation if dependencies are missing. No image build or cPanel operation.
- Strict locked Composer validation and PHP syntax checks.

Wire/transaction tests use disposable SQLite. No hosted shopping data, running
VPS service, inMotion files, app packages or installed HA integration were changed
for this repository extraction. There are no push-triggered deployment workflows.

## Shopping write repair

The owner requested app-only child-mode enforcement. Removed the server-side
policy callback and its HTTPS request to inMotion, which timed out and caused
503 responses on otherwise authenticated shopping writes. Firebase validation,
household membership, disabled-account checks, credential revocation and receipt
handling remain enforced. No schema or data migration is needed.

Verified 21 signed identity checks, socket access/receipt/revocation and HA
credential fixtures, all three real WebSocket tests (including the actual HA
client), two deployment-preservation tests, and changed PHP syntax. The source
branch starts at `a7f9c260990d8dc74cbd1f1c2aae53963d28427a`.

Deployed the repair through `python3 deploy.py`. The running container uses
`php server.php start`; its bootstrap, server and all runtime classes match this
branch. The public authenticated read passed. The first open-connection revocation
check failed without a detailed response; an immediate repeat passed both read
and revocation. No shopping items were modified by these checks, and temporary
credentials were revoked. No app packages were built or installed.

## Shared CPU policy retained through deployment

Updated source Compose from `cpus: 0.5` to `cpu_shares: 1024`, matching
`vps_admin` work commit `9463376` and the corrected installed configuration.
There is no separate runtime generator: deploy.py installs this source file.
Deployment now checks Docker CpuShares and effective cgroup cpu.max after restart.

All three deployment tests passed, including rejection of incorrect shares and a
hard effective quota. Resolved Compose contained no cpus, cpu_quota or CPU limit
under deploy.resources. Ran the corrected deployment and verified CpuShares=1024,
NanoCpus=0, CpuQuota=0 and cpu.max=`max 100000`. Installed Compose matched source.
A pre/post snapshot confirmed unchanged memory, swap/reservation/swappiness,
process, security, capability, root-filesystem, OOM and restart settings. No
image build, app build, schema/data change or changes to other services occurred.
