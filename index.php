<?php

declare(strict_types=1);

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
session_set_cookie_params([
    'httponly' => true,
    'secure' => $isHttps,
    'samesite' => 'Lax',
]);
session_start();

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function require_csrf(string $token): void
{
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (!$token || !$sessionToken || !hash_equals($sessionToken, $token)) {
        json_response(['ok' => false, 'error' => 'Ungültiger CSRF-Token.'], 403);
    }
}

function ocr_correct_text_local(string $text): string
{
    $out = $text;

    if (class_exists('Normalizer')) {
        $normalized = Normalizer::normalize($out, Normalizer::FORM_KC);
        if ($normalized !== false) {
            $out = $normalized;
        }
    }

    $out = str_replace(['ﬀ', 'ﬁ', 'ﬂ', 'ﬃ', 'ﬄ'], ['ff', 'fi', 'fl', 'ffi', 'ffl'], $out);
    $out = preg_replace("/\r\n?|\n/u", "\n", $out) ?? $out;
    $out = preg_replace('/[ \t]+\n/u', "\n", $out) ?? $out;
    $out = preg_replace('/\n[ \t]+/u', "\n", $out) ?? $out;
    $out = preg_replace('/\n{3,}/u', "\n\n", $out) ?? $out;
    $out = preg_replace('/([^\n])-\n(\p{L})/u', '$1$2', $out) ?? $out;
    $out = preg_replace('/([^\n])\n([^\n])/u', '$1 $2', $out) ?? $out;
    $out = preg_replace('/(\p{L})[ \t]+(\p{L})/u', '$1$2', $out) ?? $out;
    $out = preg_replace('/[ \t]{2,}/u', ' ', $out) ?? $out;
    $out = preg_replace('/[ \t]+([,.;:!?])/u', '$1', $out) ?? $out;
    $out = preg_replace('/([,.;:!?])([^\s\n])/u', '$1 $2', $out) ?? $out;

    return trim($out);
}

function parse_openrouter_model(string $rawModel): string
{
    $input = trim($rawModel);
    $default = 'arcee-ai/trinity-large-preview:free';
    if ($input === '') {
        return $default;
    }

    if (str_starts_with($input, 'https://openrouter.ai/')) {
        $parts = parse_url($input);
        $path = trim((string)($parts['path'] ?? ''), '/');
        if ($path !== '') {
            $segments = explode('/', $path);
            if (count($segments) >= 2) {
                $candidate = implode('/', array_slice($segments, -2));
                if ($candidate !== '') {
                    return $candidate;
                }
            }
            return $path;
        }
    }

    if (str_contains($input, '/') || str_contains($input, ':')) {
        return $input;
    }

    return $default;
}

function map_http_error_message(int $statusCode, string $apiMessage = ''): string
{
    if ($statusCode === 401 || $statusCode === 403) {
        return 'API-Key ungültig oder nicht autorisiert.';
    }
    if ($statusCode === 429) {
        return 'Rate Limit erreicht. Bitte später erneut versuchen.';
    }
    if ($statusCode >= 500) {
        return 'OpenRouter-Serverfehler. Bitte erneut versuchen.';
    }
    if ($apiMessage !== '') {
        return $apiMessage;
    }

    return 'HTTP ' . $statusCode;
}

function openrouter_correct_chunk(string $chunkText, string $modelId, string $apiKey): array
{
    $started = microtime(true);
    $payload = [
        'model' => $modelId,
        'messages' => [
            [
                'role' => 'system',
                'content' => 'Du bist ein präziser deutscher Lektor. Korrigiere nur Fehler (Leerzeichen in Wörtern, Ligaturen/Unicode-Artefakte, Rechtschreibung, Grammatik). Erhalte Bedeutung, Absatzstruktur und Stil. Keine Erklärungen.',
            ],
            [
                'role' => 'user',
                'content' => "Korrigiere den folgenden Text. Gib NUR den korrigierten Text zurück:\n\n" . $chunkText,
            ],
        ],
        'temperature' => 0.1,
    ];

    $appUrl = (isset($_SERVER['HTTP_HOST']) ? (($GLOBALS['isHttps'] ?? false) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] : 'http://localhost');

    $headers = [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
        'HTTP-Referer: ' . $appUrl,
        'X-OpenRouter-Title: OCR Korrektur Tool',
        'X-Title: OCR Korrektur Tool',
    ];

    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 90,
    ]);

    $result = curl_exec($ch);
    $curlErr = curl_error($ch);
    $curlErrNo = curl_errno($ch);
    $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $elapsedMs = (int)round((microtime(true) - $started) * 1000);

    if ($result === false) {
        $message = $curlErrNo === CURLE_OPERATION_TIMEDOUT
            ? 'Timeout beim Warten auf OpenRouter-Antwort.'
            : 'OpenRouter-Verbindungsfehler: ' . $curlErr;

        return [
            'ok' => false,
            'httpStatus' => $statusCode > 0 ? $statusCode : null,
            'error' => [
                'message' => $message,
                'type' => $curlErrNo === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'curl_error',
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

    $decoded = json_decode($result, true);
    if ($statusCode >= 400) {
        $apiMessage = (string)($decoded['error']['message'] ?? '');
        return [
            'ok' => false,
            'httpStatus' => $statusCode,
            'error' => [
                'message' => map_http_error_message($statusCode, $apiMessage),
                'type' => 'http_error',
                'raw' => $apiMessage,
            ],
            'meta' => [
                'model' => $modelId,
                'inputChars' => mb_strlen($chunkText),
                'outputChars' => 0,
                'elapsedMs' => $elapsedMs,
            ],
        ];
    }

    $corrected = trim((string)($decoded['choices'][0]['message']['content'] ?? ''));
    if ($corrected === '') {
        return [
            'ok' => false,
            'httpStatus' => $statusCode,
            'error' => [
                'message' => 'Leere Antwort vom Modell.',
                'type' => 'empty_response',
                'raw' => is_string($result) ? mb_substr($result, 0, 500) : '',
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
        'correctedText' => $corrected,
        'httpStatus' => $statusCode,
        'meta' => [
            'model' => $modelId,
            'inputChars' => mb_strlen($chunkText),
            'outputChars' => mb_strlen($corrected),
            'elapsedMs' => $elapsedMs,
        ],
    ];
}

$serverConfigKey = trim((string)getenv('OPENROUTER_API_KEY'));
$serverConfigMode = $serverConfigKey !== '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $raw = file_get_contents('php://input') ?: '';
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }

    $action = (string)($payload['action'] ?? '');

    if ($action === 'set_api_key') {
        require_csrf((string)($payload['csrfToken'] ?? ''));
        if ($serverConfigMode) {
            json_response(['ok' => true, 'message' => 'Server-Config-Modus aktiv. UI-Key wird nicht benötigt.']);
        }
        $apiKey = trim((string)($payload['apiKey'] ?? ''));
        if ($apiKey === '') {
            json_response(['ok' => false, 'error' => 'API-Key fehlt.'], 400);
        }
        $_SESSION['openrouter_api_key'] = $apiKey;
        json_response(['ok' => true, 'message' => 'API-Key wurde sicher in der Session gespeichert.']);
    }

    if ($action === 'clear_api_key') {
        require_csrf((string)($payload['csrfToken'] ?? ''));
        unset($_SESSION['openrouter_api_key']);
        json_response(['ok' => true, 'message' => 'Session-API-Key gelöscht.']);
    }

    if ($action === 'correct') {
        require_csrf((string)($payload['csrfToken'] ?? ''));
        $chunkText = (string)($payload['chunkText'] ?? '');
        $mode = (string)($payload['mode'] ?? 'local');
        $modelInput = (string)($payload['model'] ?? '');

        if ($mode === 'openrouter') {
            $apiKey = $serverConfigMode ? $serverConfigKey : (string)($_SESSION['openrouter_api_key'] ?? '');
            if ($apiKey === '') {
                json_response(['ok' => false, 'error' => 'OpenRouter API-Key fehlt. Bitte zuerst setzen.'], 400);
            }

            $modelId = parse_openrouter_model($modelInput);
            $aiResult = openrouter_correct_chunk($chunkText, $modelId, $apiKey);
            if (!(bool)($aiResult['ok'] ?? false)) {
                $httpStatus = (int)($aiResult['httpStatus'] ?? 502);
                if ($httpStatus < 400) {
                    $httpStatus = 502;
                }
                json_response($aiResult, $httpStatus);
            }

            json_response($aiResult);
        }

        $localCorrected = ocr_correct_text_local($chunkText);
        json_response([
            'ok' => true,
            'correctedText' => $localCorrected,
            'httpStatus' => 200,
            'meta' => [
                'model' => 'lokal',
                'inputChars' => mb_strlen($chunkText),
                'outputChars' => mb_strlen($localCorrected),
                'elapsedMs' => 0,
            ],
        ]);
    }

    json_response(['ok' => false, 'error' => 'Unbekannte Action.'], 400);
}

?><!doctype html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>OCR Textkorrektur (Variante B/C: PHP)</title>
  <style>
    :root { color-scheme: light dark; }
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; margin: 0; background: #f3f4f6; color: #111827; }
    .container { max-width: 1280px; margin: 0 auto; padding: 16px; }
    .panel { background: #fff; border: 1px solid #d1d5db; border-radius: 10px; padding: 12px; margin-bottom: 14px; }
    .toolbar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
    .toolbar label { display: inline-flex; align-items: center; gap: 6px; }
    .toolbar input[type="number"], .toolbar input[type="text"], .toolbar input[type="password"], .toolbar select { padding: 6px; }
    .toolbar input[type="number"] { width: 120px; }
    .toolbar input[type="text"] { min-width: 350px; }
    button { border: 1px solid #9ca3af; background: #e5e7eb; color: #111827; padding: 8px 12px; border-radius: 8px; cursor: pointer; }
    button:hover { background: #d1d5db; }
    textarea { width: 100%; box-sizing: border-box; min-height: 170px; padding: 8px; border: 1px solid #9ca3af; border-radius: 8px; resize: vertical; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; line-height: 1.4; }
    .block { border: 1px solid #d1d5db; border-radius: 10px; margin-bottom: 14px; overflow: hidden; background: #fff; }
    .block-header { display: flex; justify-content: space-between; gap: 10px; padding: 8px 10px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-size: 13px; align-items: center; }
    .block-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; padding: 10px; }
    .field { display: flex; flex-direction: column; gap: 6px; }
    .field label { font-weight: 600; font-size: 13px; }
    .status { font-size: 12px; font-weight: 700; }
    .status-badge { padding: 2px 8px; border-radius: 999px; border: 1px solid #cbd5e1; background: #f3f4f6; }
    .status-running { background: #dbeafe; border-color: #93c5fd; color: #1e40af; }
    .status-done { background: #dcfce7; border-color: #86efac; color: #166534; }
    .status-error { background: #fee2e2; border-color: #fca5a5; color: #991b1b; }
    .status-aborted { background: #fef3c7; border-color: #fcd34d; color: #92400e; }
    .block-progress { height: 4px; background: #e5e7eb; }
    .block-progress > span { display: block; height: 100%; width: 0; background: #2563eb; transition: width .25s ease; }
    .block-progress.indeterminate > span { width: 40%; animation: indeterminate 1.2s infinite linear; }
    .block-progress.done > span { width: 100%; background: #16a34a; }
    .block-progress.error > span { width: 100%; background: #dc2626; }
    .block-progress.aborted > span { width: 100%; background: #d97706; }
    .block-subinfo { padding: 6px 10px; border-bottom: 1px solid #e5e7eb; font-size: 12px; color: #4b5563; }
    .block-actions { display: flex; gap: 6px; }
    .retry-btn { padding: 4px 8px; font-size: 12px; }
    @keyframes indeterminate { 0% { transform: translateX(-120%);} 100% { transform: translateX(320%);} }
    .msg { font-size: 12px; margin-top: 8px; }
    .msg.error { color: #b91c1c; }
    .msg.ok { color: #166534; }
    .muted { color: #4b5563; font-size: 12px; }
    @media (max-width: 900px) {
      .block-grid { grid-template-columns: 1fr; }
      .toolbar input[type="text"] { min-width: 220px; }
    }
  </style>
</head>
<body>
  <div class="container">
    <h1>OCR/PDF-Textkorrektur (Variante B/C mit PHP)</h1>

    <div class="panel">
      <div class="toolbar">
        <label>Modus:
          <select id="modeSelect">
            <option value="local">B) Lokal (Regex/Heuristik in PHP)</option>
            <option value="openrouter">C) OpenRouter (KI pro Chunk)</option>
          </select>
        </label>

        <label>Chunk-Größe:
          <input id="chunkSize" type="number" min="1000" step="500" value="10000" />
        </label>

        <label>Model:
          <input id="modelInput" type="text" value="arcee-ai/trinity-large-preview:free" />
        </label>

        <label id="apiKeyWrap" <?= $serverConfigMode ? 'style="display:none"' : '' ?>>OpenRouter API Key:
          <input id="apiKeyInput" type="password" autocomplete="off" placeholder="or-..." />
        </label>

        <button id="saveApiKeyBtn" <?= $serverConfigMode ? 'disabled' : '' ?>>API-Key sicher speichern</button>
        <button id="clearApiKeyBtn">API-Key löschen</button>
      </div>

      <div class="toolbar" style="margin-top: 10px;">
        <button id="splitBtn">Aufteilen</button>
        <button id="splitCorrectBtn">Aufteilen &amp; Korrigieren</button>
        <button id="stopAllBtn">Stop All</button>
        <button id="mergeBtn">Alles zusammenfügen (korrigiert)</button>
        <button id="copyBtn">In Zwischenablage kopieren</button>
        <button id="resetBtn">Reset</button>
      </div>

      <p class="muted">Sicherer Modus: API-Key wird nur in der PHP-Session gespeichert (nicht in LocalStorage/URL). Für sicheren Transport HTTPS nutzen. Session-Cookies sind HttpOnly, bei HTTPS zusätzlich Secure.</p>
      <?php if ($serverConfigMode): ?>
      <p class="muted">Server-Config-Modus aktiv (OPENROUTER_API_KEY gesetzt): UI-Key-Feld ist deaktiviert.</p>
      <?php endif; ?>
      <div id="globalMsg" class="msg"></div>
      <div id="globalProgress" class="muted">0 / 0 Blöcke fertig • Fehler: 0 • Läuft: 0</div>
      <textarea id="sourceInput" placeholder="Hier gesamten Rohtext einfügen (z. B. bis 200.000 Zeichen)..."></textarea>
    </div>

    <div id="blocks"></div>

    <div class="panel">
      <h2>Zusammengefügter korrigierter Text</h2>
      <textarea id="mergedOutput" placeholder="Hier erscheint der zusammengefügte korrigierte Text..."></textarea>
    </div>
  </div>

  <script>
    const csrfToken = <?= json_encode($_SESSION['csrf_token']) ?>;
    const serverConfigMode = <?= $serverConfigMode ? 'true' : 'false' ?>;

    const STATUS = {
      WAITING: 'wartet',
      QUEUED: 'in Warteschlange',
      SENDING: 'sendet…',
      RESPONDING: 'antwortet…',
      DONE: 'fertig',
      ABORTED: 'abgebrochen',
      ERROR: 'Fehler',
      MANUAL: 'manuell'
    };

    const state = {
      blocks: [],
      queue: [],
      queueSet: new Set(),
      activeRequests: 0,
      maxConcurrency: 2,
      controllers: new Map(),
      durationTimer: null,
      stopRequested: false,
    };

    const els = {
      sourceInput: document.getElementById('sourceInput'),
      chunkSizeInput: document.getElementById('chunkSize'),
      modeSelect: document.getElementById('modeSelect'),
      modelInput: document.getElementById('modelInput'),
      apiKeyInput: document.getElementById('apiKeyInput'),
      globalMsg: document.getElementById('globalMsg'),
      globalProgress: document.getElementById('globalProgress'),
      blocksEl: document.getElementById('blocks'),
      mergedOutput: document.getElementById('mergedOutput'),
    };

    function showMessage(message, kind = 'ok') {
      els.globalMsg.className = `msg ${kind}`;
      els.globalMsg.textContent = message;
    }

    function splitIntoChunks(text, size) {
      const chunks = [];
      for (let i = 0; i < text.length; i += size) chunks.push(text.slice(i, i + size));
      return chunks;
    }

    function debounce(fn, wait = 300) {
      let t;
      return (...args) => {
        clearTimeout(t);
        t = setTimeout(() => fn(...args), wait);
      };
    }

    function nowMs() { return Date.now(); }
    function formatTime(ms) { return (ms / 1000).toFixed(1) + ' s'; }
    function formatStart(ts) { return ts ? new Date(ts).toLocaleTimeString('de-DE') : '—'; }

    function mapStatusClass(status) {
      if ([STATUS.SENDING, STATUS.RESPONDING, STATUS.QUEUED].includes(status)) return 'status-running';
      if (status === STATUS.DONE) return 'status-done';
      if (status === STATUS.ERROR) return 'status-error';
      if (status === STATUS.ABORTED) return 'status-aborted';
      return '';
    }

    function mapFriendlyError(httpStatus, message) {
      if (httpStatus === 429) return 'Rate Limit erreicht.';
      if (httpStatus === 401 || httpStatus === 403) return 'API-Key ungültig.';
      if (httpStatus >= 500) return 'Serverfehler bei OpenRouter.';
      if ((message || '').toLowerCase().includes('timeout')) return 'Timeout.';
      return message || '';
    }

    function startDurationTimer() {
      if (state.durationTimer) return;
      state.durationTimer = setInterval(() => {
        state.blocks.forEach((b, i) => {
          if (!b.refs) return;
          if ([STATUS.SENDING, STATUS.RESPONDING, STATUS.QUEUED].includes(b.status) && b.startedAt) {
            b.liveElapsedMs = nowMs() - b.startedAt;
            updateBlockView(i);
          }
        });
      }, 250);
    }

    function stopDurationTimerIfIdle() {
      const active = state.blocks.some((b) => [STATUS.SENDING, STATUS.RESPONDING, STATUS.QUEUED].includes(b.status));
      if (!active && state.durationTimer) {
        clearInterval(state.durationTimer);
        state.durationTimer = null;
      }
    }

    async function postJson(payload, signal) {
      const resp = await fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
        signal,
      });
      const data = await resp.json().catch(() => ({ ok: false, error: { message: 'Ungültige Serverantwort.' } }));
      if (!resp.ok || !data.ok) {
        const err = new Error((data.error && data.error.message) || data.error || `HTTP ${resp.status}`);
        err.httpStatus = data.httpStatus || resp.status;
        err.errorObj = data.error || { message: err.message };
        err.meta = data.meta || null;
        throw err;
      }
      return data;
    }

    function updateGlobalProgress() {
      const total = state.blocks.length;
      const done = state.blocks.filter((b) => b.status === STATUS.DONE).length;
      const errors = state.blocks.filter((b) => b.status === STATUS.ERROR).length;
      const running = state.blocks.filter((b) => [STATUS.SENDING, STATUS.RESPONDING].includes(b.status)).length;
      els.globalProgress.textContent = `${done} / ${total} Blöcke fertig • Fehler: ${errors} • Läuft: ${running}`;
    }

    function enqueueCorrection(blockIndex, prioritize = false) {
      if (state.stopRequested) return;
      const block = state.blocks[blockIndex];
      if (!block) return;
      if (state.queueSet.has(blockIndex)) return;
      block.status = STATUS.QUEUED;
      if (prioritize) {
        state.queue.unshift(blockIndex);
      } else {
        state.queue.push(blockIndex);
      }
      state.queueSet.add(blockIndex);
      updateBlockView(blockIndex);
      updateGlobalProgress();
      startDurationTimer();
      processQueue();
    }

    function processQueue() {
      while (!state.stopRequested && state.activeRequests < state.maxConcurrency && state.queue.length > 0) {
        const blockIndex = state.queue.shift();
        state.queueSet.delete(blockIndex);
        const block = state.blocks[blockIndex];
        if (!block) continue;

        state.activeRequests += 1;
        block.status = STATUS.SENDING;
        block.startedAt = nowMs();
        block.liveElapsedMs = 0;
        block.error = '';
        updateBlockView(blockIndex);
        updateGlobalProgress();

        correctBlock(blockIndex)
          .then(() => {
            block.status = STATUS.DONE;
            block.error = '';
          })
          .catch((err) => {
            if (err.name === 'AbortError') {
              block.status = STATUS.ABORTED;
              block.error = 'Request abgebrochen.';
            } else {
              block.status = STATUS.ERROR;
              block.error = mapFriendlyError(err.httpStatus, err.message);
            }
          })
          .finally(() => {
            state.activeRequests -= 1;
            block.finishedAt = nowMs();
            if (!block.meta.elapsedMs) {
              block.meta.elapsedMs = (block.finishedAt - (block.startedAt || block.finishedAt));
            }
            state.controllers.delete(blockIndex);
            updateBlockView(blockIndex);
            updateGlobalProgress();
            processQueue();
            stopDurationTimerIfIdle();
          });
      }
    }

    async function correctBlock(blockIndex) {
      const block = state.blocks[blockIndex];
      const controller = new AbortController();
      state.controllers.set(blockIndex, controller);

      const payload = {
        action: 'correct',
        csrfToken,
        chunkText: block.original,
        mode: els.modeSelect.value,
        model: els.modelInput.value,
      };

      const respondingTimer = setTimeout(() => {
        if (block.status === STATUS.SENDING) {
          block.status = STATUS.RESPONDING;
          updateBlockView(blockIndex);
          updateGlobalProgress();
        }
      }, 150);

      try {
        const data = await postJson(payload, controller.signal);
        clearTimeout(respondingTimer);
        block.status = STATUS.RESPONDING;
        block.corrected = data.correctedText || block.corrected;
        block.httpStatus = data.httpStatus || 200;
        block.meta = data.meta || block.meta;
        block.outputChars = block.corrected.length;
      } catch (err) {
        clearTimeout(respondingTimer);
        block.httpStatus = err.httpStatus || null;
        block.meta = err.meta || block.meta;
        throw err;
      }
    }

    function makeBlocks(chunks, scheduleCorrection) {
      stopAll(false);
      state.stopRequested = false;
      state.blocks = chunks.map((chunk) => ({
        original: chunk,
        corrected: scheduleCorrection ? '' : chunk,
        status: scheduleCorrection ? STATUS.WAITING : STATUS.MANUAL,
        error: '',
        refs: null,
        startedAt: null,
        finishedAt: null,
        liveElapsedMs: 0,
        httpStatus: null,
        meta: { model: els.modeSelect.value === 'openrouter' ? els.modelInput.value : 'lokal', inputChars: chunk.length, outputChars: scheduleCorrection ? 0 : chunk.length, elapsedMs: 0 },
      }));
      renderBlocks();

      if (scheduleCorrection) {
        state.blocks.forEach((_, idx) => enqueueCorrection(idx));
      }
      els.mergedOutput.value = '';
      updateGlobalProgress();
    }

    function updateBlockView(idx) {
      const block = state.blocks[idx];
      if (!block || !block.refs) return;
      const running = [STATUS.SENDING, STATUS.RESPONDING, STATUS.QUEUED].includes(block.status);
      const elapsedMs = block.meta.elapsedMs || block.liveElapsedMs || 0;

      block.refs.rightTa.value = block.corrected;
      block.refs.status.textContent = block.status;
      block.refs.status.className = `status status-badge ${mapStatusClass(block.status)}`;
      block.refs.info.textContent = `Start: ${formatStart(block.startedAt)} • Dauer: ${elapsedMs ? formatTime(elapsedMs) : '—'}`;
      block.refs.subinfo.textContent = `HTTP: ${block.httpStatus ?? '—'} • In: ${block.original.length} • Out: ${block.corrected.length} • ${block.error || 'OK'}`;
      block.refs.error.textContent = block.error || '';
      block.refs.progress.className = 'block-progress' + (running ? ' indeterminate' : block.status === STATUS.DONE ? ' done' : block.status === STATUS.ERROR ? ' error' : block.status === STATUS.ABORTED ? ' aborted' : '');
      block.refs.retryBtn.disabled = running;
    }

    function renderBlocks() {
      const fragment = document.createDocumentFragment();

      state.blocks.forEach((block, idx) => {
        const wrapper = document.createElement('div');
        wrapper.className = 'block';

        const header = document.createElement('div');
        header.className = 'block-header';

        const meta = document.createElement('strong');
        meta.textContent = `Block ${idx + 1}`;

        const info = document.createElement('span');
        const status = document.createElement('span');

        const actions = document.createElement('div');
        actions.className = 'block-actions';
        const retryBtn = document.createElement('button');
        retryBtn.className = 'retry-btn';
        retryBtn.textContent = 'Neu versuchen';
        retryBtn.addEventListener('click', () => {
          block.error = '';
          enqueueCorrection(idx, true);
        });
        actions.append(retryBtn);

        header.append(meta, info, status, actions);

        const subinfo = document.createElement('div');
        subinfo.className = 'block-subinfo';
        const progress = document.createElement('div');
        progress.className = 'block-progress';
        const progressInner = document.createElement('span');
        progress.append(progressInner);

        const grid = document.createElement('div');
        grid.className = 'block-grid';

        const left = document.createElement('div');
        left.className = 'field';
        const leftLabel = document.createElement('label');
        leftLabel.textContent = 'Original-Block (editierbar)';
        const leftTa = document.createElement('textarea');
        leftTa.value = block.original;

        const right = document.createElement('div');
        right.className = 'field';
        const rightLabel = document.createElement('label');
        rightLabel.textContent = 'Korrigierter Block (editierbar)';
        const rightTa = document.createElement('textarea');
        rightTa.value = block.corrected;

        const error = document.createElement('div');
        error.className = 'msg error';
        error.textContent = block.error || '';

        const leftChange = debounce(() => {
          block.original = leftTa.value;
          block.status = STATUS.WAITING;
          block.error = '';
          block.httpStatus = null;
          block.meta = { ...block.meta, inputChars: block.original.length, outputChars: block.corrected.length, elapsedMs: 0 };
          updateBlockView(idx);
          enqueueCorrection(idx, true);
        }, 300);

        leftTa.addEventListener('input', leftChange);
        rightTa.addEventListener('input', () => {
          block.corrected = rightTa.value;
          block.status = STATUS.MANUAL;
          block.error = '';
          block.meta = { ...block.meta, outputChars: block.corrected.length };
          updateBlockView(idx);
          updateGlobalProgress();
        });

        left.append(leftLabel, leftTa);
        right.append(rightLabel, rightTa);
        grid.append(left, right);
        wrapper.append(header, subinfo, progress, grid, error);
        fragment.append(wrapper);

        block.refs = { rightTa, info, status, subinfo, error, progress, retryBtn };
        updateBlockView(idx);
      });

      els.blocksEl.replaceChildren(fragment);
    }

    function stopAll(showMsg = true) {
      state.stopRequested = true;
      state.queue = [];
      state.queueSet.clear();
      state.controllers.forEach((controller, idx) => {
        controller.abort();
        const block = state.blocks[idx];
        if (block && [STATUS.SENDING, STATUS.RESPONDING, STATUS.QUEUED].includes(block.status)) {
          block.status = STATUS.ABORTED;
          block.error = 'Request abgebrochen (Stop All).';
          updateBlockView(idx);
        }
      });
      state.controllers.clear();
      stopDurationTimerIfIdle();
      updateGlobalProgress();
      if (showMsg) showMessage('Alle laufenden Requests wurden abgebrochen.', 'ok');
    }

    document.getElementById('saveApiKeyBtn').addEventListener('click', async () => {
      if (serverConfigMode) {
        showMessage('Server-Config-Modus aktiv: Kein UI-Key nötig.', 'ok');
        return;
      }
      const apiKey = (els.apiKeyInput.value || '').trim();
      if (!apiKey) {
        showMessage('Bitte OpenRouter API-Key eingeben.', 'error');
        return;
      }
      try {
        await postJson({ action: 'set_api_key', csrfToken, apiKey });
        els.apiKeyInput.value = '';
        showMessage('API-Key wurde sicher in der Session gespeichert.', 'ok');
      } catch (err) {
        showMessage(err.message, 'error');
      }
    });

    document.getElementById('clearApiKeyBtn').addEventListener('click', async () => {
      try {
        await postJson({ action: 'clear_api_key', csrfToken });
        showMessage('Session-API-Key gelöscht.', 'ok');
      } catch (err) {
        showMessage(err.message, 'error');
      }
    });

    document.getElementById('splitBtn').addEventListener('click', () => {
      const size = Math.max(1000, Number(els.chunkSizeInput.value) || 10000);
      makeBlocks(splitIntoChunks(els.sourceInput.value, size), false);
    });

    document.getElementById('splitCorrectBtn').addEventListener('click', () => {
      state.stopRequested = false;
      if (els.modeSelect.value === 'openrouter' && !serverConfigMode) {
        showMessage('Hinweis: Für OpenRouter zuerst API-Key sicher speichern.', 'ok');
      }
      const size = Math.max(1000, Number(els.chunkSizeInput.value) || 10000);
      makeBlocks(splitIntoChunks(els.sourceInput.value, size), true);
    });

    document.getElementById('stopAllBtn').addEventListener('click', () => stopAll(true));

    document.getElementById('mergeBtn').addEventListener('click', () => {
      els.mergedOutput.value = state.blocks.map((b) => b.corrected).join('');
    });

    document.getElementById('copyBtn').addEventListener('click', async () => {
      if (!els.mergedOutput.value) return;
      try {
        await navigator.clipboard.writeText(els.mergedOutput.value);
        showMessage('Korrigierter Text wurde in die Zwischenablage kopiert.', 'ok');
      } catch {
        showMessage('Kopieren fehlgeschlagen. Bitte manuell kopieren.', 'error');
      }
    });

    document.getElementById('resetBtn').addEventListener('click', () => {
      stopAll(false);
      els.sourceInput.value = '';
      els.mergedOutput.value = '';
      state.blocks = [];
      state.queue = [];
      state.queueSet.clear();
      state.stopRequested = false;
      els.blocksEl.replaceChildren();
      updateGlobalProgress();
      showMessage('Zurückgesetzt.', 'ok');
    });

    updateGlobalProgress();
  </script>
</body>
</html>
