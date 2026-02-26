<?php

declare(strict_types=1);

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
session_set_cookie_params([
    'httponly' => true,
    'secure' => $isHttps,
    'samesite' => 'Lax',
]);
session_start();

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_response(['ok' => false, 'error' => ['message' => 'Nur POST erlaubt.', 'type' => 'method_not_allowed']], 405);
    }
}

function require_csrf(string $token): void
{
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if ($token === '' || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
        json_response(['ok' => false, 'error' => ['message' => 'Ungültiger CSRF-Token.', 'type' => 'csrf']], 403);
    }
}

function parse_json_payload(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}

function parse_model_id(string $modelInput): string
{
    $defaultModel = 'arcee-ai/trinity-large-preview:free';
    $trimmed = trim($modelInput);
    if ($trimmed === '') {
        return $defaultModel;
    }

    if (str_starts_with($trimmed, 'https://openrouter.ai/')) {
        $path = trim((string)(parse_url($trimmed, PHP_URL_PATH) ?? ''), '/');
        return $path !== '' ? $path : $defaultModel;
    }

    return $trimmed;
}

function map_error_message(int $statusCode, string $apiMessage): string
{
    if ($statusCode === 401 || $statusCode === 403) {
        return 'API-Key ungültig oder nicht autorisiert.';
    }
    if ($statusCode === 429) {
        return 'Rate Limit erreicht. Bitte Retry ausführen.';
    }
    if ($statusCode >= 500) {
        return 'OpenRouter ist momentan nicht erreichbar (5xx).';
    }

    return $apiMessage !== '' ? $apiMessage : ('HTTP ' . $statusCode);
}

function do_openrouter_call(string $apiKey, string $modelId, string $chunkText): array
{
    $started = microtime(true);
    $payload = [
        'model' => $modelId,
        'messages' => [
            [
                'role' => 'system',
                'content' => 'Du bist ein präziser deutscher Lektor. Korrigiere nur Fehler (Leerzeichen mitten im Wort, Ligaturen/Unicode-Artefakte, Rechtschreibung, Grammatik). Erhalte Bedeutung und Inhalt. Keine Erklärungen, gib NUR den korrigierten Text zurück.',
            ],
            [
                'role' => 'user',
                'content' => "Korrigiere den folgenden Text. Gib NUR den korrigierten Text zurück:\n\n" . $chunkText,
            ],
        ],
        'temperature' => 0.1,
    ];

    $referer = isset($_SERVER['HTTP_HOST'])
        ? (($GLOBALS['isHttps'] ?? false) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST']
        : 'http://localhost';

    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'HTTP-Referer: ' . $referer,
            'X-OpenRouter-Title: OCR Korrektur Tool',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 90,
        CURLOPT_CONNECTTIMEOUT => 20,
    ]);

    $rawBody = curl_exec($ch);
    $curlErr = curl_error($ch);
    $curlErrNo = curl_errno($ch);
    $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $elapsedMs = (int)round((microtime(true) - $started) * 1000);

    if ($rawBody === false) {
        $isTimeout = $curlErrNo === CURLE_OPERATION_TIMEDOUT;

        return [
            'ok' => false,
            'httpStatus' => $httpStatus > 0 ? $httpStatus : 502,
            'error' => [
                'message' => $isTimeout ? 'Timeout beim Warten auf OpenRouter.' : ('Verbindungsfehler: ' . $curlErr),
                'type' => $isTimeout ? 'timeout' : 'curl_error',
                'raw' => $curlErr,
            ],
            'meta' => [
                'model' => $modelId,
                'inputChars' => mb_strlen($chunkText),
                'outputChars' => 0,
                'elapsedMs' => $elapsedMs,
            ],
        ];
    }

    $decoded = json_decode($rawBody, true);
    if ($httpStatus >= 400) {
        $apiMsg = (string)($decoded['error']['message'] ?? '');

        return [
            'ok' => false,
            'httpStatus' => $httpStatus,
            'error' => [
                'message' => map_error_message($httpStatus, $apiMsg),
                'type' => 'http_error',
                'raw' => $apiMsg !== '' ? $apiMsg : mb_substr($rawBody, 0, 800),
            ],
            'meta' => [
                'model' => $modelId,
                'inputChars' => mb_strlen($chunkText),
                'outputChars' => 0,
                'elapsedMs' => $elapsedMs,
            ],
        ];
    }

    $correctedText = trim((string)($decoded['choices'][0]['message']['content'] ?? ''));
    if ($correctedText === '') {
        return [
            'ok' => false,
            'httpStatus' => $httpStatus > 0 ? $httpStatus : 502,
            'error' => [
                'message' => 'Leere Modellantwort erhalten.',
                'type' => 'empty_response',
                'raw' => mb_substr((string)$rawBody, 0, 800),
            ],
            'meta' => [
                'model' => $modelId,
                'inputChars' => mb_strlen($chunkText),
                'outputChars' => 0,
                'elapsedMs' => $elapsedMs,
            ],
        ];
    }

    return [
        'ok' => true,
        'correctedText' => $correctedText,
        'httpStatus' => $httpStatus,
        'meta' => [
            'model' => $modelId,
            'inputChars' => mb_strlen($chunkText),
            'outputChars' => mb_strlen($correctedText),
            'elapsedMs' => $elapsedMs,
        ],
    ];
}

require_post();
$payload = parse_json_payload();
$action = (string)($_GET['action'] ?? $payload['action'] ?? '');

if (!isset($_SESSION['csrf_token'])) {
    json_response(['ok' => false, 'error' => ['message' => 'Session fehlt. Bitte Seite neu laden.', 'type' => 'session']], 400);
}

if ($action === 'setApiKey') {
    require_csrf((string)($payload['csrfToken'] ?? ''));

    if (trim((string)getenv('OPENROUTER_API_KEY')) !== '') {
        json_response(['ok' => true, 'message' => 'OPENROUTER_API_KEY ist serverseitig aktiv.']);
    }

    $apiKey = trim((string)($payload['apiKey'] ?? ''));
    if ($apiKey === '') {
        json_response(['ok' => false, 'error' => ['message' => 'API-Key fehlt.', 'type' => 'validation']], 400);
    }

    $_SESSION['openrouter_api_key'] = $apiKey;
    json_response(['ok' => true, 'message' => 'API-Key in Session gespeichert.']);
}

if ($action === 'clearApiKey') {
    require_csrf((string)($payload['csrfToken'] ?? ''));
    unset($_SESSION['openrouter_api_key']);
    json_response(['ok' => true, 'message' => 'Session-API-Key gelöscht.']);
}

if ($action === 'correctBlock') {
    require_csrf((string)($payload['csrfToken'] ?? ''));

    $chunkText = (string)($payload['chunkText'] ?? '');
    $modelInput = (string)($payload['modelInput'] ?? '');

    if ($chunkText === '') {
        json_response(['ok' => false, 'error' => ['message' => 'chunkText fehlt.', 'type' => 'validation']], 400);
    }

    $apiKey = trim((string)getenv('OPENROUTER_API_KEY'));
    if ($apiKey === '') {
        $apiKey = (string)($_SESSION['openrouter_api_key'] ?? '');
    }

    if ($apiKey === '') {
        json_response([
            'ok' => false,
            'error' => ['message' => 'OpenRouter API-Key fehlt. Bitte zuerst speichern.', 'type' => 'auth'],
            'httpStatus' => 401,
        ], 401);
    }

    $modelId = parse_model_id($modelInput);
    $result = do_openrouter_call($apiKey, $modelId, $chunkText);

    if (!($result['ok'] ?? false)) {
        $status = (int)($result['httpStatus'] ?? 502);
        if ($status < 400) {
            $status = 502;
        }
        json_response($result, $status);
    }

    json_response($result, 200);
}

json_response(['ok' => false, 'error' => ['message' => 'Unbekannte Action.', 'type' => 'routing']], 400);
