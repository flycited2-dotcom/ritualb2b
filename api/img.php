<?php
/**
 * api/img.php — оптимизатор изображений товаров
 *
 * Два режима:
 *   1. Legacy (без ?w)  — JPEG 200×200. Используется прайс-листом (pricelist.js),
 *                          поведение полностью сохранено.
 *   2. Optimized (?w=N) — ресайз до ширины N (без апскейла), формат выбирается
 *                          по заголовку Accept (WebP, иначе JPEG), качество ?q
 *                          (по умолчанию 86). Результат кэшируется на диск, так
 *                          что генерация происходит только при первом запросе.
 *
 *   /api/img.php?f=photo.png&w=600                 → миниатюра для карточек/карусели
 *   /api/img.php?f=photo.png&w=1100                → детальная карточка
 *   /api/img.php?f=etalon-hero-block.png&dir=root&w=1920&q=88
 */

$f = basename(trim($_GET['f'] ?? ''));
if (!$f || !preg_match('/\.(webp|jpg|jpeg|png|gif)$/i', $f)) {
    http_response_code(400);
    exit('bad request');
}

/* Белый список исходных каталогов */
$dirs = [
    ''         => '/assets/img/products/',
    'products' => '/assets/img/products/',
    'root'     => '/assets/img/',
];
$dirKey = $_GET['dir'] ?? '';
if (!isset($dirs[$dirKey])) {
    http_response_code(400);
    exit('bad dir');
}
$path = dirname(__DIR__) . $dirs[$dirKey] . $f;
if (!file_exists($path)) {
    http_response_code(404);
    exit('not found');
}

$w = (int)($_GET['w'] ?? 0);

/* ── Legacy: JPEG 200×200 (прайс-лист) ─────────────────── */
if ($w <= 0) {
    header('Cache-Control: public, max-age=86400');
    header('Access-Control-Allow-Origin: *');

    $raw = file_get_contents($path);
    $src = @imagecreatefromstring($raw);
    if (!$src) {
        $mime = @mime_content_type($path) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        echo $raw;
        exit;
    }
    $ow = imagesx($src);
    $oh = imagesy($src);
    $dst = imagecreatetruecolor(200, 200);
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefill($dst, 0, 0, $white);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, 200, 200, $ow, $oh);
    imagedestroy($src);
    header('Content-Type: image/jpeg');
    imagejpeg($dst, null, 87);
    imagedestroy($dst);
    exit;
}

/* ── Optimized: ресайз + WebP/JPEG + дисковый кэш ──────── */
$w = min($w, 2400);
$q = (int)($_GET['q'] ?? 86);
$q = max(40, min(95, $q));

$accept  = $_SERVER['HTTP_ACCEPT'] ?? '';
$useWebp = function_exists('imagewebp') && strpos($accept, 'image/webp') !== false;
$mime    = $useWebp ? 'image/webp' : 'image/jpeg';
$ext     = $useWebp ? 'webp' : 'jpg';

$cacheDir = dirname(__DIR__) . '/assets/img/cache/';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0775, true);
}
$cacheFile = $cacheDir . sha1($dirKey . '|' . $f . '|' . $w . '|' . $q) . '.' . $ext;
$srcMtime  = filemtime($path);

/* Кэш актуален — отдать сразу */
if (is_file($cacheFile) && filemtime($cacheFile) >= $srcMtime) {
    serveFile($cacheFile, $mime);
    exit;
}

/* Генерация */
$raw = file_get_contents($path);
$src = @imagecreatefromstring($raw);
if (!$src) {
    /* GD не прочитал формат — отдать оригинал как есть */
    header('Cache-Control: public, max-age=86400');
    header('Content-Type: ' . (@mime_content_type($path) ?: 'application/octet-stream'));
    echo $raw;
    exit;
}
$ow = imagesx($src);
$oh = imagesy($src);
$tw = min($w, $ow);                       // не увеличиваем сверх оригинала
$th = max(1, (int)round($oh * ($tw / $ow)));
$dst = imagecreatetruecolor($tw, $th);

if ($useWebp) {
    /* Сохранить прозрачность для WebP */
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $bg = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefilledrectangle($dst, 0, 0, $tw, $th, $bg);
} else {
    /* Свести на белый фон для JPEG */
    $bg = imagecolorallocate($dst, 255, 255, 255);
    imagefilledrectangle($dst, 0, 0, $tw, $th, $bg);
}
imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $ow, $oh);
imagedestroy($src);

/* Атомарная запись в кэш */
$tmp = $cacheFile . '.' . getmypid() . '.tmp';
if ($useWebp) {
    imagewebp($dst, $tmp, $q);
} else {
    imagejpeg($dst, $tmp, $q);
}
imagedestroy($dst);
if (!@rename($tmp, $cacheFile)) {
    @unlink($cacheFile);
    @rename($tmp, $cacheFile);
}

serveFile(is_file($cacheFile) ? $cacheFile : $tmp, $mime);

/* ── Отдача файла с длинным кэшем и поддержкой 304 ─────── */
function serveFile($file, $mime) {
    $mtime = @filemtime($file) ?: time();
    $etag  = '"' . md5($file . $mtime . filesize($file)) . '"';

    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=2592000');  // 30 дней + ревалидация по ETag
    header('Access-Control-Allow-Origin: *');
    header('Vary: Accept');
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');

    $inm = trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
    $ims = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
    if (($inm && $inm === $etag) || ($ims && @strtotime($ims) >= $mtime)) {
        http_response_code(304);
        exit;
    }
    header('Content-Length: ' . filesize($file));
    readfile($file);
}
