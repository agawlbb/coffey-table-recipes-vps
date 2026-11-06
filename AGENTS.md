# Working rules

## Keep it simple

This is a small personal project. Use the simplest maintainable implementation.
A couple of minutes of deployment downtime is acceptable. Prefer copying files
and restarting one process. Keep setup separate from routine deployment. Do not
add release frameworks, zero-downtime machinery, compatibility layers, feature
flags or abstractions for hypothetical scale. Preserve authentication, credential
privacy and protection against real data loss. Explain a concrete need before
adding complexity. Stay within the requested behavior; no unsolicited features.

## Repository and branches

- Canonical repository: `agawlbb/coffey-table-recipes-vps`; default branch `main`.
  This is the VPS backend only. inMotion is a separate server repository.
- Before editing, inspect instructions, worktrees, edits, live remote heads and
  active writers. Preserve unrelated and unpushed work. Fetch origin first.
- For new tasks use an isolated worktree and temporary branch from fetched
  `origin/main`. Record its purpose/base. For resumed tasks inspect divergence
  before integrating. Do not blindly pull, overwrite edits or rewrite history.
- Test appropriately, commit and push completed work to the existing origin.
  Verify a clean tree and matching local/GitHub heads before declaring completion.
- Merge only accepted work with user approval. Fetch/integrate main first and
  rerun relevant checks. Delete temporary branches only after verified merge or
  explicit abandonment. Preserve historical evidence and unique work.
- Do not create repositories, force-push or delete another task's work without
  explicit authorization. Do not introduce branch protection/rulesets.
- Inspect workflow triggers before every push. Publishing source does not itself
  authorize builds, deployment or installation. Honor explicit local-only requests.

## Development, data and deployment

- Use the existing shared development container through
  `/home/debian/.local/bin/dev exec`. Respect workspace instructions.
- Keep credentials, private config, dependencies, logs and generated files out
  of Git. Dependencies are project-local and locked. Never print secrets.
- The persistent MariaDB database runs on the VPS private database network. Never use production data
  or production containers for development tests. Use disposable SQLite fixtures.
- The owner's requirements define correctness; existing tests are evidence, not
  additional requirements. Test failures never authorize adding unrequested
  restrictions, version comparisons, conflict handling or other behavior.
- Before editing, state the observable behavior and smallest relevant checks.
  Use affected PHP fixtures or wire checks for the behavior changed, deployment
  tooling tests only when that tooling changes, and diff review for documentation.
  A full suite requires an explicit request for that suite; a deployment, release,
  shared-code change, merge or push does not authorize it. Explain a concrete
  reason before broadening focused checks. Do not repeat passing checks without
  a relevant new change, failure or unresolved concern.
- Classify a failing test before changing code: fix requested behavior; remove or
  correct tests imposing unwanted behavior; ask about unclear product decisions.
  Test observable outcomes rather than internal counters, queue layouts or retry
  counts. Preserve useful authentication, account isolation and actual user-data
  protection coverage. Do not retain obsolete tests as a competing specification.
- Separate test execution from build/deployment/delivery. Keep live health,
  deployed-source and package verification, but no automatic test-suite hooks.
  Report exactly what was checked and its limits; passing tests do not establish
  untested requirements. A test cleanup does not authorize production changes.
- Keep private/state directories outside replaced code. Routine deployment copies
  source and restarts the existing process. No cPanel credential belongs here.
- Schema changes and credential imports are explicit setup/maintenance actions,
  never recurring deployment steps. Preserve SQL transactions and replay receipts.
- This repository does not build or publish the app or install the HA integration.

- Child mode for shopping is enforced by hiding app editing controls. Do not add
  server-side child-mode restrictions or an inMotion HTTP policy dependency.

## VPS CPU policy

Production uses `cpu_shares: 1024`; shared development uses `cpu_shares: 128`.
Do not set `cpus`, `cpu_quota` or `deploy.resources.limits.cpus` hard caps.
Preserve existing memory, swap, process, security and restart settings. Deployment
must retain the policy in its installed Compose configuration and verify
`CpuShares=1024` and effective `/sys/fs/cgroup/cpu.max` beginning with `max`.
The VPS reference is `agawlbb/vps_admin`, branch `work`, commit `9463376`.
