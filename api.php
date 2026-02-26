<?php
declare(strict_types=1);

session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function jsonResponse(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function getJsonBody(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        jsonResponse(['ok' => false, 'error' => ['message' => 'Ungültiges JSON'], 'httpStatus' => 400], 400);
    }
    return $data;
}

function requireCsrf(array $body): void {
    $token = $body['csrfToken'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (!is_string($token) || $token === '' || !is_string($sessionToken) || !hash_equals($sessionToken, $token)) {
        jsonResponse(['ok' => false, 'error' => ['message' => 'CSRF-Token ungültig'], 'httpStatus' => 403], 403);
    }
}

function modelIdFromInput(string $input): string {
    $trim = trim($input);
    if ($trim === '') {
        return 'arcee-ai/trinity-large-preview:free';
    }
    $parsed = parse_url($trim);
    if (!is_array($parsed) || !isset($parsed['host'])) {
        return $trim;
    }
    $host = strtolower((string)$parsed['host']);
    if (strpos($host, 'openrouter.ai') === false) {
        return $trim;
    }
    $path = trim((string)($parsed['path'] ?? ''), '/');
    if ($path === '') {
        return $trim;
    }
    $parts = explode('/', $path);
    if ($parts[0] === 'api') {
        return $trim;
    }
    return $path;
}

function deriveReferer(): string {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    return ($https ? 'https://' : 'http://') . $host;
}

$body = getJsonBody();
$action = $body['action'] ?? null;

if (!is_string($action) || $action === '') {
    jsonResponse(['ok' => false, 'error' => ['message' => 'Action fehlt'], 'httpStatus' => 400], 400);
}

if ($action === 'ping') {
    jsonResponse([
        'ok' => true,
        'time' => gmdate('c'),
        'session' => session_status() === PHP_SESSION_ACTIVE
    ]);
}

if ($action === 'saveKey') {
    requireCsrf($body);
    $envKey = (string) getenv('OPENROUTER_API_KEY');
    if ($envKey !== '') {
        jsonResponse(['ok' => true, 'message' => 'ENV-Key aktiv']);
    }
    $apiKey = $body['apiKey'] ?? '';
    if (!is_string($apiKey) || trim($apiKey) === '') {
        jsonResponse(['ok' => false, 'error' => ['message' => 'API-Key fehlt'], 'httpStatus' => 400], 400);
    }
    $_SESSION['openrouter_api_key'] = trim($apiKey);
    jsonResponse(['ok' => true]);
}

if ($action === 'deleteKey') {
    requireCsrf($body);
    unset($_SESSION['openrouter_api_key']);
    jsonResponse(['ok' => true]);
}

if ($action !== 'correctBlock') {
    jsonResponse(['ok' => false, 'error' => ['message' => 'Unbekannte Action'], 'httpStatus' => 404], 404);
}

requireCsrf($body);

$blockId = $body['blockId'] ?? null;
$chunkText = $body['chunkText'] ?? '';
$modelInput = $body['modelInput'] ?? '';

if (!is_int($blockId) && !(is_string($blockId) && ctype_digit($blockId))) {
    jsonResponse(['ok' => false, 'error' => ['message' => 'blockId fehlt/ungültig'], 'httpStatus' => 400], 400);
}
if (!is_string($chunkText) || trim($chunkText) === '') {
    jsonResponse(['ok' => false, 'error' => ['message' => 'chunkText fehlt'], 'httpStatus' => 400], 400);
}
if (!is_string($modelInput)) {
    $modelInput = '';
}

$apiKey = (string) getenv('OPENROUTER_API_KEY');
if ($apiKey === '' && isset($_SESSION['openrouter_api_key']) && is_string($_SESSION['openrouter_api_key'])) {
    $apiKey = trim($_SESSION['openrouter_api_key']);
}
if ($apiKey === '') {
    jsonResponse(['ok' => false, 'error' => ['message' => 'Kein OpenRouter API-Key verfügbar'], 'httpStatus' => 401], 401);
}

$modelId = modelIdFromInput($modelInput);
$payload = [
    'model' => $modelId,
    'messages' => [
        [
            'role' => 'system',
            'content' => 'Du bist ein präziser deutscher Lektor. Korrigiere OCR/PDF-Fehler, Rechtschreibung und Grammatik. Erhalte Bedeutung. Gib NUR den korrigierten Text zurück.'
        ],
        [
            'role' => 'user',
            'content' => "Korrigiere den folgenden Text. Gib NUR den korrigierten Text zurück:\n\n" . $chunkText
        ],
    ],
    'temperature' => 0.1,
];

$ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
if ($ch === false) {
    jsonResponse(['ok' => false, 'error' => ['message' => 'cURL init fehlgeschlagen'], 'httpStatus' => 500], 500);
}

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
        'HTTP-Referer: ' . deriveReferer(),
        'X-OpenRouter-Title: OCR Korrektur Tool',
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    CURLOPT_TIMEOUT => 60,
    CURLOPT_CONNECTTIMEOUT => 15,
]);

$response = curl_exec($ch);
$errno = curl_errno($ch);
$error = curl_error($ch);
$httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($errno !== 0) {
    if ($errno === CURLE_OPERATION_TIMEDOUT) {
        jsonResponse(['ok' => false, 'error' => ['message' => 'Timeout'], 'httpStatus' => 0], 200);
    }
    jsonResponse(['ok' => false, 'error' => ['message' => 'Netzwerkfehler: ' . $error], 'httpStatus' => 0], 200);
}

$decoded = json_decode((string)$response, true);
if (!is_array($decoded)) {
    jsonResponse(['ok' => false, 'error' => ['message' => 'Ungültige Antwort von OpenRouter'], 'httpStatus' => $httpStatus], 200);
}

if ($httpStatus !== 200) {
    $msg = $decoded['error']['message'] ?? $decoded['message'] ?? ('OpenRouter HTTP ' . $httpStatus);
    jsonResponse([
        'ok' => false,
        'error' => ['message' => (string) $msg],
        'httpStatus' => $httpStatus,
        'meta' => ['blockId' => (int) $blockId, 'model' => $modelId]
    ], 200);
}

$correctedText = '';
if (isset($decoded['choices'][0]['message']['content']) && is_string($decoded['choices'][0]['message']['content'])) {
    $correctedText = trim($decoded['choices'][0]['message']['content']);
}
if ($correctedText === '') {
    jsonResponse(['ok' => false, 'error' => ['message' => 'Leere Modellantwort'], 'httpStatus' => 200], 200);
}

jsonResponse([
    'ok' => true,
    'correctedText' => $correctedText,
    'httpStatus' => 200,
    'meta' => [
        'blockId' => (int) $blockId,
        'model' => $modelId,
        'inputChars' => mb_strlen($chunkText),
        'outputChars' => mb_strlen($correctedText),
    ]
]);
