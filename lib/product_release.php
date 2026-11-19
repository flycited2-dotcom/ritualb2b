<?php

require_once __DIR__ . '/catalog.php';

class ProductReleaseError extends RuntimeException {}

function migrateProductReleases(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS product_releases (
        id TEXT PRIMARY KEY,
        draft TEXT NOT NULL,
        draft_hash TEXT NOT NULL,
        confirmation TEXT,
        result TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS product_release_links (
        onec_id TEXT PRIMARY KEY,
        site_sku TEXT NOT NULL UNIQUE
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS release_price_wait (
        release_id TEXT PRIMARY KEY, onec_id TEXT NOT NULL
    )");
}

function releaseUuid(mixed $value, string $field): string {
    if (!is_string($value) || !preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/iD', $value)
        || $value === '00000000-0000-0000-0000-000000000000') {
        throw new ProductReleaseError("Некорректный идентификатор $field", 422);
    }
    return strtolower($value);
}

function releaseText(mixed $value, string $field, int $max): string {
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || trim($value) === ''
        || mb_strlen($value) > $max || preg_match('/[<>\p{C}]/u', $value)) {
        throw new ProductReleaseError("Некорректное поле $field", 422);
    }
    return trim($value);
}

function releaseInt(mixed $value, string $field, int $min, int $max): int {
    if (!is_int($value) || $value < $min || $value > $max) {
        throw new ProductReleaseError("Некорректное поле $field", 422);
    }
    return $value;
}

function encodeRelease(array $data): string {
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function readProductRelease(PDO $db, string $id): array {
    $query = $db->prepare('SELECT * FROM product_releases WHERE id = ?');
    $query->execute([$id]);
    $row = $query->fetch();
    if (!$row) throw new ProductReleaseError('Заявка на выпуск не найдена', 404);
    return $row;
}

function productReleaseStatus(array $row): array {
    if ($row['result'] !== null) return json_decode($row['result'], true, 512, JSON_THROW_ON_ERROR);
    return ['release_id' => $row['id'], 'state' => 'waiting_1c'];
}

function enqueueProductRelease(PDO $db, array $body): array {
    $id = releaseUuid($body['release_id'] ?? null, 'release_id');
    $label = $body['label'] ?? 'new';
    $shape = $body['shape'] ?? null;
    if (!in_array($label, ['new', 'regular'], true)
        || !in_array($shape, [null, 'oval', 'circle', 'heart', 'drop', 'other'], true)) {
        throw new ProductReleaseError('Проверьте метку и форму венка', 422);
    }
    $onecId = isset($body['onec_id']) ? releaseUuid($body['onec_id'], 'onec_id') : null;
    $photo = $body['photo_base64'] ?? null;
    if (!is_string($photo) || strlen($photo) > 8388608 || ($bytes = base64_decode($photo, true)) === false) {
        throw new ProductReleaseError('Приложите фото до 6 МБ', 422);
    }
    $image = @getimagesizefromstring($bytes);
    $format = match ($image[2] ?? null) {
        IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', default => null,
    };
    if ($format === null || min($image[0], $image[1]) < 16 || max($image[0], $image[1]) > 6000) {
        throw new ProductReleaseError('Поддерживаются JPEG, PNG и WebP размером от 16 до 6000 пикселей по каждой стороне', 422);
    }
    $draft = encodeRelease([
        'release_id' => $id,
        'onec_id' => $onecId,
        'site_sku' => isset($body['site_sku']) ? releaseText($body['site_sku'], 'site_sku', 80) : null,
        'name' => releaseText($body['name'] ?? null, 'name', 80),
        'height_cm' => releaseInt($body['height_cm'] ?? null, 'height_cm', 1, 500),
        'width_cm' => releaseInt($body['width_cm'] ?? null, 'width_cm', 1, 500),
        'shape' => $shape,
        'price_rub' => isset($body['price_rub']) ? releaseInt($body['price_rub'], 'price_rub', 1, 10000000) : null,
        'label_copies' => releaseInt($body['label_copies'] ?? 1, 'label_copies', 1, 1000),
        'label' => $label,
        'photo_format' => $format,
        'photo_base64' => base64_encode($bytes),
    ]);
    $hash = hash('sha256', $draft);
    $db->prepare('INSERT INTO product_releases (id, draft, draft_hash) VALUES (?, ?, ?) ON CONFLICT(id) DO NOTHING')
        ->execute([$id, $draft, $hash]);
    $row = readProductRelease($db, $id);
    if (!hash_equals($row['draft_hash'], $hash)) {
        throw new ProductReleaseError('Эта заявка уже была принята с другими данными', 409);
    }
    return productReleaseStatus($row);
}

function nextProductRelease(PDO $db, ?string $afterId = null): ?array {
    $query = $db->prepare('SELECT draft FROM product_releases WHERE result IS NULL
        AND rowid > COALESCE((SELECT rowid FROM product_releases WHERE id = ?), 0) ORDER BY rowid LIMIT 1');
    $query->execute([$afterId]);
    $draft = $query->fetchColumn();
    return $draft === false ? null : json_decode($draft, true, 512, JSON_THROW_ON_ERROR);
}

function releaseWaitingPrice(PDO $db, array $body): array {
    $id = releaseUuid($body['release_id'] ?? null, 'release_id');
    $onec = releaseUuid($body['onec_id'] ?? null, 'onec_id');
    $row = readProductRelease($db, $id);
    if ($row['result'] !== null) return productReleaseStatus($row);
    $draft = json_decode($row['draft'], true, 512, JSON_THROW_ON_ERROR);
    if (($draft['onec_id'] ?? $id) !== $onec) throw new ProductReleaseError('Другая номенклатура в заявке', 409);
    $db->prepare('INSERT OR IGNORE INTO release_price_wait (release_id, onec_id) VALUES (?, ?)')->execute([$id, $onec]);
    return releaseCurrentStatus($db, $id);
}

function releaseCurrentStatus(PDO $db, string $id): array {
    $row = readProductRelease($db, $id);
    if ($row['result'] !== null) return productReleaseStatus($row);
    $query = $db->prepare('SELECT onec_id FROM release_price_wait WHERE release_id = ?');
    $query->execute([$id]);
    $onec = $query->fetchColumn();
    return $onec === false ? productReleaseStatus($row) : ['release_id' => $id, 'state' => 'waiting_price', 'onec_id' => $onec];
}

function readReleaseConfirmation(array $body): array {
    $type = $body['barcode_type'] ?? null;
    $barcode = $body['barcode'] ?? null;
    $valid = false;
    if ($type === 'code128' && is_string($barcode)) {
        $valid = (bool) preg_match('/^[A-Za-z0-9._-]{1,18}$/D', $barcode);
    } elseif ($type === 'ean13' && is_string($barcode) && preg_match('/^[0-9]{13}$/D', $barcode)) {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) $sum += (int) $barcode[$i] * ($i % 2 === 0 ? 1 : 3);
        $valid = (10 - $sum % 10) % 10 === (int) $barcode[12];
    }
    if (!$valid) throw new ProductReleaseError('Нужен действительный EAN-13 или внутренний Code 128', 422);
    return [
        'onec_id' => releaseUuid($body['onec_id'] ?? null, 'onec_id'),
        'article' => releaseText($body['article'] ?? null, 'article', 64),
        'barcode' => $barcode,
        'barcode_type' => $type,
    ];
}

function productionCatalogTarget(PDO $db, string $catalogFile, array $draft, string $onecId): array {
    $findLink = $db->prepare('SELECT site_sku FROM product_release_links WHERE onec_id = ?');
    $findLink->execute([$onecId]);
    $bound = $findLink->fetchColumn();
    if ($bound !== false && $draft['site_sku'] !== null && $draft['site_sku'] !== $bound) {
        throw new ProductReleaseError('У позиции 1С уже есть другой артикул сайта', 409);
    }
    $sku = $bound ?: ($draft['site_sku'] ?? 'venok-' . str_replace('-', '', $onecId));
    $findOwner = $db->prepare('SELECT onec_id FROM product_release_links WHERE site_sku = ?');
    $findOwner->execute([$sku]);
    $owner = $findOwner->fetchColumn();
    if ($owner !== false && $owner !== $onecId) {
        throw new ProductReleaseError('Карточка уже связана с другой позицией 1С', 409);
    }
    $target = null;
    foreach (readBaseCatalog($catalogFile) as $item) {
        if ($item['sku'] === $sku) {
            $target = ['base_id' => (string) $item['id'], 'data' => $item];
            break;
        }
    }
    if ($target === null) {
        $custom = $db->prepare('SELECT data_json FROM custom_products WHERE sku = ?');
        $custom->execute([$sku]);
        $data = $custom->fetchColumn();
        if ($data !== false) $target = ['base_id' => null, 'data' => json_decode($data, true, 512, JSON_THROW_ON_ERROR)];
    }
    if ($bound === false && $draft['site_sku'] !== null && $target === null) {
        throw new ProductReleaseError('Карточка с указанным артикулом не найдена', 409);
    }
    if ($bound === false && $draft['site_sku'] === null && $target !== null) {
        throw new ProductReleaseError('Укажите артикул карточки, которую хотите связать с 1С', 409);
    }
    if ($target !== null && ($target['data']['group'] ?? '') !== 'venki') {
        throw new ProductReleaseError('Выбранная карточка относится к другой категории', 409);
    }
    return [$sku, $target['base_id'] ?? null, $target['data'] ?? []];
}

function writeProductionPhoto(string $directory, array $draft): string {
    $name = 'release_' . str_replace('-', '', $draft['release_id']) . '.' . $draft['photo_format'];
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Cannot create the product photo directory');
    }
    $bytes = base64_decode($draft['photo_base64'], true);
    $path = $directory . '/' . $name;
    if (is_file($path) && hash_file('sha256', $path) === hash('sha256', $bytes)) return $name;
    $tmp = tempnam($directory, '.release-');
    if ($tmp === false) throw new RuntimeException('Cannot create a temporary product image');
    try {
        if (file_put_contents($tmp, $bytes) !== strlen($bytes) || !chmod($tmp, 0644) || !rename($tmp, $path)) {
            throw new RuntimeException('Cannot write the product image');
        }
    } finally {
        if (is_file($tmp)) unlink($tmp);
    }
    return $name;
}

function confirmProductRelease(PDO $db, string $catalogFile, string $photoDir, array $body): array {
    $id = releaseUuid($body['release_id'] ?? null, 'release_id');
    $confirmation = readReleaseConfirmation($body);
    if (isset($body['wholesale_price_rub'])) {
        $confirmation['wholesale_price_rub'] = releaseInt($body['wholesale_price_rub'], 'wholesale_price_rub', 1, 10000000);
    }
    $confirmationJson = encodeRelease($confirmation);
    $db->beginTransaction();
    try {
        // Serialize receipt application before reading the draft and SKU mapping.
        $db->prepare('UPDATE product_releases SET id = id WHERE id = ?')->execute([$id]);
        $row = readProductRelease($db, $id);
        if ($row['confirmation'] !== null) {
            if ($row['confirmation'] !== $confirmationJson) {
                throw new ProductReleaseError('Для этого выпуска уже получено другое подтверждение 1С', 409);
            }
            $db->commit();
            return productReleaseStatus($row);
        }
        $draft = json_decode($row['draft'], true, 512, JSON_THROW_ON_ERROR);
        $price = $confirmation['wholesale_price_rub'] ?? null;
        if ($price === null) throw new ProductReleaseError('Для публикации нужна действующая цена «Опт» из УТ', 422);
        if ($draft['onec_id'] !== null && $draft['onec_id'] !== $confirmation['onec_id']) {
            throw new ProductReleaseError('Ответ 1С не совпадает с выбранной номенклатурой', 409);
        }
        $waiting = $db->prepare('SELECT onec_id FROM release_price_wait WHERE release_id = ?');
        $waiting->execute([$id]);
        $expected = $waiting->fetchColumn();
        if ($expected !== false && $expected !== $confirmation['onec_id']) {
            throw new ProductReleaseError('Оптовая цена относится к другой номенклатуре', 409);
        }
        [$sku, $productId, $existing] = productionCatalogTarget($db, $catalogFile, $draft, $confirmation['onec_id']);
        $db->prepare('INSERT INTO product_release_links (onec_id, site_sku) VALUES (?, ?) ON CONFLICT(onec_id) DO NOTHING')
            ->execute([$confirmation['onec_id'], $sku]);
        $photo = writeProductionPhoto($photoDir, $draft);
        $dimensions = "{$draft['height_cm']} × {$draft['width_cm']} см";
        $isBaseProduct = $productId !== null;
        if (!$isBaseProduct) {
            $data = array_replace([
                'brand' => 'Авторская мастерская', 'brandCode' => 'venki', 'group' => 'venki', 'series' => 'Венки',
                'size' => '-', 'btu' => '-', 'area' => 0, 'descShort' => '', 'descLong' => '', 'benefits' => [],
                'stock' => 'on_request', 'stockLabel' => catalogStockLabel('on_request'),
            ], $existing, [
                'model' => $draft['name'], 'dimensions' => $dimensions, 'price' => $price,
                'photo' => $photo, 'photos' => [$photo],
            ]);
            $productId = saveCustomCatalogProduct($db, $sku, $data, 1);
        }
        $db->prepare("INSERT INTO product_overrides (sku, active, badge, badge_label, model_override, dimensions,
                price_override, photos_override, updated_at) VALUES (?, 1, ?, '', ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(sku) DO UPDATE SET active=1, badge=excluded.badge, badge_label='', model_override=excluded.model_override,
                dimensions=excluded.dimensions, price_override=excluded.price_override,
                photos_override=excluded.photos_override, updated_at=CURRENT_TIMESTAMP")
            ->execute([
                $sku, $draft['label'], $isBaseProduct ? $draft['name'] : null, $isBaseProduct ? $dimensions : null,
                $isBaseProduct ? $price : null,
                $isBaseProduct ? encodeRelease([$photo]) : null,
            ]);
        $result = [
            'release_id' => $id, 'state' => 'published', 'product_id' => $productId, 'site_sku' => $sku,
            'catalog_path' => '/?p=' . rawurlencode($productId),
            'onec_id' => $confirmation['onec_id'],
            'wholesale_price_rub' => $price,
            'label' => [
                'release_id' => $id, 'name' => $draft['name'], 'dimensions' => $dimensions,
                'article' => $confirmation['article'], 'barcode' => $confirmation['barcode'],
                'barcode_type' => $confirmation['barcode_type'], 'copies' => $draft['label_copies'],
            ],
        ];
        unset($draft['photo_base64']);
        $db->prepare('UPDATE product_releases SET draft = ?, confirmation = ?, result = ? WHERE id = ?')
            ->execute([encodeRelease($draft), $confirmationJson, encodeRelease($result), $id]);
        $db->commit();
        return $result;
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}
