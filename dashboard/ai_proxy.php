<?php
// ai_proxy.php - Proxy para llamadas a la API de Anthropic
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

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

$input = json_decode(file_get_contents('php://input'), true);
$prompt = $input['prompt'] ?? '';

if (!$prompt) {
    echo json_encode(['error' => 'Sin prompt']);
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
