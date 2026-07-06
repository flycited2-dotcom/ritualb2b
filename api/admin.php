<?php
/**
 * Admin API
 */

require __DIR__ . '/../db/init.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// Public product overrides — no auth required
$action = $_GET['action'] ?? '';
if ($action === 'products_overrides_public') {
    $db = getDB();
    // Return ALL overrides including active=0 (frontend filters hidden products)
    $rows = $db->query("SELECT sku, description, badge, badge_label, price_override, stock_override, size_override, desc_short, active, model_override, brand_override, dimensions, desc_long_override, benefits_override, photos_override FROM product_overrides")->fetchAll();
    $map = [];
    foreach ($rows as $r) { $map[$r['sku']] = $r; }
    // Custom products (manually added)
    $customRows = $db->query("SELECT id, sku, data_json FROM custom_products WHERE active = 1")->fetchAll();
    $custom = [];
    foreach ($customRows as $cr) {
        $d = json_decode($cr['data_json'], true) ?: [];
        $d['id']  = $cr['id'];
        $d['sku'] = $cr['sku'];
        $d['_custom'] = true;
        $custom[] = $d;
    }
    jsonResponse(['ok' => true, 'overrides' => $map, 'custom_products' => $custom]);
    exit;
}

// Upload product photo — public (admin handles auth separately, but we protect with session check)
if ($action === 'upload_photo') {
    $db = getDB(); adminRequire();
    if (empty($_FILES['photo'])) jsonResponse(['ok' => false, 'error' => 'Нет файла'], 400);
    $f = $_FILES['photo'];
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp','gif'])) jsonResponse(['ok' => false, 'error' => 'Недопустимый тип файла'], 400);
    $dir = __DIR__ . '/../assets/img/products/';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $name = 'custom_' . date('Y-m-d') . '_' . substr(md5(uniqid()), 0, 6) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . $name)) jsonResponse(['ok' => false, 'error' => 'Ошибка сохранения'], 500);
    jsonResponse(['ok' => true, 'filename' => $name]);
    exit;
}

// Carousel get — public, no auth
if ($action === 'carousel_get') {
    $db = getDB();
    try {
        $row = $db->query("SELECT value FROM app_settings WHERE key = 'carousel_ids'")->fetch();
        $ids = $row ? json_decode($row['value'], true) : null;
    } catch (Throwable $e) { $ids = null; }
    // Default: all products in carousel if not configured
    if ($ids === null) {
        $jsFile = __DIR__ . '/../products.js';
        $ids = [];
        if (file_exists($jsFile)) {
            $js = file_get_contents($jsFile);
            preg_match_all('/"id"\s*:\s*"([^"]+)"/', $js, $m);
            $ids = $m[1] ?? [];
        }
    }
    jsonResponse(['ok' => true, 'ids' => $ids]);
    exit;
}

// CSV export — override Content-Type before auth check output
if ($action === 'export_orders_csv') {
    adminRequire();
    exportOrdersCsv(getDB());
    exit;
}

adminRequire();

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();

switch ($action) {

    // ── List promo rules ──
    case 'promo_list':
        $rules = $db->query('SELECT * FROM promo_rules ORDER BY created_at DESC')->fetchAll();
        jsonResponse(['ok' => true, 'rules' => $rules]);
        break;

    // ── Create promo rule ──
    case 'promo_create':
        if ($method !== 'POST') jsonResponse(['ok' => false, 'error' => 'POST only'], 405);
        $raw = json_decode(file_get_contents('php://input'), true);
        $name     = trim($raw['name'] ?? '');
        $percent  = floatval($raw['bonus_percent'] ?? 3.0);
        $group    = isset($raw['product_group']) && $raw['product_group'] !== '' ? trim($raw['product_group']) : null;
        $minOrder = intval($raw['min_order'] ?? 0);
        $active   = intval($raw['active'] ?? 1);
        if (!$name) jsonResponse(['ok' => false, 'error' => 'Укажите название правила'], 422);
        $ins = $db->prepare('INSERT INTO promo_rules (name, active, bonus_percent, product_group, min_order) VALUES (?, ?, ?, ?, ?)');
        $ins->execute([$name, $active, $percent, $group, $minOrder]);
        jsonResponse(['ok' => true, 'id' => (int)$db->lastInsertId()]);
        break;

    // ── Toggle promo rule ──
    case 'promo_toggle':
        if ($method !== 'POST') jsonResponse(['ok' => false, 'error' => 'POST only'], 405);
        $raw = json_decode(file_get_contents('php://input'), true);
        $id = intval($raw['id'] ?? 0); $active = intval($raw['active'] ?? 0);
        if (!$id) jsonResponse(['ok' => false, 'error' => 'id required'], 422);
        $db->prepare('UPDATE promo_rules SET active = ? WHERE id = ?')->execute([$active, $id]);
        jsonResponse(['ok' => true]);
        break;

    // ── Update promo rule ──
    case 'promo_update':
        if ($method !== 'POST') jsonResponse(['ok' => false, 'error' => 'POST only'], 405);
        $raw = json_decode(file_get_contents('php://input'), true);
        $id = intval($raw['id'] ?? 0);
        if (!$id) jsonResponse(['ok' => false, 'error' => 'id required'], 422);
        $fields = []; $vals = [];
        if (isset($raw['name']))          { $fields[] = 'name = ?';          $vals[] = trim($raw['name']); }
        if (isset($raw['bonus_percent'])) { $fields[] = 'bonus_percent = ?'; $vals[] = floatval($raw['bonus_percent']); }
        if (array_key_exists('product_group', $raw)) {
            $fields[] = 'product_group = ?';
            $vals[] = ($raw['product_group'] !== '' && $raw['product_group'] !== null) ? trim($raw['product_group']) : null;
        }
        if (isset($raw['min_order'])) { $fields[] = 'min_order = ?'; $vals[] = intval($raw['min_order']); }
        if (isset($raw['active']))    { $fields[] = 'active = ?';    $vals[] = intval($raw['active']); }
        if (empty($fields)) jsonResponse(['ok' => false, 'error' => 'Нет полей для обновления'], 422);
        $vals[] = $id;
        $db->prepare('UPDATE promo_rules SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($vals);
        jsonResponse(['ok' => true]);
        break;

    // ── Delete promo rule ──
    case 'promo_delete':
        if ($method !== 'POST') jsonResponse(['ok' => false, 'error' => 'POST only'], 405);
        $raw = json_decode(file_get_contents('php://input'), true);
        $id = intval($raw['id'] ?? 0);
        if (!$id) jsonResponse(['ok' => false, 'error' => 'id required'], 422);
        $db->prepare('DELETE FROM promo_rules WHERE id = ?')->execute([$id]);
        jsonResponse(['ok' => true]);
        break;

    // ── Manual bonus adjustment ──
    case 'bonus_adjust':
        if ($method !== 'POST') jsonResponse(['ok' => false, 'error' => 'POST only'], 405);
        $raw = json_decode(file_get_contents('php://input'), true);
        $targetUserId = intval($raw['user_id'] ?? 0);
        $amount       = intval($raw['amount'] ?? 0);
        $desc         = trim($raw['description'] ?? 'Ручная корректировка');
        if (!$targetUserId || $amount === 0) jsonResponse(['ok' => false, 'error' => 'user_id и amount обязательны'], 422);
        $check = $db->prepare('SELECT id FROM users WHERE id = ?');
        $check->execute([$targetUserId]);
        if (!$check->fetch()) jsonResponse(['ok' => false, 'error' => 'Пользователь не найден'], 404);
        $type = $amount > 0 ? 'manual_earn' : 'manual_spend';
        $db->prepare('INSERT INTO bonus_log (user_id, order_id, amount, type, description) VALUES (?, NULL, ?, ?, ?)')->execute([$targetUserId, $amount, $type, $desc]);
        $bal = $db->prepare('SELECT COALESCE(SUM(amount), 0) as balance FROM bonus_log WHERE user_id = ?');
        $bal->execute([$targetUserId]);
        jsonResponse(['ok' => true, 'new_balance' => (int)$bal->fetch()['balance']]);
        break;

    // ── List users ──
    case 'users':
        $users = $db->query('
            SELECT u.id, u.name, u.phone, u.telegram, u.role, u.created_at,
                   COALESCE((SELECT SUM(amount) FROM bonus_log WHERE user_id = u.id), 0) as bonus_balance,
                   (SELECT COUNT(*) FROM orders WHERE user_id = u.id) as order_count,
                   (SELECT COALESCE(SUM(total), 0) FROM orders WHERE user_id = u.id) as total_spent
            FROM users u ORDER BY u.created_at DESC
        ')->fetchAll();
        jsonResponse(['ok' => true, 'users' => $users]);
        break;

    // ── User detail (orders + bonus log) ──
    case 'user_detail':
        $uid = intval($_GET['user_id'] ?? 0);
        if (!$uid) jsonResponse(['ok' => false, 'error' => 'user_id required'], 422);

        $user = $db->prepare('SELECT id, name, phone, telegram, role, created_at FROM users WHERE id = ?');
        $user->execute([$uid]);
        $u = $user->fetch();
        if (!$u) jsonResponse(['ok' => false, 'error' => 'Пользователь не найден'], 404);

        $orders = $db->prepare('SELECT id, total, status, bonus_earned, bonus_spent, comment, created_at FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 20');
        $orders->execute([$uid]);
        $orderList = $orders->fetchAll();

        $bonus = $db->prepare('SELECT amount, type, description, created_at FROM bonus_log WHERE user_id = ? ORDER BY created_at DESC LIMIT 30');
        $bonus->execute([$uid]);
        $bonusLog = $bonus->fetchAll();

        $bal = $db->prepare('SELECT COALESCE(SUM(amount),0) as b FROM bonus_log WHERE user_id = ?');
        $bal->execute([$uid]);
        $balance = (int)$bal->fetch()['b'];

        jsonResponse(['ok' => true, 'user' => $u, 'orders' => $orderList, 'bonus_log' => $bonusLog, 'bonus_balance' => $balance]);
        break;

    // ── List orders (all) with filters ──
    case 'orders':
        $page      = max(1, intval($_GET['page'] ?? 1));
        $limit     = 50;
        $offset    = ($page - 1) * $limit;
        $search    = trim($_GET['search'] ?? '');
        $status    = trim($_GET['status'] ?? '');
        $dateFrom  = trim($_GET['date_from'] ?? '');
        $dateTo    = trim($_GET['date_to'] ?? '');

        $where = []; $params = [];
        if ($status !== '')   { $where[] = 'o.status = ?';                     $params[] = $status; }
        if ($dateFrom !== '') { $where[] = "date(o.created_at) >= ?";           $params[] = $dateFrom; }
        if ($dateTo !== '')   { $where[] = "date(o.created_at) <= ?";           $params[] = $dateTo; }
        if ($search !== '') {
            $num = preg_replace('/^(SH-?|#)/i', '', $search);
            if (ctype_digit($num)) { $where[] = 'o.id = ?'; $params[] = (int)$num; }
            else                   { $where[] = 'u.name LIKE ?'; $params[] = '%'.$search.'%'; }
        }
        $whereSQL = $where ? 'WHERE '.implode(' AND ', $where) : '';

        $cntStmt = $db->prepare("SELECT COUNT(*) FROM orders o JOIN users u ON o.user_id=u.id $whereSQL");
        $cntStmt->execute($params);
        $total = (int)$cntStmt->fetchColumn();

        $orders = $db->prepare("SELECT o.*, u.name as user_name, u.phone as user_phone FROM orders o JOIN users u ON o.user_id=u.id $whereSQL ORDER BY o.created_at DESC LIMIT ? OFFSET ?");
        $orders->execute(array_merge($params, [$limit, $offset]));
        $list = $orders->fetchAll();
        foreach ($list as &$order) {
            $items = $db->prepare('SELECT product_name, price, qty FROM order_items WHERE order_id = ?');
            $items->execute([$order['id']]);
            $order['items'] = $items->fetchAll();
        }
        jsonResponse(['ok' => true, 'orders' => $list, 'total' => $total, 'page' => $page]);
        break;

    // ── Update order status ──
    case 'order_status':
        if ($method !== 'POST') jsonResponse(['ok' => false, 'error' => 'POST only'], 405);
        $raw = json_decode(file_get_contents('php://input'), true);
        $orderId = intval($raw['order_id'] ?? 0);
        $status  = trim($raw['status'] ?? '');
        $allowed = ['new','confirmed','in_progress','shipped','completed','cancelled'];
        if (!$orderId || !in_array($status, $allowed)) jsonResponse(['ok' => false, 'error' => 'Некорректные данные'], 422);
        $db->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$status, $orderId]);
        jsonResponse(['ok' => true]);
        break;

    // ── Bulk status update ──
    case 'bulk_status':
        if ($method !== 'POST') jsonResponse(['ok' => false, 'error' => 'POST only'], 405);
        $raw      = json_decode(file_get_contents('php://input'), true);
        $ids      = array_map('intval', $raw['order_ids'] ?? []);
        $status   = trim($raw['status'] ?? '');
        $allowed  = ['new','confirmed','in_progress','shipped','completed','cancelled'];
        if (empty($ids) || !in_array($status, $allowed)) jsonResponse(['ok' => false, 'error' => 'order_ids и status обязательны'], 422);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("UPDATE orders SET status = ? WHERE id IN ($ph)")->execute(array_merge([$status], $ids));
        jsonResponse(['ok' => true, 'updated' => count($ids)]);
        break;

    // ── Save admin note on order ──
    case 'admin_note':
        if ($method !== 'POST') jsonResponse(['ok' => false, 'error' => 'POST only'], 405);
        $raw     = json_decode(file_get_contents('php://input'), true);
        $orderId = intval($raw['order_id'] ?? 0);
        $note    = trim($raw['note'] ?? '');
        if (!$orderId) jsonResponse(['ok' => false, 'error' => 'order_id required'], 422);
        $db->prepare('UPDATE orders SET admin_note = ? WHERE id = ?')->execute([$note, $orderId]);
        jsonResponse(['ok' => true]);
        break;

    // ── Stats summary ──
    case 'stats':
        $stats = [
            'orders_count'   => (int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
            'orders_new'     => (int)$db->query("SELECT COUNT(*) FROM orders WHERE status='new'")->fetchColumn(),
            'orders_today'   => (int)$db->query("SELECT COUNT(*) FROM orders WHERE date(created_at)=date('now')")->fetchColumn(),
            'orders_revenue' => (int)$db->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status!='cancelled'")->fetchColumn(),
            'users_count'    => (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn(),
            'guests_count'   => (int)$db->query('SELECT COUNT(*) FROM guest_orders')->fetchColumn(),
        ];
        jsonResponse(['ok' => true, 'stats' => $stats]);
        break;

    // ── Guest orders ──
    case 'guest_orders':
        $page   = max(1, intval($_GET['page'] ?? 1));
        $limit  = 50; $offset = ($page - 1) * $limit;
        $total  = (int)$db->query('SELECT COUNT(*) FROM guest_orders')->fetchColumn();
        $rows   = $db->prepare('SELECT * FROM guest_orders ORDER BY created_at DESC LIMIT ? OFFSET ?');
        $rows->execute([$limit, $offset]);
        $list = $rows->fetchAll();
        foreach ($list as &$go) { $go['items'] = json_decode($go['items_json'] ?? '[]', true) ?: []; }
        jsonResponse(['ok' => true, 'orders' => $list, 'total' => $total, 'page' => $page]);
        break;

    // ── Set user role ──
    case 'set_role':
        if ($method !== 'POST') jsonResponse(['ok' => false, 'error' => 'POST only'], 405);
        $raw  = json_decode(file_get_contents('php://input'), true);
        $uid  = intval($raw['user_id'] ?? 0);
        $role = trim($raw['role'] ?? '');
        if (!$uid || !in_array($role, ['client','admin'])) jsonResponse(['ok' => false, 'error' => 'Некорректные данные'], 422);
        $db->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $uid]);
        jsonResponse(['ok' => true]);
        break;

    // ── Send Telegram report on demand ──
    case 'send_report':
        if ($method !== 'POST') jsonResponse(['ok' => false, 'error' => 'POST only'], 405);
        $cfgFile = __DIR__ . '/../config.php';
        if (file_exists($cfgFile)) require_once $cfgFile;
        $token  = defined('BOT_TOKEN') ? BOT_TOKEN : '';
        $chatId = defined('CHAT_ID')   ? CHAT_ID   : '';
        if (!$token || !$chatId) jsonResponse(['ok' => false, 'error' => 'Bot not configured'], 500);

        $cnt    = (int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn();
        $newCnt = (int)$db->query("SELECT COUNT(*) FROM orders WHERE status='new'")->fetchColumn();
        $today  = (int)$db->query("SELECT COUNT(*) FROM orders WHERE date(created_at)=date('now')")->fetchColumn();
        $rev    = (int)$db->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status!='cancelled'")->fetchColumn();
        $guests = (int)$db->query('SELECT COUNT(*) FROM guest_orders')->fetchColumn();
        $recent = $db->query("SELECT o.id,o.total,o.status,o.created_at,u.name FROM orders o JOIN users u ON o.user_id=u.id ORDER BY o.created_at DESC LIMIT 7")->fetchAll();

        $sIco = ['new'=>'🆕','confirmed'=>'✅','in_progress'=>'⚙️','shipped'=>'📦','completed'=>'✔️','cancelled'=>'❌'];
        $msg  = "📊 *Отчёт Ритуальная мастерская* (по запросу)\n━━━━━━━━━━━━━━━━\n";
        $msg .= "📦 Всего: *{$cnt}*  |  🆕 Новых: *{$newCnt}*\n";
        $msg .= "📅 Сегодня: *{$today}*  |  👥 Гостевых: *{$guests}*\n";
        $msg .= "💰 Выручка: *".number_format($rev,0,'.',' ')." ₽*\n";
        if ($recent) {
            $msg .= "━━━━━━━━━━━━━━━━\n🕐 Последние:\n";
            foreach ($recent as $r) {
                $ico = $sIco[$r['status']] ?? '•';
                $msg .= "{$ico} SH-".str_pad($r['id'],5,'0',STR_PAD_LEFT)." · {$r['name']} · ".number_format($r['total'],0,'.',' ')." ₽\n";
            }
        }
        $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['chat_id'=>$chatId,'text'=>$msg,'parse_mode'=>'Markdown']),CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_SSL_VERIFYPEER=>false]);
        $res = curl_exec($ch); curl_close($ch);
        $ok  = (bool)(json_decode($res,true)['ok'] ?? false);
        jsonResponse(['ok' => $ok]);
        break;

    // ── Analytics ──
    case 'analytics':
        $days = max(1, min(30, (int)($_GET['days'] ?? 7)));
        $from = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $byDay = $db->prepare("SELECT date(created_at) as day, COUNT(*) as cnt, COALESCE(SUM(total),0) as rev FROM orders WHERE created_at >= ? GROUP BY day ORDER BY day ASC");
        $byDay->execute([$from]); $dailyData = $byDay->fetchAll();
        $byStatus = $db->query("SELECT status, COUNT(*) as cnt FROM orders GROUP BY status")->fetchAll();
        $topP = $db->prepare("SELECT oi.product_name, SUM(oi.qty) as qty, SUM(oi.price*oi.qty) as rev FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.created_at >= ? GROUP BY oi.product_name ORDER BY rev DESC LIMIT 5");
        $topP->execute([$from]); $topProducts = $topP->fetchAll();
        jsonResponse(['ok'=>true,'daily'=>$dailyData,'by_status'=>$byStatus,'top_products'=>$topProducts]);
        break;

    // ── Settings get ──
    case 'settings_get':
        $cfgFile = __DIR__ . '/../config.php';
        $cfg = [];
        if (file_exists($cfgFile)) {
            $lines = file($cfgFile, FILE_IGNORE_NEW_LINES);
            foreach ($lines as $line) {
                if (preg_match("/define\('([^']+)',\s*'([^']*)'\)/", $line, $m)) {
                    $cfg[$m[1]] = $m[2];
                } elseif (preg_match('/define\(\'([^\']+)\',\s*(\d+)\)/', $line, $m)) {
                    $cfg[$m[1]] = $m[2];
                }
            }
        }
        // Merge app_settings (bonuses_enabled etc.)
        try {
            $appRows = $db->query("SELECT key, value FROM app_settings")->fetchAll();
            foreach ($appRows as $r) { $cfg[$r['key']] = $r['value']; }
        } catch (Throwable $e) {}
        jsonResponse(['ok' => true, 'settings' => $cfg]);
        break;

    // ── Settings save ──
    case 'settings_save':
        if ($method !== 'POST') jsonResponse(['ok' => false, 'error' => 'POST only'], 405);
        $raw = json_decode(file_get_contents('php://input'), true) ?: [];
        $allowed_keys = ['BOT_TOKEN','CHAT_ID','TG_ADMIN_ID','EMAIL_TO','CRON_SECRET','ALLOWED_ORIGIN','WEBHOOK_SECRET'];
        $cfgFile = __DIR__ . '/../config.php';

        $existing = [];
        if (file_exists($cfgFile)) {
            $lines = file($cfgFile, FILE_IGNORE_NEW_LINES);
            foreach ($lines as $line) {
                if (preg_match("/define\('([^']+)',\s*'([^']*)'\)/", $line, $m)) {
                    $existing[$m[1]] = $m[2];
                } elseif (preg_match('/define\(\'([^\']+)\',\s*(\d+)\)/', $line, $m)) {
                    $existing[$m[1]] = $m[2];
                }
            }
        }
        foreach ($allowed_keys as $key) {
            if (array_key_exists($key, $raw)) {
                $existing[$key] = trim((string)$raw[$key]);
            }
        }
        if (!array_key_exists('RATE_LIMIT_SEC', $existing)) {
            $existing['RATE_LIMIT_SEC'] = '30';
        }

        $content = "<?php\n";
        foreach ($existing as $key => $val) {
            if (!preg_match('/^[A-Z0-9_]+$/', $key)) continue;
            if ($key === 'RATE_LIMIT_SEC' && ctype_digit((string)$val)) {
                $content .= "define('{$key}', ".intval($val).");\n";
            } else {
                $content .= "define('{$key}', ".var_export((string)$val, true).");\n";
            }
        }

        file_put_contents($cfgFile, $content);

        // Save app_settings (bonuses_enabled)
        if (isset($raw['bonuses_enabled'])) {
            $bval = ($raw['bonuses_enabled'] === true || $raw['bonuses_enabled'] === '1' || $raw['bonuses_enabled'] === 1) ? '1' : '0';
            try {
                $db->prepare("INSERT OR REPLACE INTO app_settings (key, value) VALUES ('bonuses_enabled', ?)")->execute([$bval]);
            } catch (Throwable $e) {}
        }
        jsonResponse(['ok' => true]);
        break;

    // ── Carousel save ──
    case 'carousel_save':
        if ($method !== 'POST') jsonResponse(['ok' => false, 'error' => 'POST only'], 405);
        $raw = json_decode(file_get_contents('php://input'), true);
        if (!is_array($raw) || !array_key_exists('ids', $raw) || !is_array($raw['ids'])) {
            jsonResponse(['ok' => false, 'error' => 'ids required'], 422);
        }
        $ids = array_values(array_filter((array)($raw['ids'] ?? []), 'is_string'));
        $val = json_encode($ids);
        try {
            $db->prepare("INSERT OR REPLACE INTO app_settings (key, value) VALUES ('carousel_ids', ?)")->execute([$val]);
        } catch (Throwable $e) {
            jsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
        }
        jsonResponse(['ok' => true, 'saved' => count($ids)]);
        break;

    // ── List products + overrides ──
    case 'products_list':
        $jsFile = __DIR__ . '/../products.js';
        $products = [];
        if (file_exists($jsFile)) {
            $js = file_get_contents($jsFile);
            if (preg_match('/var\s+PRODUCTS\s*=\s*(\[[\s\S]*?\])\s*;/', $js, $m)) {
                $products = json_decode($m[1], true) ?: [];
            }
        }
        $ovRows = $db->query("SELECT sku, description, badge, badge_label, active, price_override, stock_override, size_override, desc_short, model_override, brand_override, dimensions, desc_long_override, benefits_override, photos_override FROM product_overrides")->fetchAll();
        $ovMap = [];
        foreach ($ovRows as $r) { $ovMap[$r['sku']] = $r; }
        foreach ($products as &$p) {
            if (isset($ovMap[$p['sku']])) { $p['_override'] = $ovMap[$p['sku']]; }
        }
        unset($p);
        // Append custom products
        $cpRows = $db->query("SELECT id, sku, data_json, active FROM custom_products")->fetchAll();
        foreach ($cpRows as $cr) {
            $d = json_decode($cr['data_json'], true) ?: [];
            $d['id'] = $cr['id']; $d['sku'] = $cr['sku']; $d['_custom'] = true;
            if (isset($ovMap[$cr['sku']])) { $d['_override'] = $ovMap[$cr['sku']]; }
            else { $d['_override'] = ['active' => $cr['active']]; }
            $products[] = $d;
        }
        $search = trim($_GET['search'] ?? '');
        if ($search !== '') {
            $sl = mb_strtolower($search);
            $products = array_values(array_filter($products, function($p) use ($sl) {
                return mb_strpos(mb_strtolower($p['model'] ?? ''), $sl) !== false
                    || mb_strpos(mb_strtolower($p['brand'] ?? ''), $sl) !== false
                    || mb_strpos(mb_strtolower($p['sku'] ?? ''), $sl) !== false
                    || mb_strpos(mb_strtolower($p['series'] ?? ''), $sl) !== false;
            }));
        }
        jsonResponse(['ok' => true, 'products' => array_slice($products, 0, 300), 'total' => count($products)]);
        break;

    // ── Save product override ──
    case 'product_save_override':
        if ($method !== 'POST') jsonResponse(['ok' => false, 'error' => 'POST only'], 405);
        $raw = json_decode(file_get_contents('php://input'), true);
        $sku             = trim($raw['sku'] ?? '');
        $desc            = trim($raw['description'] ?? '');
        $badge           = trim($raw['badge'] ?? '');
        $blabel          = trim($raw['badge_label'] ?? '');
        $priceOv         = isset($raw['price_override']) && $raw['price_override'] !== '' ? (int)$raw['price_override'] : null;
        $stockOv         = in_array($raw['stock_override'] ?? '', ['in_stock', 'out_of_stock', '']) ? (($raw['stock_override'] ?? '') ?: null) : null;
        $sizeOv          = trim($raw['size_override'] ?? '') ?: null;
        $descShort       = trim($raw['desc_short'] ?? '') ?: null;
        $modelOv         = trim($raw['model_override'] ?? '') ?: null;
        $brandOv         = trim($raw['brand_override'] ?? '') ?: null;
        $dimensions      = trim($raw['dimensions'] ?? '') ?: null;
        $descLongOv      = trim($raw['desc_long_override'] ?? '') ?: null;
        $benefitsOv      = trim($raw['benefits_override'] ?? '') ?: null;
        $photosOv        = normalizePhotoList($raw['photos_override'] ?? null);
        $photosOvJson    = $photosOv ? json_encode($photosOv, JSON_UNESCAPED_UNICODE) : null;
        if (!$sku) jsonResponse(['ok' => false, 'error' => 'sku required'], 422);
        if (!in_array($badge, ['', 'new', 'sale', 'clearance'])) jsonResponse(['ok' => false, 'error' => 'Invalid badge'], 422);
        $db->prepare("INSERT INTO product_overrides
            (sku, description, badge, badge_label, price_override, stock_override, size_override, desc_short,
             model_override, brand_override, dimensions, desc_long_override, benefits_override, photos_override, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(sku) DO UPDATE SET
                description=excluded.description,
                badge=excluded.badge,
                badge_label=excluded.badge_label,
                price_override=excluded.price_override,
                stock_override=excluded.stock_override,
                size_override=excluded.size_override,
                desc_short=excluded.desc_short,
                model_override=excluded.model_override,
                brand_override=excluded.brand_override,
                dimensions=excluded.dimensions,
                desc_long_override=excluded.desc_long_override,
                benefits_override=excluded.benefits_override,
                photos_override=excluded.photos_override,
                updated_at=excluded.updated_at")
            ->execute([$sku, $desc, $badge, $blabel, $priceOv, $stockOv, $sizeOv, $descShort,
                       $modelOv, $brandOv, $dimensions, $descLongOv, $benefitsOv, $photosOvJson]);
        jsonResponse(['ok' => true]);
        break;

    // ── Toggle single product active ──
    case 'product_toggle_active':
        if ($method !== 'POST') jsonResponse(['ok' => false, 'error' => 'POST only'], 405);
        $raw = json_decode(file_get_contents('php://input'), true);
        $sku    = trim($raw['sku'] ?? '');
        $active = isset($raw['active']) ? intval($raw['active']) : 1;
        if (!$sku) jsonResponse(['ok' => false, 'error' => 'sku required'], 422);
        $db->prepare("INSERT INTO product_overrides (sku, active, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(sku) DO UPDATE SET active=excluded.active, updated_at=excluded.updated_at")
            ->execute([$sku, $active]);
        $db->prepare("UPDATE custom_products SET active = ?, updated_at = CURRENT_TIMESTAMP WHERE sku = ?")
            ->execute([$active, $sku]);
        jsonResponse(['ok' => true]);
        break;

    // ── Bulk toggle products active ──
    case 'bulk_toggle_prod_active':
        if ($method !== 'POST') jsonResponse(['ok' => false, 'error' => 'POST only'], 405);
        $raw  = json_decode(file_get_contents('php://input'), true);
        $skus = array_filter(array_map('trim', (array)($raw['skus'] ?? [])));
        $active = isset($raw['active']) ? intval($raw['active']) : 1;
        if (!$skus) jsonResponse(['ok' => false, 'error' => 'skus required'], 422);
        $updated = 0;
        $stmt = $db->prepare("INSERT INTO product_overrides (sku, active, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(sku) DO UPDATE SET active=excluded.active, updated_at=excluded.updated_at");
        $customStmt = $db->prepare("UPDATE custom_products SET active = ?, updated_at = CURRENT_TIMESTAMP WHERE sku = ?");
        foreach ($skus as $sku) { $stmt->execute([$sku, $active]); $customStmt->execute([$active, $sku]); $updated++; }
        jsonResponse(['ok' => true, 'updated' => $updated]);
        break;

    // ── Save custom product ──
    case 'custom_product_save':
        if ($method !== 'POST') jsonResponse(['ok' => false, 'error' => 'POST only'], 405);
        $raw = json_decode(file_get_contents('php://input'), true) ?: [];
        $sku = trim($raw['sku'] ?? '');
        if (!$sku) jsonResponse(['ok' => false, 'error' => 'sku required'], 422);
        // Generate ID if new
        $existing = $db->prepare("SELECT id FROM custom_products WHERE sku = ?")->execute([$sku]);
        $existRow = $db->prepare("SELECT id FROM custom_products WHERE sku = ?");
        $existRow->execute([$sku]);
        $existData = $existRow->fetch();
        $id = $existData ? $existData['id'] : ('cp_' . substr(md5(uniqid()), 0, 8));
        $photos = normalizePhotoList($raw['photos'] ?? ($raw['photo'] ?? ''));
        $data = [
            'model'      => $raw['model'] ?? '',
            'brand'      => $raw['brand'] ?? 'Ручная работа',
            'brandCode'  => $raw['brandCode'] ?? 'custom',
            'series'     => $raw['series'] ?? '',
            'group'      => $raw['group'] ?? 'venki',
            'size'       => $raw['size'] ?? '-',
            'price'      => intval($raw['price'] ?? 0),
            'stock'      => $raw['stock'] ?? 'in_stock',
            'stockLabel' => $raw['stock'] === 'out_of_stock' ? 'Нет в наличии' : 'В наличии',
            'descShort'  => $raw['descShort'] ?? '',
            'cardBenef'  => $raw['cardBenef'] ?? '',
            'benefits'   => array_values(array_filter(explode("\n", $raw['benefits_text'] ?? ''))),
            'descLong'   => $raw['descLong'] ?? '',
            'photo'      => $photos[0] ?? '',
            'photos'     => $photos,
            'btu'        => '-',
            'area'       => 0,
        ];
        $db->prepare("INSERT INTO custom_products (id, sku, data_json, active, updated_at)
            VALUES (?, ?, ?, 1, CURRENT_TIMESTAMP)
            ON CONFLICT(sku) DO UPDATE SET data_json=excluded.data_json, active=1, updated_at=excluded.updated_at")
            ->execute([$id, $sku, json_encode($data, JSON_UNESCAPED_UNICODE)]);
        jsonResponse(['ok' => true, 'id' => $id, 'sku' => $sku]);
        break;

    // ── Delete custom product ──
    case 'custom_product_delete':
        if ($method !== 'POST') jsonResponse(['ok' => false, 'error' => 'POST only'], 405);
        $raw = json_decode(file_get_contents('php://input'), true) ?: [];
        $sku = trim($raw['sku'] ?? '');
        if (!$sku) jsonResponse(['ok' => false, 'error' => 'sku required'], 422);
        $db->prepare("DELETE FROM custom_products WHERE sku = ?")->execute([$sku]);
        jsonResponse(['ok' => true]);
        break;

    default:
        jsonResponse(['ok' => false, 'error' => 'Unknown action'], 400);
}

function normalizePhotoList($value) {
    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '') return [];
        $decoded = json_decode($trimmed, true);
        $value = is_array($decoded) ? $decoded : preg_split('/[\r\n,]+/', $trimmed);
    }
    if (!is_array($value)) return [];

    $out = [];
    foreach ($value as $name) {
        $name = basename(trim((string)$name));
        if ($name === '') continue;
        if (!preg_match('/\.(jpe?g|png|webp|gif)$/i', $name)) continue;
        if (!in_array($name, $out, true)) $out[] = $name;
        if (count($out) >= 12) break;
    }
    return $out;
}

// ── Export CSV helper ──────────────────────────────────────────────────────
function exportOrdersCsv($db) {
    $status   = trim($_GET['status'] ?? '');
    $dateFrom = trim($_GET['date_from'] ?? '');
    $dateTo   = trim($_GET['date_to'] ?? '');
    $search   = trim($_GET['search'] ?? '');

    $where = []; $params = [];
    if ($status !== '')   { $where[] = 'o.status = ?';           $params[] = $status; }
    if ($dateFrom !== '') { $where[] = 'date(o.created_at) >= ?'; $params[] = $dateFrom; }
    if ($dateTo !== '')   { $where[] = 'date(o.created_at) <= ?'; $params[] = $dateTo; }
    if ($search !== '') {
        $num = preg_replace('/^(SH-?|#)/i', '', $search);
        if (ctype_digit($num)) { $where[] = 'o.id = ?'; $params[] = (int)$num; }
        else                   { $where[] = 'u.name LIKE ?'; $params[] = '%'.$search.'%'; }
    }
    $whereSQL = $where ? 'WHERE '.implode(' AND ', $where) : '';

    $rows = $db->prepare("SELECT o.id, o.created_at, u.name, u.phone, u.telegram, o.total, o.status, o.bonus_earned, o.bonus_spent, o.admin_note, o.comment FROM orders o JOIN users u ON o.user_id=u.id $whereSQL ORDER BY o.created_at DESC LIMIT 5000");
    $rows->execute($params);
    $list = $rows->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="orders_'.date('Y-m-d').'.csv"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM для Excel
    $out = fopen('php://output', 'w');
    fputcsv($out, ['№','Дата','Клиент','Телефон','Telegram','Сумма ₽','Статус','Бонусов начислено','Бонусов списано','Заметка','Комментарий'], ';');
    $smap = ['new'=>'Новый','confirmed'=>'Подтверждён','in_progress'=>'В работе','shipped'=>'Отгружен','completed'=>'Выполнен','cancelled'=>'Отменён'];
    foreach ($list as $r) {
        fputcsv($out, [
            'SH-'.str_pad($r['id'],5,'0',STR_PAD_LEFT),
            $r['created_at'], $r['name'], $r['phone'], $r['telegram'],
            $r['total'], $smap[$r['status']] ?? $r['status'],
            $r['bonus_earned'], $r['bonus_spent'], $r['admin_note'], $r['comment']
        ], ';');
    }
    fclose($out);
}
