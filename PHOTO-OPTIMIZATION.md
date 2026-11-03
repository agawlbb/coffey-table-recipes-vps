# Background photo optimization

Task `task/photo-optimization`, based on fetched main
`e35e9c84056543a0fab10555079143ca11600c1e`.

Recipe saves still persist the original upload and return normally. A separate
Workerman process scans managed images at startup and every 30 seconds when its
pending list is empty, handling one pending file per one-second tick. No image decoding, encoding, queue writes or
provider calls are added to the upload request. The scan covers ordinary uploads,
AI-worker saves, category artwork and existing files, and resumes after restarts.

Files above 250,000 bytes or 1600 pixels on either edge are candidates. GD writes
WebP quality 82, preserving aspect ratio, transparency and JPEG EXIF orientation,
without upscaling. The result is published only when valid and smaller; files
that do not need resizing must save at least 10%. Existing 816-pixel JPEGs below
the byte threshold are left alone. Only JPEG, PNG and WebP up to 24 megapixels
are decoded. Animated uploads are not a supported app feature.

Originals remain in `state/media/recipes/`. Derived files and completion receipts
are private in `state/media/optimized/`. The HTTP media route serves a completed
WebP at the existing URL with `Content-Type: image/webp`, correct HEAD length and
existing CORS/security/cache headers. Source file identity/size/timestamps are
part of the cache key; replacing or deleting the original invalidates the derived
response. SQL photo metadata continues to describe the retained original, so no
database update, revision change or client synchronization is needed. Existing
device caches may retain the original until refreshed.

Encoding uses a temporary file and atomic rename. A cross-process lock serializes
the batch tool with the worker. Failed processing leaves the original usable and
is retried on a later scan; completion receipts prevent repeated lossy encodes.
Missing derived files are regenerated. Retaining originals intentionally means
this reduces served bytes and decoded dimensions, not original disk usage.

The runtime Dockerfile now includes GD JPEG/PNG/WebP and EXIF support. Build that
backend image once before deploying; the deployer checks these capabilities before
stopping the service. Routine deployment remains a source copy and restart.
No Flutter build or deployment is involved.

For an immediate batch inside the configured backend runtime:

```sh
php bin/optimize-recipe-images.php
```

The JSON-lines output reports unchanged, unsupported, already processed and
optimized files with original/output byte counts. Original bytes are never
replaced. To roll back, deploy the previous backend source/runtime; it serves the
originals directly. Private optimized files can remain for recovery.

Validation uses `php tests/recipe_image_optimizer.php`, `php tests/http.php`,
`python -m unittest discover -s tests -p data_websocket.py` and
`python -m unittest discover -s tests -p test_deployment.py`. They use generated
images, disposable SQLite and real local sockets, with no production data writes.
The upload wire test saves an oversized photo, checks the original/recipe URL and
metadata, exercises a heartbeat, waits for background processing and verifies GET
and HEAD switch to the smaller WebP.

## Verified deployment — 2026-11-01

Backend task source `9c9f41a12c27bdc36fca32a50e6e78e3d63ab2e1` was deployed
directly from the task branch under the requested backend-maintenance scope.
Main integration remains pending user acceptance; main was not changed. The
backend runtime was built from this Dockerfile with PHP 8.4.27 and GD/EXIF;
image ID `sha256:d24d53ec5037e09eba4b8fcfb04c57ddda03d3577aed663b808e0d40560a9d2a`.
The previous image remains tagged `ctr-backend:before-photo-optimization-20261101`.

Focused checks passed: 29 optimizer checks, 34 HTTP checks, all 5 data socket tests
(including the new asynchronous upload case), all 4 deployer tests, existing
recipe-save receipt and category-image fixtures, changed PHP syntax checks and
`git diff --check`. The 29 optimizer checks also passed inside the newly built
runtime with a 256 MB container limit before deployment. No Flutter tests/builds
or whole-backend suite were run for this bounded backend-only change.

The immediate batch and background worker completed all 86 managed image files:

| Scope | Original bytes | Served bytes |
| --- | ---: | ---: |
| 17 optimized images | 15,963,892 | 2,559,638 |
| All 86 images (69 unchanged) | 23,911,811 | 10,507,557 |

That saves 13,404,254 served bytes: 84.0% for the optimized set, 56.1% overall.
The largest PNG changed from 5,102,615 bytes at 1613 × 1525 to 218,268 served
bytes at 1600 × 1513; a visual comparison confirmed its content and orientation.
Each optimized output was validated before publication. Public HTTPS GET and HEAD
were checked for every image (172 requests), including exact response SHA-256,
MIME, CORS and HEAD byte lengths. Health returned ok, the pending list was empty,
and the service reported zero container restarts, no OOM and about 29 MiB of
memory after the batch. CPU shares remain 1024 with unlimited cpu.max.

Every pre-existing original and private configuration file matched its pre-change
SHA-256. No database row or production test recipe was created/changed. The
86-image original collection remains on disk for recovery, so these numbers are
network payload savings, not reclaimed storage. Existing app caches can continue
showing their cached originals until refreshed. New-upload behavior was tested
with generated images and disposable SQLite, not a real user's account.
