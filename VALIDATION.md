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
