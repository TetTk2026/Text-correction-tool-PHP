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

function openrouter_correct_chunk(string $chunkText, string $modelId, string $apiKey): array
{
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
    $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($result === false) {
        return ['ok' => false, 'error' => 'OpenRouter-Verbindungsfehler: ' . $curlErr];
    }

    $decoded = json_decode($result, true);
    if ($statusCode >= 400) {
        $message = $decoded['error']['message'] ?? ('HTTP ' . $statusCode);
        return ['ok' => false, 'error' => 'OpenRouter-Fehler: ' . $message, 'statusCode' => $statusCode];
    }

    $corrected = trim((string)($decoded['choices'][0]['message']['content'] ?? ''));
    if ($corrected === '') {
        return ['ok' => false, 'error' => 'Leere Antwort vom Modell.'];
    }

    return ['ok' => true, 'corrected' => $corrected];
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
            if (!$aiResult['ok']) {
                json_response([
                    'ok' => false,
                    'error' => (string)$aiResult['error'],
                    'model' => $modelId,
                ], 429);
            }

            json_response([
                'ok' => true,
                'corrected' => (string)$aiResult['corrected'],
                'model' => $modelId,
            ]);
        }

        json_response([
            'ok' => true,
            'corrected' => ocr_correct_text_local($chunkText),
            'model' => 'lokal',
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
        <button id="mergeBtn">Alles zusammenfügen (korrigiert)</button>
        <button id="copyBtn">In Zwischenablage kopieren</button>
        <button id="resetBtn">Reset</button>
      </div>

      <p class="muted">Sicherer Modus: API-Key wird nur in der PHP-Session gespeichert (nicht in LocalStorage/URL). Für sicheren Transport HTTPS nutzen. Session-Cookies sind HttpOnly, bei HTTPS zusätzlich Secure.</p>
      <?php if ($serverConfigMode): ?>
      <p class="muted">Server-Config-Modus aktiv (OPENROUTER_API_KEY gesetzt): UI-Key-Feld ist deaktiviert.</p>
      <?php endif; ?>
      <div id="globalMsg" class="msg"></div>
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

    const state = {
      blocks: [],
      queue: [],
      activeRequests: 0,
      maxConcurrency: 2,
    };

    const els = {
      sourceInput: document.getElementById('sourceInput'),
      chunkSizeInput: document.getElementById('chunkSize'),
      modeSelect: document.getElementById('modeSelect'),
      modelInput: document.getElementById('modelInput'),
      apiKeyInput: document.getElementById('apiKeyInput'),
      globalMsg: document.getElementById('globalMsg'),
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

    async function postJson(payload) {
      const resp = await fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      const data = await resp.json().catch(() => ({ ok: false, error: 'Ungültige Serverantwort.' }));
      if (!resp.ok || !data.ok) {
        throw new Error(data.error || `HTTP ${resp.status}`);
      }
      return data;
    }

    function enqueueCorrection(blockIndex) {
      if (!state.queue.includes(blockIndex)) {
        state.queue.push(blockIndex);
      }
      processQueue();
    }

    function processQueue() {
      while (state.activeRequests < state.maxConcurrency && state.queue.length > 0) {
        const blockIndex = state.queue.shift();
        const block = state.blocks[blockIndex];
        if (!block) continue;

        state.activeRequests += 1;
        block.status = 'läuft…';
        block.error = '';
        updateBlockView(blockIndex);

        correctBlock(blockIndex)
          .then(() => {
            block.status = 'korrigiert';
            block.error = '';
          })
          .catch((err) => {
            block.status = 'Fehler';
            block.error = err.message || 'Unbekannter Fehler';
          })
          .finally(() => {
            state.activeRequests -= 1;
            updateBlockView(blockIndex);
            processQueue();
          });
      }
    }

    async function correctBlock(blockIndex) {
      const block = state.blocks[blockIndex];
      const payload = {
        action: 'correct',
        csrfToken,
        chunkText: block.original,
        mode: els.modeSelect.value,
        model: els.modelInput.value,
      };
      const data = await postJson(payload);
      block.corrected = data.corrected || block.corrected;
    }

    function makeBlocks(chunks, scheduleCorrection) {
      state.blocks = chunks.map((chunk) => ({
        original: chunk,
        corrected: scheduleCorrection ? '' : chunk,
        status: scheduleCorrection ? 'wartet' : 'manuell',
        error: '',
        refs: null,
      }));
      renderBlocks();

      if (scheduleCorrection) {
        state.blocks.forEach((_, idx) => enqueueCorrection(idx));
      }
      els.mergedOutput.value = '';
    }

    function updateBlockView(idx) {
      const block = state.blocks[idx];
      if (!block || !block.refs) return;
      block.refs.rightTa.value = block.corrected;
      block.refs.status.textContent = block.status;
      block.refs.info.textContent = `Original: ${block.original.length} | Korrigiert: ${block.corrected.length}`;
      block.refs.error.textContent = block.error || '';
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
        info.textContent = `Original: ${block.original.length} | Korrigiert: ${block.corrected.length}`;

        const status = document.createElement('span');
        status.className = 'status';
        status.textContent = block.status;

        header.append(meta, info, status);

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
          block.status = 'wartet';
          block.error = '';
          updateBlockView(idx);
          enqueueCorrection(idx);
        }, 300);

        leftTa.addEventListener('input', leftChange);
        rightTa.addEventListener('input', () => {
          block.corrected = rightTa.value;
          block.status = 'manuell';
          block.error = '';
          updateBlockView(idx);
        });

        left.append(leftLabel, leftTa);
        right.append(rightLabel, rightTa);
        grid.append(left, right);
        wrapper.append(header, grid, error);
        fragment.append(wrapper);

        block.refs = { rightTa, info, status, error };
      });

      els.blocksEl.replaceChildren(fragment);
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
      if (els.modeSelect.value === 'openrouter' && !serverConfigMode) {
        showMessage('Hinweis: Für OpenRouter zuerst API-Key sicher speichern.', 'ok');
      }
      const size = Math.max(1000, Number(els.chunkSizeInput.value) || 10000);
      makeBlocks(splitIntoChunks(els.sourceInput.value, size), true);
    });

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
      els.sourceInput.value = '';
      els.mergedOutput.value = '';
      state.blocks = [];
      state.queue = [];
      els.blocksEl.replaceChildren();
      showMessage('Zurückgesetzt.', 'ok');
    });
  </script>
</body>
</html>
