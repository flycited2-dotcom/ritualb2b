<?php

function readBaseCatalog(string $file): array {
    if (!is_file($file)) return [];
    $source = file_get_contents($file);
    if ($source === false || !preg_match('/var\s+PRODUCTS\s*=\s*(\[[\s\S]*?\])\s*;/', $source, $matches)) return [];
    return json_decode($matches[1], true) ?: [];
}

function catalogStockLabel(string $stock): string {
    return match ($stock) {
        'on_request' => 'Под заказ',
        'out_of_stock' => 'Нет в наличии',
        default => 'В наличии',
    };
}

// The caller owns the transaction when a product is saved with related data.
function saveCustomCatalogProduct(PDO $db, string $sku, array $data, int $active): string {
    $db->prepare("INSERT INTO custom_products (id, sku, data_json, active, updated_at)
        VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT(sku) DO UPDATE SET data_json=excluded.data_json, active=excluded.active, updated_at=excluded.updated_at")
        ->execute(['cp_' . bin2hex(random_bytes(8)), $sku, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $active]);
    $db->prepare("INSERT INTO product_overrides (sku, active, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT(sku) DO UPDATE SET active=excluded.active, updated_at=excluded.updated_at")
        ->execute([$sku, $active]);
    $product = $db->prepare('SELECT id FROM custom_products WHERE sku = ?');
    $product->execute([$sku]);
    return (string) $product->fetchColumn();
}
