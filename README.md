# PulsarX

A `requests`-style HTTP client for PHP, built on cURL. Session cookies in memory
(no files), browser **impersonation**, **async** parallel requests and multipart
uploads.

By **Vxsilisk** · MIT licensed.

## Install

**Composer:**

```bash
composer require vxsilisk/pulsarx
```

**Or zero-dependency** (no Composer) — just require the bundled autoloader:

```php
require __DIR__ . '/autoload.php';

$s = new Pulsar();
$r = $s->get('https://example.com');
echo $r->getStatusCode();   // 200
echo $r->getBody();
$data = $r->json();         // decoded JSON
```

## Session (cookies)

A `Pulsar` instance is a session — cookies set by the server persist in memory and
are re-sent automatically on later requests, scoped by **domain / path / expiry**
(like `requests.Session`). Nothing is written to disk.

```php
$s = new Pulsar();
$s->get('https://site.com/login');           // server sets cookies
$s->post('https://site.com/cart', $payload);  // cookies sent automatically
```

Cookies are correctly **isolated by host**: a cookie from `shop.com` is *not* sent
to `api.stripe.com`. Pre-seed manually if needed:

```php
$jar = new CookieJar();
$jar->add(new Cookie(name: 'session', value: 'abc123'));
$s->get('https://site.com', cookie: $jar);
```

## Impersonate

Mimic a browser's fingerprint (User-Agent, full header set **in browser order**,
TLS cipher list, EC curves, HTTP/2, Brotli/ZSTD). Chainable:

```php
$s = (new Pulsar())->impersonate('chrome131');
$r = $s->get('https://protected-site.com');
```

**32 version-pinned targets** (à la `curl_cffi`), spanning desktop, Android and iOS:

```
chrome99 chrome110 chrome116 chrome119 chrome120 chrome124 chrome131 chrome133
chrome136 chrome142 chrome146 chrome  chrome99_android chrome131_android chrome_android
edge99 edge101 edge131 edge  firefox133 firefox135 firefox144 firefox
safari153 safari170 safari180 safari260 safari  safari172_ios safari180_ios safari_ios  tor
```

```php
Pulsar::impersonateTargets();                  // -> the full list at runtime
$s->impersonate('chrome131_android');          // mobile Chrome
$s->impersonate('safari172_ios');              // iOS Safari
```

Your own headers merge on top of the profile (overriding by name, keeping order):

```php
$s->impersonate('chrome')->get($url, headers: ['Referer: https://google.com']);
```

> **JA3 accuracy.** This build runs on **OpenSSL**, so impersonation matches the
> HTTP layer and TLS cipher *ordering* — strong, but not a byte-exact JA3/JA4
> (TLS extension order, GREASE, ALPS, HTTP/2 SETTINGS need BoringSSL).
> If you run PHP against a [`curl-impersonate`](https://github.com/lwthiker/curl-impersonate)
> libcurl, Pulsar **auto-detects** the extra options (`ALPS`, cert compression,
> extension permutation, no-server-push) and produces an exact fingerprint — no
> code change needed.

## Async (parallel)

Build promises with `getAsync()` / `postAsync()` / `requestAsync()`, then resolve a
batch with `pool()`. Uses `curl_multi` with a **rolling concurrency window** and
`curl_multi_select` (near-zero CPU while waiting).

```php
$s = new Pulsar();

$promises = [
    $s->getAsync('https://api.com/a', key: 'a'),
    $s->getAsync('https://api.com/b', key: 'b'),
    $s->postAsync('https://api.com/c', ['x' => 1], key: 'c'),
];

$responses = $s->pool($promises, concurrency: 10);  // array keyed by `key`
echo $responses['a']->getStatusCode();
```

Per-promise callback:

```php
$s->getAsync($url)->then(fn(Response $r) => print($r->getStatusCode()));
$s->pool($promises);
```

## Multipart & file uploads (`Mime`)

Pulsar's take on `curl_cffi`'s `CurlMime` — builds a `multipart/form-data` body:

```php
$mime = (new Mime)
    ->addPart('username', data: 'andy')
    ->addPart('avatar', filename: 'a.png', contentType: 'image/png', localPath: '/tmp/a.png')
    ->addPart('inline', filename: 'note.txt', data: 'in-memory bytes'); // no temp file needed

$client->post($url, $mime);

// or declaratively
$mime = Mime::fromList([
    ['name' => 'username', 'data' => 'andy'],
    ['name' => 'avatar', 'filename' => 'a.png', 'local_path' => '/tmp/a.png'],
]);
```

## JSON body

```php
$client->post($url, json: ['id' => 1, 'tags' => ['a', 'b']]);  // sets Content-Type: application/json
```

A plain array passed as `$data` is still JSON-encoded (legacy behaviour); pass a
`Mime` or an array containing a `CURLFile` to send multipart instead.

## API

| Sync | Async |
|------|-------|
| `get($url, $headers?, $cookie?, $server?)` | `getAsync(..., key?)` |
| `post($url, $data?, $headers?, $cookie?, $server?, json?)` | `postAsync(..., json?, key?)` |
| `put` / `patch` / `delete($url, $data?, ..., json?)` | `requestAsync($method, $url, $data?, ..., json?, key?)` |
| `custom($url, $method, $data?, ..., json?)` | `pool(array $promises, int $concurrency = 10)` |

**`Response`**: `isSuccess()`, `ok()`, `getStatusCode()`, `getBody()`, `json($assoc=true)`,
`getHeaders()`, `getReason()`, `getElapsed()`.

**Proxy** (`$server`):

```php
$s->get($url, server: ['method' => 'tunnel', 'server' => 'http://1.2.3.4:8080']);
$s->get($url, server: ['method' => 'custom', 'server' => 'http://1.2.3.4:8080', 'auth' => 'user:pass']);
```

## Constructor options

Pass any cURL options to override defaults:

```php
$s = new Pulsar([CURLOPT_TIMEOUT => 120, CURLOPT_SSL_VERIFYPEER => true]);
```

## Project layout

```
PulsarX/
├── autoload.php      # zero-dependency autoloader
├── composer.json     # PSR classmap autoload of src/
├── example.php       # runnable demo
└── src/
    ├── Pulsar.php             # the client (session + impersonate + async)
    ├── Response.php           # immutable response (ok/json/elapsed/…)
    ├── Profile.php            # 32 impersonation targets
    ├── Mime.php               # multipart/form-data + file uploads
    ├── Promise.php            # deferred async request
    ├── CookieJar.php          # domain/path/expiry-scoped cookie jar
    ├── CookieJarInterface.php
    ├── Cookie.php
    ├── Helper.php             # header parsing
    └── PulsarException.php
```
