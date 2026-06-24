<div align="center">

# ✦ PulsarX

### A `requests`-style HTTP client for PHP, built on cURL

**The browser-impersonating HTTP client PHP was missing.**
Guzzle sends requests — PulsarX sends requests that look like Chrome.

In-memory session cookies · browser impersonation · async parallel requests · multipart uploads

⋆ ˚ ｡ ⋆ ୨ ⋆ ˚ ｡ ⋆

[![PHP](https://img.shields.io/badge/PHP-%E2%89%A5%208.1-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-22c55e)](LICENSE)
[![Built on cURL](https://img.shields.io/badge/built%20on-cURL-073551?logo=curl&logoColor=white)](https://curl.se/)
[![Impersonation targets](https://img.shields.io/badge/impersonate-32%20targets-f59e0b)](#-impersonation)
[![Author](https://img.shields.io/badge/by-Vxsilisk-6366f1)](https://github.com/Vxsilisk)

<br>

[**Why PulsarX**](#-why-pulsarx) · [**Install**](#-installation) · [**Quick start**](#-quick-start) · [**Sessions**](#-sessions--cookies) · [**Impersonate**](#-impersonation) · [**Anti-detection**](#-anti-detection) · [**Async**](#-async-parallel) · [**Uploads**](#-multipart--file-uploads) · [**API**](#-api-reference)

</div>

---

## ✦ Features

|   | Feature | What it does |
|---|---------|--------------|
| ❍ | **Session model** | Cookies persist in memory across requests, scoped by domain / path / expiry — like `requests.Session`. No files touch disk. |
| ◈ | **Impersonation** | 32 version-pinned browser fingerprints (Chrome, Edge, Firefox, Safari — desktop, Android & iOS). |
| ✸ | **Anti-detection** | Coherent `Sec-Fetch-Site` + `Referer` chains (`stealth()`) and whole-profile rotation (`rotate()`). |
| ⟡ | **Async** | Parallel requests over `curl_multi` with a rolling concurrency window and near-zero idle CPU. |
| ❖ | **Multipart** | `multipart/form-data` and file uploads via a fluent `Mime` builder (on-disk or in-memory). |
| ⌗ | **JSON-first** | `json:` body param, plus `Response::json()`, `ok()` and `getElapsed()`. |
| ⬡ | **Zero deps** | One class per file, a tiny autoloader, and optional Composer. Just PHP + ext-curl. |

---

## ✦ Why PulsarX?

PHP has great HTTP clients — but **none of them can look like a browser**. That's the gap
PulsarX fills: it's the PHP answer to Python's `curl_cffi`.

| | **PulsarX** | Guzzle | Symfony HttpClient | curl_cffi *(Python)* |
|---|:---:|:---:|:---:|:---:|
| Language | **PHP** | PHP | PHP | Python |
| Browser impersonation | **✓ 32 targets** | ✗ | ✗ | ✓ |
| Anti-detection (`Sec-Fetch` / `Referer`) | **✓** | ✗ | ✗ | partial |
| Fingerprint rotation | **✓** | ✗ | ✗ | ✓ |
| Async / parallel | **✓** `curl_multi` | ✓ | ✓ | ✓ |
| Scoped session cookies | **✓** | ✓ | ✓ | ✓ |
| Multipart / file uploads | **✓** | ✓ | ✓ | ✓ |
| Runtime dependencies | **none** | several | none | C / curl-impersonate |

> On stock OpenSSL the TLS layer is *approximate*, not a byte-exact JA3 — see
> [Anti-detection](#-anti-detection). For an exact fingerprint, run PHP against a
> `curl-impersonate` libcurl (auto-detected).

---

## ⬡ Installation

**With Composer**

```bash
composer require vxsilisk/pulsarx
```

**Without Composer** — require the bundled autoloader:

```php
require __DIR__ . '/autoload.php';
```

> **Requirements:** PHP ≥ 8.1 with the `curl` and `json` extensions.

---

## ✷ Quick start

```php
require __DIR__ . '/autoload.php';

$s = new Pulsar();
$r = $s->get('https://example.com');

$r->getStatusCode();   // 200
$r->ok();              // true
$r->getBody();         // raw body
$r->json();            // decoded JSON
$r->getElapsed();      // transfer time in seconds
```

---

## ❍ Sessions & cookies

A `Pulsar` instance **is** a session. Cookies set by the server are stored in memory
and re-sent automatically on later requests — correctly scoped by **domain, path and
expiry**.

```php
$s = new Pulsar();
$s->get('https://site.com/login');            // server sets cookies
$s->post('https://site.com/cart', $payload);   // cookies sent automatically
```

Cookies are **isolated by host** — a cookie from `shop.com` is never leaked to
`api.stripe.com`. You can also pre-seed the jar:

```php
$jar = new CookieJar();
$jar->add(new Cookie(name: 'session', value: 'abc123'));

$s->get('https://site.com', cookie: $jar);
```

---

## ◈ Impersonation

Mimic a real browser's fingerprint — User-Agent, the full header set **in browser
order**, TLS cipher list, EC curves, HTTP/2 and Brotli/ZSTD. Chainable:

```php
$s = (new Pulsar())->impersonate('chrome131');
$r = $s->get('https://protected-site.com');
```

Your own headers merge **on top** of the profile (overriding by name, preserving order):

```php
$s->impersonate('chrome')->get($url, headers: ['Referer: https://google.com']);
```

<details>
<summary><b>◇ All 32 targets</b> (or call <code>Pulsar::impersonateTargets()</code> at runtime)</summary>

<br>

| Browser | Targets |
|---------|---------|
| ❯ **Chrome** (desktop) | `chrome99` `chrome110` `chrome116` `chrome119` `chrome120` `chrome124` `chrome131` `chrome133` `chrome136` `chrome142` `chrome146` `chrome` |
| ❯ **Chrome** (Android) | `chrome99_android` `chrome131_android` `chrome_android` |
| ❯ **Edge** | `edge99` `edge101` `edge131` `edge` |
| ❯ **Firefox** | `firefox133` `firefox135` `firefox144` `firefox` |
| ❯ **Safari** (macOS) | `safari153` `safari170` `safari180` `safari260` `safari` |
| ❯ **Safari** (iOS) | `safari172_ios` `safari180_ios` `safari_ios` |
| ❯ **Tor** | `tor` |

> Bare names like `chrome`, `safari`, `firefox` are aliases for the latest stable build.

</details>

```php
$s->impersonate('chrome131_android');   // mobile Chrome
$s->impersonate('safari172_ios');       // iOS Safari
```

> [!NOTE]
> **About JA3 accuracy.** PulsarX runs on stock **OpenSSL**, so impersonation matches
> the HTTP layer and the TLS cipher *ordering* — strong, but not a byte-exact JA3/JA4
> (TLS extension order, GREASE, ALPS and HTTP/2 SETTINGS require BoringSSL).
>
> If you run PHP against a [`curl-impersonate`](https://github.com/lwthiker/curl-impersonate)
> libcurl, PulsarX **auto-detects** the extra options (ALPS, cert compression, extension
> permutation, no-server-push) and produces an exact fingerprint — **no code change needed**.

---

## ✸ Anti-detection

Bot detection checks **coherence across layers** — it catches you when something doesn't
line up. PulsarX gives you two tools beyond raw impersonation, both fully working on stock
OpenSSL.

### Behavioural coherence — `stealth()`

A naive client always sends `Sec-Fetch-Site: none` with no `Referer`. A real browser
derives both from where it navigated *from*. Enable `stealth()` and PulsarX maintains that
context across the session automatically:

```php
$s = (new Pulsar())->impersonate('chrome131')->stealth();

$s->get('https://shop.com/');           // Sec-Fetch-Site: none      (direct, no Referer)
$s->get('https://shop.com/cart');       // Sec-Fetch-Site: same-origin · Referer: https://shop.com/
$s->get('https://api.shop.com/data');   // Sec-Fetch-Site: same-site · Referer: https://shop.com/cart
$s->get('https://other.com/');          // Sec-Fetch-Site: cross-site · Referer: https://api.shop.com/  (origin only)
```

The `Referer` honours the default **strict-origin-when-cross-origin** policy — full URL
within a site, origin-only across sites. Anything you set by hand always wins.

### Fingerprint rotation — `rotate()`

Since Chrome 110, browsers randomise TLS extension order — so a **single static
fingerprint is itself suspicious**. Rotation picks a fresh, *coherent* profile (UA +
sec-ch-ua + TLS all in sync) on every request:

```php
$s = (new Pulsar())->rotate();              // realistic pool of current browsers
$s = (new Pulsar())->rotate(['chrome146', 'firefox144', 'safari260']);  // your own pool
$s = (new Pulsar())->impersonate('random'); // one random profile, fixed for the session
```

> [!IMPORTANT]
> Rotation varies **whole profiles**, never fields within one. Randomising the
> `sec-ch-ua` brand order or mixing a Chrome UA with a Firefox header set is a *mismatch
> signal* that makes you easier to flag, not harder — so PulsarX never does it.
>
> None of this forges a byte-exact JA3/JA4 on OpenSSL, and no HTTP client clears
> JavaScript challenges (Cloudflare Turnstile et al.). For hardened anti-bots you still
> need a `curl-impersonate` libcurl or a headless browser.

---

## ⟡ Async (parallel)

Build promises with `getAsync()` / `postAsync()` / `requestAsync()`, then resolve a
batch with `pool()`. It uses `curl_multi` with a **rolling concurrency window** and
`curl_multi_select`, so idle CPU stays near zero while requests are in flight.

```php
$s = new Pulsar();

$promises = [
    $s->getAsync('https://api.com/a', key: 'a'),
    $s->getAsync('https://api.com/b', key: 'b'),
    $s->postAsync('https://api.com/c', json: ['x' => 1], key: 'c'),
];

$responses = $s->pool($promises, concurrency: 10);  // array keyed by `key`

echo $responses['a']->getStatusCode();
```

Prefer callbacks? Each promise resolves as soon as it finishes:

```php
$s->getAsync($url)->then(fn(Response $r) => print($r->getStatusCode()));
$s->pool($promises);
```

---

## ❖ Multipart & file uploads

A fluent `multipart/form-data` builder — PulsarX's take on `curl_cffi`'s `CurlMime`:

```php
$mime = (new Mime)
    ->addPart('username', data: 'andy')
    ->addPart('avatar', filename: 'a.png', contentType: 'image/png', localPath: '/tmp/a.png')
    ->addPart('inline', filename: 'note.txt', data: 'in-memory bytes'); // no temp file needed

$s->post($url, $mime);
```

Or declaratively, from a list:

```php
$mime = Mime::fromList([
    ['name' => 'username', 'data' => 'andy'],
    ['name' => 'avatar', 'filename' => 'a.png', 'local_path' => '/tmp/a.png'],
]);
```

---

## ⌗ JSON body

```php
$s->post($url, json: ['id' => 1, 'tags' => ['a', 'b']]);  // sets Content-Type: application/json
```

> A plain array passed as `$data` is still JSON-encoded (legacy behaviour). To send
> multipart instead, pass a `Mime` or an array containing a `CURLFile`.

---

## ⇄ Proxy

```php
// HTTP tunnel
$s->get($url, server: ['method' => 'tunnel', 'server' => 'http://1.2.3.4:8080']);

// Authenticated proxy
$s->get($url, server: ['method' => 'custom', 'server' => 'http://1.2.3.4:8080', 'auth' => 'user:pass']);
```

---

## ◇ Constructor options

Override any cURL default by passing options to the constructor:

```php
$s = new Pulsar([
    CURLOPT_TIMEOUT        => 120,
    CURLOPT_SSL_VERIFYPEER => true,
]);
```

---

## ❯ API reference

**HTTP methods** — every method returns a `Response`.

| Sync | Async (returns `Promise`) |
|------|---------------------------|
| `get($url, $headers?, $cookie?, $server?)` | `getAsync(..., $key?)` |
| `post($url, $data?, $headers?, $cookie?, $server?, $json?)` | `postAsync(..., $json?, $key?)` |
| `put` / `patch` / `delete($url, $data?, …, $json?)` | `requestAsync($method, $url, $data?, …, $json?, $key?)` |
| `custom($url, $method, $data?, …, $json?)` | `pool(array $promises, int $concurrency = 10): Response[]` |

**Impersonation**

| Method | Returns |
|--------|---------|
| `impersonate(string\|Profile $target)` | `$this` — fixed profile (`'random'` picks one) |
| `rotate(?array $targets = null)` | `$this` — fresh coherent profile per request |
| `stealth(bool $on = true)` | `$this` — auto `Sec-Fetch-Site` + `Referer` chain |
| `clearImpersonation()` | `$this` |
| `Pulsar::impersonateTargets()` | `string[]` of every target |

**`Response`**

| Method | Description |
|--------|-------------|
| `isSuccess()` | transport succeeded |
| `ok()` | status in `[200, 400)` |
| `getStatusCode()` | HTTP status code |
| `getBody()` | raw response body |
| `json($assoc = true)` | decoded JSON body |
| `getHeaders()` | request + response headers |
| `getReason()` | error message, if any |
| `getElapsed()` | transfer time in seconds |

---

## ❏ Project layout

```
PulsarX/
├── autoload.php          # zero-dependency autoloader
├── composer.json         # classmap autoload of src/
├── example.php           # runnable demo
└── src/
    ├── Pulsar.php             # the client (session · impersonate · async)
    ├── Response.php           # immutable response (ok / json / elapsed …)
    ├── Profile.php            # 32 impersonation targets
    ├── Mime.php               # multipart/form-data + file uploads
    ├── Promise.php            # deferred async request
    ├── CookieJar.php          # domain/path/expiry-scoped cookie jar
    ├── CookieJarInterface.php
    ├── Cookie.php
    ├── Helper.php             # header parsing
    └── PulsarException.php
```

> Run `php example.php` to see sessions, impersonation, multipart, JSON and async in action.

---

<div align="center">

⋆ ˚ ｡ ⋆ ୨ ⋆ ˚ ｡ ⋆

**PulsarX** — made by [**Vxsilisk**](https://github.com/Vxsilisk) · [MIT License](LICENSE)

</div>
