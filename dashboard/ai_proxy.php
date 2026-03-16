<?php
// ai_proxy.php - Proxy para llamadas a la API de Anthropic
header('Content-Type: application/json');

// Solo aceptar requests desde el mismo servidor
if (!empty($_SERVER['HTTP_ORIGIN'])) {
    $allowed = 'http://' . $_SERVER['HTTP_HOST'];
    if (rtrim($_SERVER['HTTP_ORIGIN'], '/') !== rtrim($allowed, '/')) {
        http_response_code(403);
        echo json_encode(['error' => 'Origen no autorizado']);
        exit;
    }
}

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

$env = load_env(__DIR__ . '/../credentials.env');
$api_key = $env['ANTHROPIC_API_KEY'] ?? '';

if (!$api_key) {
    echo json_encode(['error' => 'ANTHROPIC_API_KEY no configurada en credentials.env']);
    exit;
}

// Rate limiting simple: máximo 1 request cada 30 segundos por IP
$rate_file = sys_get_temp_dir() . '/grid_bot_ai_' . md5($_SERVER['REMOTE_ADDR'] ?? 'local');
if (file_exists($rate_file) && (time() - filemtime($rate_file)) < 30) {
    http_response_code(429);
    echo json_encode(['error' => 'Demasiadas solicitudes. Esperá 30 segundos.']);
    exit;
}
touch($rate_file);

$input = json_decode(file_get_contents('php://input'), true);
$prompt = $input['prompt'] ?? '';

if (!$prompt) {
    echo json_encode(['error' => 'Sin prompt']);
    exit;
}

// Limitar largo del prompt
if (strlen($prompt) > 10000) {
    echo json_encode(['error' => 'Prompt demasiado largo (máximo 10000 caracteres)']);
    exit;
}

$payload = json_encode([
    'model'      => 'claude-sonnet-4-20250514',
    'max_tokens' => 1000,
    'messages'   => [['role' => 'user', 'content' => $prompt]]
]);

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . $api_key,
        'anthropic-version: 2023-06-01'
    ],
    CURLOPT_TIMEOUT => 30
]);

$response = curl_exec($ch);
$error    = curl_error($ch);
curl_close($ch);

if ($error) {
    echo json_encode(['error' => $error]);
} else {
    echo $response;
}
