<?php
/**
 * tg_poll.php — Telegram-пульт управления статусами заказов через POLLING (getUpdates).
 *
 * РФ-хостинг не пропускает входящие от Telegram → webhook не работает, используем polling.
 * Запуск по cron раз в минуту:
 *   * * * * * curl -s "https://ritualb2b.ru/api/tg_poll.php?secret=<WEBHOOK_SECRET>" >/dev/null 2>&1
 *
 * Обрабатывает callback_query от inline-кнопок под уведомлением о заказе (см. send.php),
 * меняет orders.status. Offset хранится в app_settings и сохраняется ВСЕГДА (защита от спама).
 *
 * Перед первым запуском polling разово снять возможный старый webhook:
 *   curl -s "https://api.telegram.org/bot<BOT_TOKEN>/deleteWebhook"
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db/init.php';
header('Content-Type: text/plain; charset=utf-8');

$TOKEN  = defined('BOT_TOKEN') ? BOT_TOKEN : '';
$CHAT   = defined('CHAT_ID')   ? (string)CHAT_ID : '';
$SECRET = defined('WEBHOOK_SECRET') ? WEBHOOK_SECRET : '';
$LOG    = __DIR__ . '/tg_poll.log';

if ($SECRET === '' || ($_GET['secret'] ?? '') !== $SECRET) {
    http_response_code(403); echo 'forbidden'; exit;
}

function plog($file, $m) { @error_log('[' . date('Y-m-d H:i:s') . '] ' . $m . "\n", 3, $file); }

function getSetting($db, $k, $def = '') {
    $s = $db->prepare('SELECT value FROM app_settings WHERE key = ?');
    $s->execute([$k]); $r = $s->fetch();
    return $r ? $r['value'] : $def;
}
function setSetting($db, $k, $v) {
    $db->prepare('INSERT OR REPLACE INTO app_settings(key,value) VALUES(?,?)')->execute([$k, $v]);
}

function tgApi($token, $method, $params) {
    if (!$token) return null;
    $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $params,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $r = curl_exec($ch); curl_close($ch);
    return $r;
}

$labels  = ['confirmed' => 'Подтверждён', 'completed' => 'Выполнен', 'cancelled' => 'Отменён'];
$allowed = ['confirmed', 'completed', 'cancelled'];
$processed = 0; $errors = 0;

try {
    $db = getDB();
    $offset = (int)getSetting($db, 'tg_offset', '0');

    $resp = tgApi($TOKEN, 'getUpdates', [
        'offset' => $offset, 'timeout' => 0, 'allowed_updates' => json_encode(['callback_query']),
    ]);
    $j = json_decode($resp, true);
    if (!is_array($j) || empty($j['ok'])) {
        plog($LOG, 'getUpdates fail: ' . substr((string)$resp, 0, 300));
        http_response_code(502); echo 'getUpdates fail'; exit;
    }

    $maxId = $offset - 1;
    foreach ($j['result'] as $u) {
        $maxId = max($maxId, (int)$u['update_id']);
        try {
            $cq = $u['callback_query'] ?? null;
            if (!$cq) continue;
            $data   = $cq['data'] ?? '';
            $cqId   = $cq['id'] ?? '';
            $msg    = $cq['message'] ?? [];
            $msgId  = $msg['message_id'] ?? 0;
            $chatId = (string)($msg['chat']['id'] ?? '');

            if ($chatId !== $CHAT) {
                tgApi($TOKEN, 'answerCallbackQuery', ['callback_query_id' => $cqId, 'text' => 'Недоступно']);
                continue;
            }
            $p = explode(':', $data);
            if (count($p) !== 3 || $p[0] !== 'st' || !in_array($p[1], $allowed)) {
                tgApi($TOKEN, 'answerCallbackQuery', ['callback_query_id' => $cqId]);
                continue;
            }
            $status = $p[1]; $oid = (int)$p[2];

            $chk = $db->prepare('SELECT status FROM orders WHERE id = ?');
            $chk->execute([$oid]); $row = $chk->fetch();
            if (!$row) {
                tgApi($TOKEN, 'answerCallbackQuery', ['callback_query_id' => $cqId, 'text' => 'Заказ не найден']);
                continue;
            }
            $sh = 'SH-' . str_pad((string)$oid, 5, '0', STR_PAD_LEFT);

            // Идемпотентность: повторное нажатие того же статуса — только всплывашка, без дубля
            if ($row['status'] === $status) {
                tgApi($TOKEN, 'answerCallbackQuery', ['callback_query_id' => $cqId, 'text' => 'Статус: ' . $labels[$status]]);
                $processed++;
                continue;
            }

            $db->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$status, $oid]);
            tgApi($TOKEN, 'answerCallbackQuery', ['callback_query_id' => $cqId, 'text' => 'Статус: ' . $labels[$status]]);

            // После "Подтверждён" → [Выполнен][Отменить]; после "Выполнен"/"Отменён" → кнопки исчезают
            $mk = ($status === 'confirmed')
                ? ['inline_keyboard' => [[
                    ['text' => '📦 Выполнен', 'callback_data' => 'st:completed:' . $oid],
                    ['text' => '❌ Отменить', 'callback_data' => 'st:cancelled:' . $oid],
                  ]]]
                : ['inline_keyboard' => []];
            tgApi($TOKEN, 'editMessageReplyMarkup', ['chat_id' => $chatId, 'message_id' => $msgId, 'reply_markup' => json_encode($mk)]);
            tgApi($TOKEN, 'sendMessage', ['chat_id' => $chatId, 'text' => $sh . ' → ' . $labels[$status], 'reply_to_message_id' => $msgId]);
            $processed++;
        } catch (Throwable $e) {
            $errors++;
            plog($LOG, 'update ' . ($u['update_id'] ?? '?') . ' error: ' . $e->getMessage());
        }
    }

    // offset сохраняем ВСЕГДА — чтобы не переобрабатывать одни и те же нажатия (спам)
    if ($maxId >= $offset) setSetting($db, 'tg_offset', (string)($maxId + 1));

    echo "ok processed={$processed} errors={$errors} offset=" . ($maxId + 1);
} catch (Throwable $e) {
    plog($LOG, 'FATAL: ' . $e->getMessage());
    http_response_code(500); echo 'error';
}
