# Shopping item photos

One optional photo per shopping item, shared by its household. Source branch
`feature/shopping-item-photo` starts at `b8c0b24f9c04981f1604f52ddc13244b9a3007e2`.
The paired Flutter branch has the same name (base `9c840e26b3b61bbdcb7f0938986e6bcb3d006f6c`).

## Protocol and storage

- On an authenticated shopping or household WebSocket, send a request to
  `/households/{id}/shopping-list/photos` with `image_base64` to upload. The
  response contains `photo`, a 64-character SHA-256 reference. Repeating the same
  upload returns the same reference. Upload alone does not change/broadcast the list.
- Read `/households/{id}/shopping-list/photos/{reference}` with no body to receive
  `image_base64`. Membership and enabled-account checks apply on every operation.
  Home Assistant tokens cannot upload/read images; there is no public media URL.
- Item metadata accepts optional `photo`. Omission preserves a same-ID entry's
  photo for old clients. Empty string removes the attachment. Field-level merge
  stamps prevent an independent checkoff/name/note edit from losing a photo edit.
- Input is JPEG/PNG/WebP, at most 5 MiB and 24 megapixels. GD validates decoding;
  EXIF orientation is applied, metadata stripped, transparency flattened to white,
  and the image encoded as JPEG quality 85, at most 1600 pixels on either side.
- Files are under `CTR_STORAGE/shopping-photos/{household}/{reference}.jpg`,
  outside replaceable code and public recipe media. Atomic file writes avoid
  partial images. Snapshots and database JSON contain references only; no schema
  migration is needed. Removing/replacing an attachment clears/changes the list
  reference. Uploaded files remain private on disk, including unused uploads,
  so queued edits and lost-response retries retain valid references. No automatic
  media purge is introduced.

## Runtime and delivery

The updated Dockerfile includes GD (JPEG/PNG/WebP) and EXIF. The backend runtime
image must be rebuilt before source rollout; the normal source-only deploy uses
`--no-build` and cannot add these extensions. This task does not rebuild or deploy
production, merge main, build the app, or use the production database for tests.
Keep `state/` and existing private configuration intact during later rollout.

## Validation

Using the shared development container and disposable SQLite/filesystem fixtures:

- `php tests/shopping_lists.php`, `php tests/shopping_socket.php`,
  `php tests/data_socket.php`, and `php tests/shopping_photos.php` passed.
  The image fixture includes orientation 6 and mirrored orientations 5/7,
  downscaling, metadata stripping, transparent PNGs, malformed content and
  declared pixel limits. Socket checks cover revoked membership, cross-household
  access, HA restrictions and missing/invalid references.
- `python -m unittest discover -s tests -p '*websocket.py'`: 31 tests,
  30 passed and one skipped. New tests exercise photo upload/read on real
  shopping and household-scope sockets and ensure broadcasts contain references,
  not image bytes.
- `python -m unittest discover -s tests -p test_deployment.py`: four passed.
  Deployment messages printed by this fixture are mocked; production was untouched.
- `composer validate --no-check-publish`, changed PHP syntax checks and
  `git diff --check` passed. Composer package versions are unchanged.

Push-trigger review: this repository has no tracked `.github` workflow directory.
Only the task branch is to be pushed; main integration and production rollout
remain pending user instruction.
