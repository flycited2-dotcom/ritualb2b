<?php

require_once __DIR__ . '/../db/init.php';
require_once __DIR__ . '/../lib/product_release.php';

header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    jsonResponse(['ok' => false, 'error' => 'POST only'], 405);
}

$action = $_GET['action'] ?? '';
$keyName = match ($action) {
    'enqueue', 'status' => 'RITUAL_RELEASE_PRODUCER_KEY',
    'next', 'confirm' => 'RITUAL_RELEASE_1C_KEY',
    default => null,
};
if ($keyName === null) jsonResponse(['ok' => false, 'error' => 'Unknown action'], 404);
$key = getenv($keyName);
if (!is_string($key) || strlen($key) < 32
    || !hash_equals('Bearer ' . $key, $_SERVER['HTTP_AUTHORIZATION'] ?? '')) {
    jsonResponse(['ok' => false, 'error' => 'Unauthorized'], 401);
}

try {
    $input = file_get_contents('php://input', false, null, 0, 8454145);
    if ($input === false || strlen($input) > 8454144) {
        jsonResponse(['ok' => false, 'error' => 'Request too large'], 413);
    }
    $body = json_decode($input, true, 16, JSON_THROW_ON_ERROR);
} catch (JsonException $error) {
    jsonResponse(['ok' => false, 'error' => 'Invalid JSON'], 400);
}
if (!is_array($body)) jsonResponse(['ok' => false, 'error' => 'Expected JSON object'], 400);

try {
    $db = getDB();
    $db->exec('PRAGMA busy_timeout = 5000');
    migrateProductReleases($db);
    $result = match ($action) {
        'enqueue' => enqueueProductRelease($db, $body),
        'next' => ['job' => nextProductRelease($db)],
        'confirm' => confirmProductRelease($db, __DIR__ . '/../products.js', __DIR__ . '/../assets/img/products', $body),
        'status' => productReleaseStatus(readProductRelease($db, releaseUuid($body['release_id'] ?? null, 'release_id'))),
    };
    jsonResponse(['ok' => true] + $result);
} catch (ProductReleaseError $error) {
    jsonResponse(['ok' => false, 'error' => $error->getMessage()], $error->getCode());
} catch (Throwable $error) {
    error_log('Product release failed: ' . $error->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Product release unavailable'], 500);
}
