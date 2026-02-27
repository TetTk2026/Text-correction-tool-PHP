<?php
declare(strict_types=1);

session_start();

if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];
$hasEnvKey = (string) getenv('OPENROUTER_API_KEY') !== '';
$hasSessionKey = isset($_SESSION['openrouter_api_key']) && $_SESSION['openrouter_api_key'] !== '';
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>OCR Korrektur Tool (PHP + OpenRouter)</title>
  <style>
    :root { color-scheme: light; }
    body { font-family: Arial, sans-serif; margin: 0; background: #f4f5f7; color: #1f2937; }
    .container { max-width: 1300px; margin: 0 auto; padding: 16px; }
    .card { background: #fff; border: 1px solid #d1d5db; border-radius: 10px; padding: 14px; margin-bottom: 14px; }
    h1,h2,h3 { margin-top: 0; }
    textarea,input,button,select { font: inherit; }
    textarea,input[type="text"],input[type="number"],input[type="password"],select {
      width: 100%; box-sizing: border-box; padding: 8px; border: 1px solid #cbd5e1; border-radius: 8px;
    }
    textarea { min-height: 110px; resize: vertical; }
    .row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .row > * { flex: 1; }
    .tight { flex: 0 0 auto; }
    button { background: #2563eb; color: #fff; border: none; border-radius: 8px; padding: 9px 12px; cursor: pointer; }
    button:hover { background: #1d4ed8; }
    button.alt { background: #4b5563; }
    button.warn { background: #dc2626; }
    button:disabled { opacity: .6; cursor: not-allowed; }
    .status-grid { display:grid; grid-template-columns: repeat(auto-fit,minmax(220px,1fr)); gap:8px; }
    .status-chip { background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:8px; }
    .muted { color:#6b7280; font-size: 13px; }
    .blocks { display: grid; gap: 12px; }
    .block { border:1px solid #cbd5e1; border-radius:10px; padding: 10px; background:#fff; }
    .block-head { display:flex; flex-wrap:wrap; align-items:center; gap:8px; margin-bottom:8px; }
    .badge { padding:2px 8px; border-radius:999px; font-size:12px; background:#e5e7eb; }
    .badge.ok { background:#dcfce7; }
    .badge.err { background:#fee2e2; }
    .badge.run { background:#dbeafe; }
    .grid-2 { display:grid; grid-template-columns: 1fr 1fr; gap:10px; }
    .progress { width:170px; height: 8px; border-radius: 999px; background:#e5e7eb; overflow:hidden; }
    .progress > div { width:45%; height:100%; background:#3b82f6; animation: move 1s linear infinite; }
    @keyframes move { from{ transform: translateX(-110%);} to{ transform:translateX(240%);} }
    .err-text { color:#b91c1c; font-size: 13px; white-space: pre-wrap; }
    .toolbar { align-items: flex-start; }
    .global-counter { position: sticky; top: 8px; align-self: flex-start; margin-left: auto; white-space: nowrap; }
    @media (max-width: 900px) { .grid-2 { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
<div class="container">
  <h1>OCR Korrektur Tool (Variante C)</h1>

  <div class="card">
    <h3>Systemstatus</h3>
    <div class="status-grid">
      <div class="status-chip">API erreichbar: <strong id="apiReach">unbekannt</strong></div>
      <div class="status-chip">Key-Quelle: <strong id="keySource"><?= $hasEnvKey ? 'ENV' : 'Session/User Input' ?></strong></div>
      <div class="status-chip">Key gespeichert: <strong id="keyStored"><?= ($hasEnvKey || $hasSessionKey) ? 'ja' : 'nein' ?></strong></div>
      <div class="status-chip">Session aktiv: <strong id="sessionState"><?= session_status() === PHP_SESSION_ACTIVE ? 'ja' : 'nein' ?></strong></div>
    </div>
    <p class="muted" id="statusMessage">Bereit.</p>
  </div>

  <div class="card">
    <label for="fullText"><strong>Gesamter Text</strong></label>
    <textarea id="fullText" placeholder="Text hier einfügen..."></textarea>

    <div class="row" style="margin-top:10px;">
      <div>
        <label for="chunkSize"><strong>Chunk size</strong></label>
        <input id="chunkSize" type="number" min="300" step="100" value="2000">
      </div>
      <div>
        <label for="modelSelect"><strong>Modell wählen</strong></label>
        <select id="modelSelect">
          <option value="https://openrouter.ai/stepfun/step-3.5-flash:free" selected>stepfun/step-3.5-flash:free</option>
          <option value="https://openrouter.ai/arcee-ai/trinity-large-preview:free">arcee-ai/trinity-large-preview:free</option>
          <option value="__custom__">Custom</option>
        </select>
      </div>
      <div>
        <label for="modelInput"><strong>Model input (URL oder Model-ID)</strong></label>
        <input id="modelInput" type="text" value="https://openrouter.ai/stepfun/step-3.5-flash:free">
      </div>
    </div>

    <div class="row" style="margin-top:10px;">
      <div>
        <label for="apiKey"><strong>OpenRouter API Key</strong></label>
        <input id="apiKey" type="password" placeholder="sk-or-v1-..." <?= $hasEnvKey ? 'disabled' : '' ?>>
      </div>
      <button class="tight" id="btnSaveKey" <?= $hasEnvKey ? 'disabled' : '' ?>>Key speichern</button>
      <button class="tight alt" id="btnDeleteKey" <?= $hasEnvKey ? 'disabled' : '' ?>>Key löschen</button>
      <button class="tight alt" id="btnPing">Test API</button>
    </div>
    <?php if ($hasEnvKey): ?>
      <p class="muted">Key kommt aus ENV (OPENROUTER_API_KEY). Manuelle Eingabe ist deaktiviert.</p>
    <?php endif; ?>

    <div class="row toolbar" style="margin-top:10px;">
      <button id="btnSplit" class="tight">Aufteilen</button>
      <button id="btnSplitCorrect" class="tight">Aufteilen &amp; Korrigieren</button>
      <button id="btnStop" class="warn tight">Stop All</button>
      <button id="btnReset" class="alt tight">Reset</button>
      <div class="status-chip global-counter">Global: <strong id="globalStats">0/0 fertig, Fehler: 0, läuft: 0</strong></div>
    </div>

    <div class="row" style="margin-top:10px;">
      <label class="tight" style="display:flex; gap:8px; align-items:center;">
        <input type="checkbox" id="smoothLineBreaks" checked style="width:auto;">
        Zeilenumbrüche glätten
      </label>
      <label class="tight" style="display:flex; gap:8px; align-items:center;">
        <input type="checkbox" id="preferParagraphSplit" checked style="width:auto;">
        Am Absatzende trennen (empfohlen)
      </label>
      <label class="tight" style="display:flex; gap:8px; align-items:center;">
        <input type="checkbox" id="splitOnWordBoundary" checked style="width:auto;">
        Notfalls an Wortgrenze trennen
      </label>
      <label class="tight" style="display:flex; gap:8px; align-items:center;">
        <input type="checkbox" id="enableScrollSync" checked style="width:auto;">
        Scroll-Sync (links/rechts)
      </label>
    </div>
  </div>

  <div class="card">
    <h3>Blöcke</h3>
    <div id="blocks" class="blocks"></div>
  </div>

  <div class="card">
    <h3>Export</h3>
    <div class="row">
      <button id="btnMerge">Alles zusammenfügen (korrigiert)</button>
      <button id="btnFluid">Flüssigen Text exportieren</button>
      <button id="btnCopy" class="alt">Copy to clipboard</button>
    </div>
    <textarea id="exportText" style="margin-top:10px; min-height:180px;"></textarea>
  </div>
</div>

<script>
window.CSRF_TOKEN = <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

(() => {
  const MODEL_STORAGE_KEY = 'variantC_model_input';
  const DEFAULT_MODEL = 'https://openrouter.ai/stepfun/step-3.5-flash:free';
  const KNOWN_MODELS = [
    'https://openrouter.ai/stepfun/step-3.5-flash:free',
    'https://openrouter.ai/arcee-ai/trinity-large-preview:free'
  ];

  const state = {
    blocks: [],
    queue: [],
    running: new Map(),
    maxConcurrent: 2,
    stopRequested: false
  };

  const el = {
    fullText: document.getElementById('fullText'),
    chunkSize: document.getElementById('chunkSize'),
    modelSelect: document.getElementById('modelSelect'),
    modelInput: document.getElementById('modelInput'),
    apiKey: document.getElementById('apiKey'),
    blocks: document.getElementById('blocks'),
    exportText: document.getElementById('exportText'),
    globalStats: document.getElementById('globalStats'),
    apiReach: document.getElementById('apiReach'),
    keyStored: document.getElementById('keyStored'),
    statusMessage: document.getElementById('statusMessage'),
    smoothLineBreaks: document.getElementById('smoothLineBreaks'),
    preferParagraphSplit: document.getElementById('preferParagraphSplit'),
    splitOnWordBoundary: document.getElementById('splitOnWordBoundary'),
    enableScrollSync: document.getElementById('enableScrollSync')
  };

  const safeError = (msg) => ({ ok: false, error: { message: msg || 'Unbekannter Fehler' }, httpStatus: 0 });

  function debounce(fn, waitMs) {
    let timer = null;
    return (...args) => {
      clearTimeout(timer);
      timer = setTimeout(() => fn(...args), waitMs);
    };
  }

  async function apiCall(action, data = {}) {
    const payload = { action, csrfToken: window.CSRF_TOKEN, ...data };
    try {
      const res = await fetch('./api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        cache: 'no-store',
        body: JSON.stringify(payload),
        signal: data.__signal
      });

      const text = await res.text();
      let parsed;
      try {
        parsed = JSON.parse(text);
      } catch {
        return safeError('Ungültige Serverantwort (kein JSON).');
      }
      return { ...parsed, httpStatus: parsed.httpStatus ?? res.status };
    } catch (err) {
      if (err.name === 'AbortError') {
        return { ok: false, aborted: true, error: { message: 'Abgebrochen' }, httpStatus: 0 };
      }
      return safeError('Netzwerkfehler: ' + err.message);
    }
  }

  function statusClass(s) {
    if (s === 'fertig') return 'ok';
    if (s === 'Fehler' || s === 'abgebrochen') return 'err';
    if (['sendet…','antwortet…','in Warteschlange'].includes(s)) return 'run';
    return '';
  }

  function updateGlobalStats() {
    const total = state.blocks.length;
    const done = state.blocks.filter((b) => b.status === 'fertig').length;
    const errors = state.blocks.filter((b) => b.status === 'Fehler').length;
    const running = state.blocks.filter((b) => ['sendet…', 'antwortet…', 'in Warteschlange'].includes(b.status)).length;
    el.globalStats.textContent = `${done}/${total} fertig, Fehler: ${errors}, läuft: ${running}`;
  }

  function renderBlocks() {
    el.blocks.innerHTML = '';
    state.blocks.forEach((b, i) => {
      const card = document.createElement('div');
      card.className = 'block';
      const elapsed = typeof b.elapsedMs === 'number' ? `${(b.elapsedMs / 1000).toFixed(2)}s` : '-';
      card.innerHTML = `
        <div class="block-head">
          <strong>Block ${i + 1}</strong>
          <span>inputChars: ${b.meta.inputChars ?? b.originalText.length}</span>
          <span>outputChars: ${b.meta.outputChars ?? b.correctedText.length}</span>
          <span>Model: ${escapeHtml(b.meta.model || '-')}</span>
          <span class="badge ${statusClass(b.status)}">${b.status}</span>
          <span>HTTP: ${b.httpStatus ?? '-'}</span>
          <span>Zeit: ${elapsed}</span>
          ${['sendet…','antwortet…'].includes(b.status) ? '<div class="progress"><div></div></div>' : ''}
          <button data-retry="${b.id}" class="alt tight">Retry</button>
        </div>
        <div class="grid-2" data-block-id="${b.id}">
          <div>
            <label>Original</label>
            <textarea data-original="${b.id}">${escapeHtml(b.originalText)}</textarea>
          </div>
          <div>
            <label>Korrigiert</label>
            <textarea data-corrected="${b.id}">${escapeHtml(b.correctedText)}</textarea>
          </div>
        </div>
        <div class="err-text">${escapeHtml(b.errorMessage || '')}</div>
      `;
      el.blocks.appendChild(card);
    });

    document.querySelectorAll('[data-original]').forEach((t) => {
      t.addEventListener('input', (e) => {
        const id = Number(e.target.dataset.original);
        const block = state.blocks.find((x) => x.id === id);
        if (block) block.originalText = e.target.value;
      });
    });

    document.querySelectorAll('[data-corrected]').forEach((t) => {
      t.addEventListener('input', (e) => {
        const id = Number(e.target.dataset.corrected);
        const block = state.blocks.find((x) => x.id === id);
        if (block) block.correctedText = e.target.value;
      });
    });

    document.querySelectorAll('[data-retry]').forEach((btn) => {
      btn.addEventListener('click', () => enqueueRetry(Number(btn.dataset.retry)));
    });

    document.querySelectorAll('[data-block-id]').forEach((row) => setupBlockScrollSync(row));
    updateGlobalStats();
  }

  function syncScroll(source, target) {
    const sourceMax = source.scrollHeight - source.clientHeight;
    const targetMax = target.scrollHeight - target.clientHeight;
    const ratio = sourceMax <= 0 ? 0 : source.scrollTop / sourceMax;
    target.scrollTop = targetMax <= 0 ? 0 : ratio * targetMax;
  }

  function setupBlockScrollSync(blockRow) {
    const leftTA = blockRow.querySelector('[data-original]');
    const rightTA = blockRow.querySelector('[data-corrected]');
    if (!leftTA || !rightTA) return;

    let isSyncing = false;
    let rafLeft = null;
    let rafRight = null;

    const runSync = (source, target) => {
      if (!el.enableScrollSync.checked || isSyncing) return;
      isSyncing = true;
      syncScroll(source, target);
      requestAnimationFrame(() => { isSyncing = false; });
    };

    leftTA.addEventListener('scroll', () => {
      if (rafLeft) return;
      rafLeft = requestAnimationFrame(() => {
        rafLeft = null;
        runSync(leftTA, rightTA);
      });
    });

    rightTA.addEventListener('scroll', () => {
      if (rafRight) return;
      rafRight = requestAnimationFrame(() => {
        rafRight = null;
        runSync(rightTA, leftTA);
      });
    });
  }

  function escapeHtml(v) {
    return (v || '').replace(/[&<>"']/g, (ch) => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[ch]));
  }

  function applyOcrSpecialRules(text) {
    let t = text;
    // Spezialregel: öffentliche/Öffentlichkeit OCR-Muster "ö? ent" -> "öffent".
    t = t.replace(/([Öö])\?\s*ent/gu, '$1ffent');
    // Spezialregel: Haftung OCR-Muster "Ha? ung" -> "Haftung".
    t = t.replace(/Ha\?\s*ung/gu, 'Haftung');
    // Spezialregel: Themen OCR-Muster "The? en" und "? emen" -> "Themen".
    t = t.replace(/The\?\s*en/gu, 'Themen').replace(/\?\s*emen/gu, 'Themen');
    // Spezialregel: finden OCR-Muster mit Steuerzeichen/Leerzeichen vor "nden" -> "finden".
    t = t.replace(/(?:\u0016|\s)nden\b/gu, ' finden');
    return t;
  }

  function cleanupUrlsAndQuotes(text) {
    let t = text;
    // URL/Domain-Spaces: "www. " -> "www." und Spaces um Punkte in Domains entfernen.
    t = t.replace(/\bwww\.\s+/giu, 'www.')
      .replace(/([\p{L}\d-])\s*\.\s*(de|com|net|org|info|eu)\b/giu, '$1.$2');
    // Anführungszeichen-Spaces: Space direkt nach » und direkt vor « entfernen.
    t = t.replace(/»\s+/gu, '»').replace(/\s+«/gu, '«');
    return t;
  }

  function preCleanupText(text) {
    let t = (text || '').normalize('NFKC');
    t = t
      .replace(/ﬀ/gu, 'ff')
      .replace(/ﬁ/gu, 'fi')
      .replace(/ﬂ/gu, 'fl')
      .replace(/ﬃ/gu, 'ffi')
      .replace(/ﬄ/gu, 'ffl');

    t = applyOcrSpecialRules(t);
    t = cleanupUrlsAndQuotes(t);

    // Zwischen Buchstaben stehendes ? als OCR-Platzhalter zu "f" (vorsichtig, deutsch OCR-Fälle).
    t = t.replace(/(?<=\p{L})\?(?=\p{L})/gu, 'f');
    // Spaces mitten im Wort reduzieren: "Selbstwer t" -> "Selbstwert".
    t = t.replace(/(\p{L})\s+(\p{L})/gu, '$1$2');
    t = t.replace(/\s+([,.;:!?])/gu, '$1').replace(/[ \t]{2,}/gu, ' ');
    return t;
  }

  function splitIntoChunksSmart(text, maxLen = 2000, options = {}) {
    const {
      preferParagraphSplit = true,
      minChunkFraction = 0.6,
      keepDelimiter = true,
      splitOnWordBoundary = true
    } = options;

    const normalizedText = (text || '').replace(/\r\n|\r/g, '\n');
    const chunks = [];
    const clampedMaxLen = Math.max(1, Number(maxLen) || 2000);
    const minFraction = Math.min(0.95, Math.max(0.1, Number(minChunkFraction) || 0.6));

    const isLetter = (char) => /\p{L}/u.test(char || '');
    let rest = normalizedText;

    while (rest.length > clampedMaxLen) {
      const searchStart = Math.min(Math.floor(clampedMaxLen * minFraction), rest.length);
      const searchEnd = Math.min(clampedMaxLen, rest.length);
      let splitPoint = -1;

      if (preferParagraphSplit) {
        const windowText = rest.slice(searchStart, searchEnd);
        let match;
        const paragraphRegex = /\n{2,}/g;
        while ((match = paragraphRegex.exec(windowText)) !== null) {
          splitPoint = searchStart + match.index + (keepDelimiter ? match[0].length : 0);
        }
      }

      if (splitPoint < 0 && splitOnWordBoundary) {
        const windowText = rest.slice(searchStart, searchEnd);
        let match;
        const wsRegex = /\s+/g;
        while ((match = wsRegex.exec(windowText)) !== null) {
          splitPoint = searchStart + match.index + match[0].length;
        }
      }

      if (splitPoint < 0) splitPoint = clampedMaxLen;

      // Safety-Check: niemals mitten im Wort schneiden.
      if (splitPoint < rest.length && isLetter(rest[splitPoint - 1]) && isLetter(rest[splitPoint])) {
        let safe = splitPoint - 1;
        while (safe > 0 && !/\s/u.test(rest[safe])) safe--;
        if (safe > 0) {
          while (safe < rest.length && /\s/u.test(rest[safe])) safe++;
          splitPoint = safe;
        }
      }

      chunks.push(rest.slice(0, splitPoint));
      rest = rest.slice(splitPoint).replace(/^\n(?!\n)/, '');
    }

    if (rest.length) chunks.push(rest);
    return chunks;
  }

  function splitText() {
    const text = preCleanupText(el.fullText.value || '');
    const size = Math.max(300, Number(el.chunkSize.value) || 2000);
    const textChunks = splitIntoChunksSmart(text, size, {
      preferParagraphSplit: el.preferParagraphSplit.checked,
      splitOnWordBoundary: el.splitOnWordBoundary.checked,
      minChunkFraction: 0.6,
      keepDelimiter: true
    });

    state.blocks = textChunks.map((chunk, i) => ({
      id: i + 1,
      originalText: chunk,
      correctedText: '',
      status: 'wartet',
      errorMessage: '',
      httpStatus: null,
      elapsedMs: null,
      meta: {}
    }));

    state.queue = [];
    state.running.clear();
    state.stopRequested = false;
    renderBlocks();
  }

  function enqueueAll() {
    state.queue = state.blocks.map((b) => ({ blockId: b.id, type: 'correct' }));
    state.blocks.forEach((b) => {
      b.status = 'in Warteschlange';
      b.errorMessage = '';
      b.httpStatus = null;
      b.elapsedMs = null;
    });
    updateGlobalStats();
    renderBlocks();
    drainQueue();
  }

  function enqueueRetry(blockId) {
    const b = state.blocks.find((x) => x.id === blockId);
    if (!b) return;
    b.status = 'in Warteschlange';
    b.errorMessage = '';
    b.httpStatus = null;
    b.elapsedMs = null;
    state.queue.push({ blockId, type: 'correct' });
    renderBlocks();
    drainQueue();
  }

  async function runTask(task) {
    const block = state.blocks.find((b) => b.id === task.blockId);
    if (!block) return;
    const controller = new AbortController();
    state.running.set(task.blockId, controller);

    block.status = 'sendet…';
    block.errorMessage = '';
    block.httpStatus = null;
    renderBlocks();

    const result = await apiCall('correctBlock', {
      blockId: block.id,
      chunkText: preCleanupText(block.originalText),
      modelInput: el.modelInput.value,
      __signal: controller.signal
    });

    if (result.aborted || state.stopRequested) {
      block.status = 'abgebrochen';
      block.errorMessage = 'Request wurde abgebrochen.';
      block.httpStatus = result.httpStatus ?? 0;
    } else if (!result.ok) {
      block.status = 'Fehler';
      block.errorMessage = `HTTP ${result.httpStatus ?? 0}: ${result?.error?.message || 'Unbekannter Fehler'}`;
      block.httpStatus = result.httpStatus ?? 0;
    } else {
      block.status = 'fertig';
      const correctedText = result.correctedText || '';
      const withPostRules = cleanupUrlsAndQuotes(el.smoothLineBreaks.checked ? normalizeAndFluidifyText(correctedText) : correctedText);
      block.correctedText = withPostRules;
      block.httpStatus = result.httpStatus ?? 200;
      block.meta = result.meta || {};
      block.elapsedMs = typeof result?.meta?.elapsedMs === 'number' ? result.meta.elapsedMs : null;
    }

    state.running.delete(task.blockId);
    renderBlocks();
    drainQueue();
  }

  function drainQueue() {
    if (state.stopRequested) return;
    while (state.running.size < state.maxConcurrent && state.queue.length > 0) {
      const next = state.queue.shift();
      const block = state.blocks.find((b) => b.id === next.blockId);
      if (!block || block.status === 'fertig') continue;
      block.status = 'antwortet…';
      renderBlocks();
      runTask(next);
    }
  }

  function stopAll() {
    state.stopRequested = true;
    state.queue = [];
    for (const [id, controller] of state.running.entries()) {
      controller.abort();
      const b = state.blocks.find((x) => x.id === id);
      if (b) b.status = 'abgebrochen';
    }
    state.running.clear();
    renderBlocks();
  }

  function resetAll() {
    stopAll();
    state.blocks = [];
    el.fullText.value = '';
    el.exportText.value = '';
    el.statusMessage.textContent = 'Zurückgesetzt.';
    renderBlocks();
  }

  function mergeCorrected() {
    el.exportText.value = state.blocks.map((b) => b.correctedText || b.originalText).join('\n\n');
  }

  function normalizeAndFluidifyText(text) {
    let t = (text || '').replace(/\r\n?/g, '\n').replace(/\n{3,}/g, '\n\n');
    t = t.replace(/-\n(?=\p{L})/gu, '');

    const paragraphs = t.split(/\n{2}/);
    const outputParagraphs = [];
    const isListLine = (line) => /^\s*(?:[-*•–]|\d+[.)]|\(\d+\)|[IVXLCDM]+\.)\s+/u.test(line);
    const isMetaLine = (line) => /(?:ISBN|©|www\.|https?:\/\/|\S+@\S+|\b\d{5}\b|\b(?:München|Str\.?|Straße)\b)/iu.test(line);
    const isHeadingLike = (line) => {
      const words = (line.match(/\p{L}+/gu) || []).length;
      if (words > 0 && words <= 4) return true;
      const letters = (line.match(/\p{L}/gu) || []).join('');
      if (!letters) return false;
      const uppercase = (letters.match(/\p{Lu}/gu) || []).length;
      return uppercase / letters.length > 0.7;
    };

    for (const paragraph of paragraphs) {
      const lines = paragraph.split('\n').map((line) => line.trim()).filter(Boolean);
      if (!lines.length) continue;
      let current = lines[0];
      const built = [];

      for (let i = 1; i < lines.length; i++) {
        const next = lines[i];
        const keep = isListLine(current) || isListLine(next) || isMetaLine(current) || isMetaLine(next) || isHeadingLike(current);
        if (keep) {
          built.push(current);
          current = next;
        } else {
          current = `${current.trimEnd()} ${next.trimStart()}`;
        }
      }
      built.push(current);
      outputParagraphs.push(built.join('\n').replace(/\s+([,.;:!?])/gu, '$1').replace(/[ \t]{2,}/gu, ' ').trim());
    }

    return outputParagraphs.join('\n\n').replace(/\n{3,}/g, '\n\n').trim();
  }

  async function pingApi() {
    el.statusMessage.textContent = 'Teste API...';
    const res = await apiCall('ping', {});
    if (res.ok) {
      el.apiReach.textContent = 'ja';
      el.statusMessage.textContent = `API ok (${res.time || '-'})`;
    } else {
      el.apiReach.textContent = 'nein';
      el.statusMessage.textContent = `API Fehler: ${res?.error?.message || 'Unbekannt'}`;
    }
  }

  const saveModelInputDebounced = debounce(() => {
    localStorage.setItem(MODEL_STORAGE_KEY, el.modelInput.value.trim());
  }, 300);

  el.modelSelect.addEventListener('change', () => {
    if (el.modelSelect.value === '__custom__') return;
    el.modelInput.value = el.modelSelect.value;
    saveModelInputDebounced();
  });

  el.modelInput.addEventListener('input', () => {
    const current = el.modelInput.value.trim();
    if (KNOWN_MODELS.includes(current)) {
      el.modelSelect.value = current;
    } else {
      el.modelSelect.value = '__custom__';
    }
    saveModelInputDebounced();
  });

  document.getElementById('btnSplit').addEventListener('click', splitText);
  document.getElementById('btnSplitCorrect').addEventListener('click', () => {
    splitText();
    enqueueAll();
  });
  document.getElementById('btnStop').addEventListener('click', stopAll);
  document.getElementById('btnReset').addEventListener('click', resetAll);
  document.getElementById('btnMerge').addEventListener('click', mergeCorrected);
  document.getElementById('btnFluid').addEventListener('click', () => {
    mergeCorrected();
    el.exportText.value = cleanupUrlsAndQuotes(normalizeAndFluidifyText(el.exportText.value));
  });

  document.getElementById('btnCopy').addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(el.exportText.value);
      el.statusMessage.textContent = 'In Zwischenablage kopiert.';
    } catch {
      el.statusMessage.textContent = 'Kopieren fehlgeschlagen.';
    }
  });
  document.getElementById('btnPing').addEventListener('click', pingApi);

  document.getElementById('btnSaveKey').addEventListener('click', async () => {
    const apiKey = el.apiKey.value.trim();
    if (!apiKey) { el.statusMessage.textContent = 'Bitte API-Key eingeben.'; return; }
    const res = await apiCall('saveKey', { apiKey });
    el.statusMessage.textContent = res.ok ? 'Key gespeichert.' : `Fehler: ${res?.error?.message || 'unbekannt'}`;
    el.keyStored.textContent = res.ok ? 'ja' : el.keyStored.textContent;
  });

  document.getElementById('btnDeleteKey').addEventListener('click', async () => {
    const res = await apiCall('deleteKey', {});
    el.statusMessage.textContent = res.ok ? 'Key gelöscht.' : `Fehler: ${res?.error?.message || 'unbekannt'}`;
    if (res.ok) {
      el.keyStored.textContent = 'nein';
      el.apiKey.value = '';
    }
  });

  const storedModel = localStorage.getItem(MODEL_STORAGE_KEY);
  if (storedModel && storedModel.trim() !== '') {
    el.modelInput.value = storedModel.trim();
    if (KNOWN_MODELS.includes(storedModel.trim())) {
      el.modelSelect.value = storedModel.trim();
    } else {
      el.modelSelect.value = '__custom__';
    }
  } else {
    el.modelInput.value = DEFAULT_MODEL;
    el.modelSelect.value = DEFAULT_MODEL;
  }

  renderBlocks();
})();
</script>
</body>
</html>
