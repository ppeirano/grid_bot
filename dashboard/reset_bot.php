<?php
// reset_bot.php — Liquida posiciones y recentra el grid al precio actual
header('Content-Type: application/json');

function load_env($path) {
    if (!file_exists($path)) return [];
    $vars = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$key, $val] = explode('=', $line, 2);
        $vars[trim($key)] = trim($val);
    }
    return $vars;
}

$env   = load_env(__DIR__ . '/../credentials.env');
$input = json_decode(file_get_contents('php://input'), true);

$bot_name = $input['bot'] ?? '';
if (!$bot_name) {
    echo json_encode(['ok' => false, 'error' => 'Bot no especificado']);
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host={$env['DB_HOST']};port={$env['DB_PORT']};dbname={$env['DB_NAME']};charset=utf8mb4",
        $env['DB_USER'], $env['DB_PASSWORD']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. Obtener precio actual desde Binance
    $stmt = $pdo->prepare("SELECT symbol FROM bots WHERE bot_name = ?");
    $stmt->execute([$bot_name]);
    $bot_row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$bot_row) throw new Exception("Bot '$bot_name' no encontrado");

    $symbol = $bot_row['symbol'];
    $url = "https://api.binance.com/api/v3/ticker/price?symbol=$symbol";
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $price_data = json_decode($resp, true);
    if (!isset($price_data['price'])) throw new Exception("No se pudo obtener precio de Binance");
    $current_price = (float)$price_data['price'];

    // 2. Obtener estado actual
    $stmt = $pdo->prepare("SELECT * FROM bot_state WHERE bot_name = ?");
    $stmt->execute([$bot_name]);
    $state = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$state) throw new Exception("Sin estado guardado para $bot_name");

    $positions = json_decode($state['positions'], true) ?? [];
    $usdt_balance  = (float)$state['usdt_balance'];
    $asset_balance = (float)$state['xrp_btc_balance'];
    $realized_pnl  = (float)$state['realized_pnl'];
    $grid_lower    = (float)$state['grid_lower'];
    $grid_upper    = (float)$state['grid_upper'];

    // 3. Obtener config del bot para recalcular grid
    $stmt = $pdo->prepare("SELECT * FROM bots WHERE bot_name = ?");
    $stmt->execute([$bot_name]);
    $bot_cfg = $stmt->fetch(PDO::FETCH_ASSOC);
    $grid_levels = (int)$bot_cfg['grid_levels'];
    $rng         = $grid_upper - $grid_lower;

    // 4. Liquidar todas las posiciones al precio actual
    $total_liquidated = 0;
    $total_profit     = 0;
    $positions_closed = 0;

    // Reconstruir grid para calcular precio de compra de cada nivel
    $step = $rng / $grid_levels;
    $grid = [];
    for ($i = 0; $i <= $grid_levels; $i++) {
        $grid[] = round($grid_lower + $i * $step, 8);
    }

    foreach ($positions as $level_idx => $val) {
        // Soportar formato nuevo {"qty":x,"price":y} y viejo (solo qty)
        if (is_array($val)) {
            $qty       = (float)$val['qty'];
            $buy_price = (float)$val['price'];
        } else {
            $qty       = (float)$val;
            $buy_price = isset($grid[$level_idx]) ? $grid[$level_idx] : $current_price;
        }
        if ($qty <= 0) continue;
        $level_idx = (int)$level_idx;
        $revenue   = $qty * $current_price;
        $profit    = $revenue - ($qty * $buy_price);

        // Registrar venta en trades
        $stmt = $pdo->prepare("INSERT INTO trades (bot_name, action, level_idx, price, qty, usdt_amount, profit) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([$bot_name, 'SELL', $level_idx, $current_price, $qty, $revenue, $profit]);

        $usdt_balance  += $revenue;
        $asset_balance -= $qty;
        $realized_pnl  += $profit;
        $total_liquidated += $revenue;
        $total_profit     += $profit;
        $positions_closed++;
    }

    // 5. Recentrar grid alrededor del precio actual
    $half      = $rng / 2;
    $new_lower = round($current_price - $half, $current_price > 1000 ? 0 : 4);
    $new_upper = round($current_price + $half, $current_price > 1000 ? 0 : 4);

    // 6. Actualizar bot_state — posiciones vacías, grid recentrado, last_price=NULL para forzar reinicialización
    $stmt = $pdo->prepare("
        UPDATE bot_state
        SET usdt_balance=?, xrp_btc_balance=?, realized_pnl=?, last_price=NULL,
            positions='{}', grid_lower=?, grid_upper=?, updated_at=CURRENT_TIMESTAMP
        WHERE bot_name=?
    ");
    $stmt->execute([$usdt_balance, max(0, $asset_balance), $realized_pnl, $new_lower, $new_upper, $bot_name]);

    // 7. Actualizar config en tabla bots
    $stmt = $pdo->prepare("UPDATE bots SET grid_lower=?, grid_upper=? WHERE bot_name=?");
    $stmt->execute([$new_lower, $new_upper, $bot_name]);

    echo json_encode([
        'ok'               => true,
        'bot'              => $bot_name,
        'current_price'    => $current_price,
        'positions_closed' => $positions_closed,
        'total_liquidated' => round($total_liquidated, 2),
        'total_profit'     => round($total_profit, 4),
        'new_lower'        => $new_lower,
        'new_upper'        => $new_upper,
        'usdt_balance'     => round($usdt_balance, 2),
        'message'          => "OK: $positions_closed posiciones liquidadas al precio actual. Grid recentrado en \${$new_lower}-\${$new_upper}. Reiniciá el bot."
    ]);

} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
