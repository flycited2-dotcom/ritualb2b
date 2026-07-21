<?php
/**
 * SQLite database initialization and connection.
 * Database file is auto-created on first request.
 */

function getDB() {
    static $db = null;
    if ($db) return $db;

    $dbPath = __DIR__ . '/ritualb2b.sqlite';
    $isNew = !file_exists($dbPath);

    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    try { $db->exec('PRAGMA journal_mode=WAL'); } catch (Throwable $e) {}
    try { $db->exec('PRAGMA foreign_keys=ON'); } catch (Throwable $e) {}

    if ($isNew) {
        migrate($db);
    } else {
        // Ensure tables exist even if DB file was created but migration failed
        $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetch();
        if (!$tables) migrate($db);
    }

    // Incremental migrations — safe to run on every boot
    try {
        $cols = $db->query("PRAGMA table_info(order_items)")->fetchAll(PDO::FETCH_ASSOC);
        $colNames = array_column($cols, 'name');
        if (!in_array('product_id', $colNames)) {
            $db->exec("ALTER TABLE order_items ADD COLUMN product_id TEXT DEFAULT ''");
        }
    } catch (Throwable $e) {}

    // product_overrides table
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS product_overrides (
            sku TEXT PRIMARY KEY,
            description TEXT DEFAULT '',
            badge TEXT DEFAULT '',
            badge_label TEXT DEFAULT '',
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (Throwable $e) {}

    // product_overrides — incremental columns
    $poColsResult = $db->query("PRAGMA table_info(product_overrides)")->fetchAll(PDO::FETCH_ASSOC);
    $poCols = array_column($poColsResult, 'name');
    foreach ([
        'active'             => "ALTER TABLE product_overrides ADD COLUMN active INTEGER DEFAULT NULL",
        'price_override'     => "ALTER TABLE product_overrides ADD COLUMN price_override INTEGER DEFAULT NULL",
        'stock_override'     => "ALTER TABLE product_overrides ADD COLUMN stock_override TEXT DEFAULT NULL",
        'size_override'      => "ALTER TABLE product_overrides ADD COLUMN size_override TEXT DEFAULT NULL",
        'desc_short'         => "ALTER TABLE product_overrides ADD COLUMN desc_short TEXT DEFAULT NULL",
        'model_override'     => "ALTER TABLE product_overrides ADD COLUMN model_override TEXT DEFAULT NULL",
        'brand_override'     => "ALTER TABLE product_overrides ADD COLUMN brand_override TEXT DEFAULT NULL",
        'dimensions'         => "ALTER TABLE product_overrides ADD COLUMN dimensions TEXT DEFAULT NULL",
        'desc_long_override' => "ALTER TABLE product_overrides ADD COLUMN desc_long_override TEXT DEFAULT NULL",
        'benefits_override'  => "ALTER TABLE product_overrides ADD COLUMN benefits_override TEXT DEFAULT NULL",
        'photos_override'    => "ALTER TABLE product_overrides ADD COLUMN photos_override TEXT DEFAULT NULL",
    ] as $col => $sql) {
        if (!in_array($col, $poCols)) {
            try { $db->exec($sql); } catch (Throwable $e) {}
        }
    }

    // custom_products — manually added products
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS custom_products (
            id TEXT PRIMARY KEY,
            sku TEXT NOT NULL UNIQUE,
            data_json TEXT NOT NULL DEFAULT '{}',
            active INTEGER DEFAULT 1,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (Throwable $e) {}

    // users — CRM/customer profile columns
    try {
        $userColsResult = $db->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
        $userCols = array_column($userColsResult, 'name');
        foreach ([
            'email'               => "ALTER TABLE users ADD COLUMN email TEXT DEFAULT ''",
            'company'             => "ALTER TABLE users ADD COLUMN company TEXT DEFAULT ''",
            'contact_person'      => "ALTER TABLE users ADD COLUMN contact_person TEXT DEFAULT ''",
            'position'            => "ALTER TABLE users ADD COLUMN position TEXT DEFAULT ''",
            'client_type'         => "ALTER TABLE users ADD COLUMN client_type TEXT DEFAULT 'retail'",
            'status'              => "ALTER TABLE users ADD COLUMN status TEXT DEFAULT 'active'",
            'legal_name'          => "ALTER TABLE users ADD COLUMN legal_name TEXT DEFAULT ''",
            'inn'                 => "ALTER TABLE users ADD COLUMN inn TEXT DEFAULT ''",
            'kpp'                 => "ALTER TABLE users ADD COLUMN kpp TEXT DEFAULT ''",
            'ogrn'                => "ALTER TABLE users ADD COLUMN ogrn TEXT DEFAULT ''",
            'legal_address'       => "ALTER TABLE users ADD COLUMN legal_address TEXT DEFAULT ''",
            'delivery_address'    => "ALTER TABLE users ADD COLUMN delivery_address TEXT DEFAULT ''",
            'preferred_channel'   => "ALTER TABLE users ADD COLUMN preferred_channel TEXT DEFAULT 'phone'",
            'telegram_chat_id'    => "ALTER TABLE users ADD COLUMN telegram_chat_id TEXT DEFAULT ''",
            'telegram_token'      => "ALTER TABLE users ADD COLUMN telegram_token TEXT DEFAULT ''",
            'email_subscribed'    => "ALTER TABLE users ADD COLUMN email_subscribed INTEGER DEFAULT 1",
            'telegram_subscribed' => "ALTER TABLE users ADD COLUMN telegram_subscribed INTEGER DEFAULT 1",
            'sms_subscribed'      => "ALTER TABLE users ADD COLUMN sms_subscribed INTEGER DEFAULT 0",
            'discount_percent'    => "ALTER TABLE users ADD COLUMN discount_percent REAL DEFAULT 0",
            'payment_terms'       => "ALTER TABLE users ADD COLUMN payment_terms TEXT DEFAULT ''",
            'tags'                => "ALTER TABLE users ADD COLUMN tags TEXT DEFAULT ''",
            'manager_note'        => "ALTER TABLE users ADD COLUMN manager_note TEXT DEFAULT ''",
            'updated_at'          => "ALTER TABLE users ADD COLUMN updated_at TEXT DEFAULT CURRENT_TIMESTAMP",
        ] as $col => $sql) {
            if (!in_array($col, $userCols)) {
                try { $db->exec($sql); } catch (Throwable $e) {}
            }
        }
    } catch (Throwable $e) {}

    // app_settings table
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS app_settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL DEFAULT ''
        )");
        $db->exec("INSERT OR IGNORE INTO app_settings (key, value) VALUES ('bonuses_enabled', '1')");
    } catch (Throwable $e) {}

    // admin_note column on orders
    try {
        $cols2 = $db->query("PRAGMA table_info(orders)")->fetchAll(PDO::FETCH_ASSOC);
        $colNames2 = array_column($cols2, 'name');
        if (!in_array('admin_note', $colNames2)) {
            $db->exec("ALTER TABLE orders ADD COLUMN admin_note TEXT DEFAULT ''");
        }
    } catch (Throwable $e) {}

    return $db;
}

function migrate($db) {
    $db->exec('
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            phone TEXT NOT NULL UNIQUE,
            telegram TEXT DEFAULT "",
            password_hash TEXT NOT NULL,
            role TEXT DEFAULT "client",
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            total INTEGER NOT NULL DEFAULT 0,
            bonus_earned INTEGER NOT NULL DEFAULT 0,
            bonus_spent INTEGER NOT NULL DEFAULT 0,
            status TEXT DEFAULT "new",
            comment TEXT DEFAULT "",
            client_tg TEXT DEFAULT "",
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        );

        CREATE TABLE IF NOT EXISTS order_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL,
            product_name TEXT NOT NULL,
            price INTEGER NOT NULL,
            qty INTEGER NOT NULL DEFAULT 1,
            FOREIGN KEY (order_id) REFERENCES orders(id)
        );

        CREATE TABLE IF NOT EXISTS bonus_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            order_id INTEGER,
            amount INTEGER NOT NULL,
            type TEXT NOT NULL,
            description TEXT DEFAULT "",
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (order_id) REFERENCES orders(id)
        );

        CREATE TABLE IF NOT EXISTS promo_rules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            active INTEGER DEFAULT 1,
            bonus_percent REAL DEFAULT 3.0,
            product_group TEXT DEFAULT NULL,
            min_order INTEGER DEFAULT 0,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS guest_orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            phone TEXT NOT NULL,
            total INTEGER NOT NULL DEFAULT 0,
            items_json TEXT NOT NULL DEFAULT "[]",
            comment TEXT DEFAULT "",
            client_tg TEXT DEFAULT "",
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );

        -- Default promo: 3% retro-bonus on all orders
        INSERT INTO promo_rules (name, active, bonus_percent, product_group, min_order)
        VALUES ("Ретробонус 3%", 1, 3.0, NULL, 0);
    ');
}

/**
 * JSON response helper.
 */
function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Start session and return user_id or null.
 */
function authCheck() {
    if (session_status() === PHP_SESSION_NONE) {
        $sessDir = sys_get_temp_dir() . '/ritualb2b_sess';
        if (is_dir($sessDir) && is_writable($sessDir)) session_save_path($sessDir);
        session_start();
    }
    return $_SESSION['user_id'] ?? null;
}

/**
 * Require authentication — 401 if not logged in.
 */
function authRequire() {
    $uid = authCheck();
    if (!$uid) jsonResponse(['ok' => false, 'error' => 'Необходима авторизация'], 401);
    return $uid;
}

/**
 * Require admin role — 403 if not admin.
 */
function adminRequire() {
    $uid = authRequire();
    $db = getDB();
    $user = $db->prepare('SELECT role FROM users WHERE id = ?');
    $user->execute([$uid]);
    $row = $user->fetch();
    if (!$row || $row['role'] !== 'admin') {
        jsonResponse(['ok' => false, 'error' => 'Доступ запрещён'], 403);
    }
    return $uid;
}

/**
 * Normalize phone to digits-only (e.g. "79781234567").
 */
function normalizePhone($phone) {
    $digits = preg_replace('/\D/', '', $phone);
    if (strlen($digits) === 11 && $digits[0] === '8') {
        $digits = '7' . substr($digits, 1);
    }
    return $digits;
}

/**
 * Calculate bonus for an order based on active promo rules.
 */
function calculateBonus($total, $items = []) {
    $db = getDB();

    // Honour global bonuses_enabled flag
    try {
        $bs = $db->prepare("SELECT value FROM app_settings WHERE key = 'bonuses_enabled'");
        $bs->execute();
        $bsRow = $bs->fetch();
        if ($bsRow && $bsRow['value'] === '0') return ['bonus' => 0, 'rules' => []];
    } catch (Throwable $e) {}

    $rules = $db->query('SELECT * FROM promo_rules WHERE active = 1')->fetchAll();

    $totalBonus = 0;
    $appliedRules = [];

    foreach ($rules as $rule) {
        if ($rule['min_order'] > 0 && $total < $rule['min_order']) continue;

        if ($rule['product_group'] === null || $rule['product_group'] === '') {
            // Rule applies to entire order
            $bonus = (int)floor($total * $rule['bonus_percent'] / 100);
            $totalBonus += $bonus;
            $appliedRules[] = $rule['name'] . ': +' . $bonus . ' ₽';
        } else {
            // Rule applies to specific product group — match by group name in item names
            // Items should have 'group' field if available
            foreach ($items as $item) {
                $group = $item['group'] ?? '';
                if ($group === $rule['product_group']) {
                    $itemTotal = ($item['price'] ?? 0) * ($item['qty'] ?? 1);
                    $bonus = (int)floor($itemTotal * $rule['bonus_percent'] / 100);
                    $totalBonus += $bonus;
                    $appliedRules[] = $rule['name'] . ' (' . $group . '): +' . $bonus . ' ₽';
                }
            }
        }
    }

    return ['bonus' => $totalBonus, 'rules' => $appliedRules];
}
