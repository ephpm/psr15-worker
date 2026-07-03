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
> package resolves its dependency from a sibling `../worker` checkout via a
> Composer `path` repository declared in `composer.json`. Once `ephpm/worker` is
> on Packagist (or added as a VCS repository), remove that `repositories` block.

## Usage

Three lines. Hand your PSR-15 app to the worker and run the loop:

```php
use Ephpm\Psr15\Worker;

$app = /* your PSR-15 RequestHandlerInterface: Slim App, Mezzio Application, ... */;

exit((new Worker($app))->run());
```

`run()` blocks on the native `Ephpm\Worker\take_request()`, dispatches each
request through your handler, translates the PSR-7 response back to the engine
via `Ephpm\Worker\send_response()`, and returns `0` when ePHPm signals shutdown.
A handler exception becomes a `500` so one bad request cannot kill the loop;
engine-level fatals are left to bubble so ePHPm can recycle the worker.

## Wiring it into ePHPm

Point ePHPm at the worker entrypoint and switch on worker mode in `ephpm.toml`:

```toml
[php]
mode = "worker"
worker_script = "vendor/ephpm/psr15-worker/bin/ephpm-worker"
```

`bin/ephpm-worker` finds your project's `vendor/autoload.php`, loads a bootstrap
that `return`s a `RequestHandlerInterface`, and runs the loop. Pass the bootstrap
path as an argument or via `EPHPM_WORKER_BOOTSTRAP`:

```toml
[php]
mode = "worker"
worker_script = "vendor/ephpm/psr15-worker/bin/ephpm-worker"
worker_args = ["app/worker-bootstrap.php"]   # this file must `return $app;`
```

Prefer to keep everything in your project? Copy `bin/ephpm-worker` (or one of the
`examples/`) into your app and point `worker_script` at your copy.

## Framework recipes

- **Slim 4** — [`examples/slim.php`](examples/slim.php)
- **Mezzio** — [`examples/mezzio.php`](examples/mezzio.php)

These are documentation recipes; they require the respective frameworks and are
not exercised in CI.

## Streaming caveat (Phase 1)

The request body is currently buffered as a string (`Envelope::bodyStream()`
returns a string today), and the response body is fully materialised before it
is sent. True request/response streaming is a later engine phase; the API here
will not change when it lands.

## License

MIT — see [LICENSE](LICENSE).
