# ephpm/psr15-worker

Run **any PSR-15 application** — Mezzio, Slim 4, or any framework that exposes a
`Psr\Http\Server\RequestHandlerInterface` — under **ePHPm persistent worker
mode**. One package serves them all.

In worker mode ePHPm keeps your framework bootstrapped in memory and dispatches
each HTTP request to a long-lived PHP worker, avoiding per-request bootstrap
cost.

## Install

```bash
composer require ephpm/psr15-worker
```

> **Note (pre-Packagist):** until `ephpm/worker` is published on Packagist, this
> package resolves its dependency from its GitHub repository via the Composer
> `vcs` repository declared in `composer.json`
> (`https://github.com/ephpm/php-worker`). Once `ephpm/worker` is on Packagist,
> remove that `repositories` block.

## Usage

Three lines. Hand your PSR-15 app to the worker and run the loop:

```php
use Ephpm\Psr15\Worker;

$app = /* your PSR-15 RequestHandlerInterface: Slim App, Mezzio Application, ... */;

exit((new Worker($app))->run());
```

`run()` blocks on the native `Ephpm\Worker\take_request()`, dispatches each
request through your handler, translates the PSR-7 response back to the engine
via `Ephpm\Worker\send_response()` (or `send_response_stream()` for large /
unknown-size bodies), and returns `0` when ePHPm signals shutdown. A handler
exception becomes a `500` so one bad request cannot kill the loop; engine-level
fatals are left to bubble so ePHPm can recycle the worker.

### What the worker does for you

The engine hands adapters raw request material only — it never parses bodies
(`Envelope::parsedBody()` is always null, `Envelope::files()` is always empty)
and its query/cookie arrays are not url-decoded. This package fills the gap:

- **Query string** is re-parsed with `parse_str()` (url-decoding plus `a[]=`
  bracket arrays).
- **Cookies** have their names and values url-decoded.
- **`application/x-www-form-urlencoded`** bodies (POST/PUT/PATCH/DELETE) are
  parsed into `getParsedBody()`.
- **`multipart/form-data`** bodies are parsed into fields plus PSR-7
  `UploadedFileInterface` instances; uploads are spooled to temp files that are
  removed automatically after each request.
- **JSON / raw bodies** are left untouched — read `getBody()` yourself.
- **`Set-Cookie` response headers** are sent as one wire header per cookie
  (never comma-joined); other repeated headers are comma-joined per RFC 9110.
- **Large responses stream.** Bodies larger than 1 MiB — or whose size is
  unknown — are handed to the engine as a stream and forwarded to the client in
  64 KiB chunks with backpressure, so big downloads keep memory flat.

## Wiring it into ePHPm

Point ePHPm at the worker entrypoint and switch on worker mode in `ephpm.toml`:

```toml
[php]
mode = "worker"
worker_script = "vendor/ephpm/psr15-worker/bin/ephpm-worker"
```

`bin/ephpm-worker` finds your project's `vendor/autoload.php`, loads a bootstrap
that `return`s a `RequestHandlerInterface`, and runs the loop. Under the engine
there is no CLI `$argv`, so name the bootstrap with the
`EPHPM_WORKER_BOOTSTRAP` environment variable (set it on the ePHPm server
process; the bootstrap file must `return $app;`):

```bash
EPHPM_WORKER_BOOTSTRAP=app/worker-bootstrap.php ephpm serve --config ephpm.toml
```

Note: `worker_script` must resolve to a file under `document_root`, so the
vendored `bin/ephpm-worker` only works when `vendor/` lives inside the document
root. Prefer to keep everything in your project? Copy `bin/ephpm-worker` (or
one of the `examples/`) into your app and point `worker_script` at your copy —
a self-contained worker script that builds the app and calls
`(new Worker($app))->run()` itself needs no bootstrap variable at all.

## Framework recipes

- **Slim 4** — [`examples/slim.php`](examples/slim.php)
- **Mezzio** — [`examples/mezzio.php`](examples/mezzio.php)

These are documentation recipes; they require the respective frameworks and are
not exercised in CI.

## Streaming

Response bodies stream: anything larger than 1 MiB (or of unknown size) is sent
through the engine's `send_response_stream()` with flat memory. The request
body is currently buffered as a string via `Envelope::rawBody()` — incremental
request-body consumption (`Envelope::bodyStream()`) is available from the
engine but not yet used by this adapter.

## License

MIT — see [LICENSE](LICENSE).
