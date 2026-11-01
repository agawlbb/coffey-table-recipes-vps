# Background photo optimization

Task `task/photo-optimization`, based on fetched main
`e35e9c84056543a0fab10555079143ca11600c1e`.

Recipe saves still persist the original upload and return normally. A separate
Workerman process scans managed images every 30 seconds and encodes at most one
pending image per one-second tick. No image decoding, encoding, queue writes or
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
