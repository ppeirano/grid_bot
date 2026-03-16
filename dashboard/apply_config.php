<?php
// apply_config.php — Actualiza parámetros del grid en la BD
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

$env  = load_env(__DIR__ . '/../credentials.env');
$input = json_decode(file_get_contents('php://input'), true);

$bot_name = $input['bot']    ?? '';
$lower    = (float)($input['lower']  ?? 0);
$upper    = (float)($input['upper']  ?? 0);
$levels   = (int)($input['levels']  ?? 0);

if (!$bot_name || !$lower || !$upper || !$levels) {
    echo json_encode(['ok' => false, 'error' => 'Parámetros incompletos']);
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host={$env['DB_HOST']};port={$env['DB_PORT']};dbname={$env['DB_NAME']};charset=utf8mb4",
        $env['DB_USER'], $env['DB_PASSWORD']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Actualizar config en tabla bots
    $stmt = $pdo->prepare("UPDATE bots SET grid_lower=?, grid_upper=?, grid_levels=? WHERE bot_name=?");
    $stmt->execute([$lower, $upper, $levels, $bot_name]);

    // Actualizar rango en bot_state (sin tocar posiciones ni balances)
    $stmt2 = $pdo->prepare("UPDATE bot_state SET grid_lower=?, grid_upper=? WHERE bot_name=?");
    $stmt2->execute([$lower, $upper, $bot_name]);

    echo json_encode(['ok' => true, 'message' => "Config actualizada para $bot_name"]);

} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
