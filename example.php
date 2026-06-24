<?php
require __DIR__ . '/autoload.php';

$base = 'https://httpbingo.org'; // httpbin-compatible echo server

echo "=== 1) Session: cookies persisten solas ===\n";
$s = new Pulsar();
$s->get("$base/cookies/set?token=abc123");   // el server setea cookie
$r = $s->get("$base/cookies");                // se reenvia sola, scoped por dominio
echo "  cookies vistas por el server: " . json_encode($r->json()['cookies'] ?? []) . "\n";

echo "\n=== 2) Impersonate (32 targets) ===\n";
foreach (['chrome131', 'firefox144', 'safari172_ios', 'chrome131_android'] as $target) {
    $r = (new Pulsar())->impersonate($target)->get("$base/user-agent");
    printf("  %-18s -> %s\n", $target, substr(trim($r->json()['user-agent'] ?? ''), 0, 60));
}

echo "\n=== 3) Multipart upload (Mime) ===\n";
$mime = (new Mime)
    ->addPart('user', data: 'andy')
    ->addPart('doc', filename: 'hi.txt', contentType: 'text/plain', data: 'contenido');
$r = $s->post("$base/post", $mime);
$j = $r->json();
echo "  form=" . json_encode($j['form'] ?? null) . "  files=" . json_encode($j['files'] ?? null) . "\n";

echo "\n=== 4) JSON body (json:) ===\n";
$r = $s->post("$base/post", json: ['a' => 1, 'b' => [2, 3]]);
echo "  json=" . json_encode($r->json()['json'] ?? null) . "\n";

echo "\n=== 5) Async: peticiones en paralelo ===\n";
$a = new Pulsar();
$promises = [
    $a->getAsync("$base/uuid", key: 'u1'),
    $a->getAsync("$base/uuid", key: 'u2'),
    $a->postAsync("$base/post", json: ['x' => 1], key: 'p1'),
];
$responses = $a->pool($promises, concurrency: 3);
foreach ($responses as $key => $res) {
    printf("  [%s] HTTP %d en %.3fs\n", $key, $res->getStatusCode(), $res->getElapsed());
}
