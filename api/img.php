<?php
/**
 * api/img.php — конвертер WebP/PNG → JPEG для прайс-листа
 * GET /api/img.php?f=some-photo.webp
 * Отдаёт JPEG 200×200 из /assets/img/products/
 */

$f = basename(trim($_GET['f'] ?? ''));
if (!$f || !preg_match('/\.(webp|jpg|jpeg|png|gif)$/i', $f)) {
    http_response_code(400);
    exit('bad request');
}

$path = dirname(__DIR__) . '/assets/img/products/' . $f;
if (!file_exists($path)) {
    http_response_code(404);
    exit('not found');
}

header('Cache-Control: public, max-age=86400');
header('Access-Control-Allow-Origin: *');

/* imagecreatefromstring поддерживает JPEG, PNG, GIF, WebP (PHP 7.2+ GD2) */
$raw = file_get_contents($path);
$src = @imagecreatefromstring($raw);

if (!$src) {
    /* GD не поддерживает формат — отдать как есть */
    $mime = @mime_content_type($path) ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    echo $raw;
    exit;
}

/* Масштаб с сохранением пропорций в 200×200 */
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
