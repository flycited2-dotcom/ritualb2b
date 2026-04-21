<?php
/**
 * Admin API — promo rules management + manual bonus adjustments.
 *
 * GET    /api/admin.php?action=promo_list
 * POST   /api/admin.php?action=promo_create   {name, bonus_percent, product_group?, min_order?, active?}
 * POST   /api/admin.php?action=promo_toggle    {id, active}
 * POST   /api/admin.php?action=promo_update    {id, name?, bonus_percent?, product_group?, min_order?}
 * POST   /api/admin.php?action=promo_delete    {id}
 * POST   /api/admin.php?action=bonus_adjust    {user_id, amount, description}
 * GET    /api/admin.php?action=users
 * GET    /api/admin.php?action=orders
 * POST   /api/admin.php?action=order_status    {order_id, status}
 */

require __DIR__ . '/../db/init.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// Session started inside authCheck() with correct path
adminRequire();

$action = $_GET['action'] ?? '';
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

    // ── Toggle promo rule active/inactive ──
    case 'promo_toggle':
        if ($method !== 'POST') jsonResponse(['ok' => false, 'error' => 'POST only'], 405);
        $raw = json_decode(file_get_contents('php://input'), true);
        $id     = intval($raw['id'] ?? 0);
        $active = intval($raw['active'] ?? 0);

        if (!$id) jsonResponse(['ok' => false, 'error' => 'id required'], 422);

        $upd = $db->prepare('UPDATE promo_rules SET active = ? WHERE id = ?');
        $upd->execute([$active, $id]);

        jsonResponse(['ok' => true]);
        break;

    // ── Update promo rule ──
    case 'promo_update':
        if ($method !== 'POST') jsonResponse(['ok' => false, 'error' => 'POST only'], 405);
        $raw = json_decode(file_get_contents('php://input'), true);
        $id = intval($raw['id'] ?? 0);
        if (!$id) jsonResponse(['ok' => false, 'error' => 'id required'], 422);

        $fields = [];
        $vals = [];
        if (isset($raw['name']))          { $fields[] = 'name = ?';          $vals[] = trim($raw['name']); }
        if (isset($raw['bonus_percent'])) { $fields[] = 'bonus_percent = ?'; $vals[] = floatval($raw['bonus_percent']); }
        if (array_key_exists('product_group', $raw)) {
            $fields[] = 'product_group = ?';
            $vals[] = ($raw['product_group'] !== '' && $raw['product_group'] !== null) ? trim($raw['product_group']) : null;
        }
        if (isset($raw['min_order']))     { $fields[] = 'min_order = ?';     $vals[] = intval($raw['min_order']); }
        if (isset($raw['active']))        { $fields[] = 'active = ?';        $vals[] = intval($raw['active']); }

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

        if (!$targetUserId || $amount === 0) {
            jsonResponse(['ok' => false, 'error' => 'user_id и amount обязательны'], 422);
        }

        // Verify user exists
        $check = $db->prepare('SELECT id FROM users WHERE id = ?');
        $check->execute([$targetUserId]);
        if (!$check->fetch()) jsonResponse(['ok' => false, 'error' => 'Пользователь не найден'], 404);

        $type = $amount > 0 ? 'manual_earn' : 'manual_spend';
        $ins = $db->prepare('INSERT INTO bonus_log (user_id, order_id, amount, type, description) VALUES (?, NULL, ?, ?, ?)');
        $ins->execute([$targetUserId, $amount, $type, $desc]);

        // Return new balance
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

    // ── List orders (all) ──
    case 'orders':
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = 50;
        $offset = ($page - 1) * $limit;

        $total = (int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn();
        $orders = $db->prepare('
            SELECT o.*, u.name as user_name, u.phone as user_phone
            FROM orders o JOIN users u ON o.user_id = u.id
            ORDER BY o.created_at DESC LIMIT ? OFFSET ?
        ');
        $orders->execute([$limit, $offset]);
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

        $allowed = ['new', 'confirmed', 'in_progress', 'shipped', 'completed', 'cancelled'];
        if (!$orderId || !in_array($status, $allowed)) {
            jsonResponse(['ok' => false, 'error' => 'order_id и корректный status обязательны'], 422);
        }

        $db->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$status, $orderId]);
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
        $limit  = 50;
        $offset = ($page - 1) * $limit;
        $total  = (int)$db->query('SELECT COUNT(*) FROM guest_orders')->fetchColumn();
        $rows   = $db->prepare('SELECT * FROM guest_orders ORDER BY created_at DESC LIMIT ? OFFSET ?');
        $rows->execute([$limit, $offset]);
        $list = $rows->fetchAll();
        foreach ($list as &$go) {
            $go['items'] = json_decode($go['items_json'] ?? '[]', true) ?: [];
        }
        jsonResponse(['ok' => true, 'orders' => $list, 'total' => $total, 'page' => $page]);
        break;

    // ── Set user role ──
    case 'set_role':
        if ($method !== 'POST') jsonResponse(['ok' => false, 'error' => 'POST only'], 405);
        $raw  = json_decode(file_get_contents('php://input'), true);
        $uid  = intval($raw['user_id'] ?? 0);
        $role = trim($raw['role'] ?? '');
        if (!$uid || !in_array($role, ['client', 'admin'])) {
            jsonResponse(['ok' => false, 'error' => 'user_id и role обязательны'], 422);
        }
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

        $cnt     = (int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn();
        $newCnt  = (int)$db->query("SELECT COUNT(*) FROM orders WHERE status='new'")->fetchColumn();
        $today   = (int)$db->query("SELECT COUNT(*) FROM orders WHERE date(created_at)=date('now')")->fetchColumn();
        $rev     = (int)$db->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status!='cancelled'")->fetchColumn();
        $guests  = (int)$db->query('SELECT COUNT(*) FROM guest_orders')->fetchColumn();
        $recent  = $db->query("SELECT o.id,o.total,o.status,o.created_at,u.name,u.phone FROM orders o JOIN users u ON o.user_id=u.id ORDER BY o.created_at DESC LIMIT 7")->fetchAll();

        $sIco = ['new'=>'🆕','confirmed'=>'✅','in_progress'=>'⚙️','shipped'=>'📦','completed'=>'✔️','cancelled'=>'❌'];
        $msg  = "📊 *Отчёт СплитХаб* (по запросу)\n";
        $msg .= "━━━━━━━━━━━━━━━━\n";
        $msg .= "📦 Заказов всего: *{$cnt}*  |  🆕 Новых: *{$newCnt}*\n";
        $msg .= "📅 Сегодня: *{$today}*  |  👥 Гостевых: *{$guests}*\n";
        $msg .= "💰 Выручка: *".number_format($rev,0,'.',' ')." ₽*\n";
        if ($recent) {
            $msg .= "━━━━━━━━━━━━━━━━\n🕐 Последние заказы:\n";
            foreach ($recent as $r) {
                $ico = $sIco[$r['status']] ?? '•';
                $msg .= "{$ico} SH-".str_pad($r['id'],5,'0',STR_PAD_LEFT)." · {$r['name']} · ".number_format($r['total'],0,'.',' ')." ₽\n";
            }
        }

        $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['chat_id'=>$chatId,'text'=>$msg,'parse_mode'=>'Markdown']),CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_SSL_VERIFYPEER=>false]);
        $res = curl_exec($ch); curl_close($ch);
        $ok  = (bool)(json_decode($res,true)['ok'] ?? false);
        jsonResponse(['ok' => $ok, 'tg' => $ok ? 'sent' : 'failed']);
        break;

    default:
        jsonResponse(['ok' => false, 'error' => 'Unknown action'], 400);
}
