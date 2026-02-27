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
    body { font-family: Arial, sans-serif; margin: 0; background: #f3f4f6; color: #111827; }
    .container { max-width: 1200px; margin: 0 auto; padding: 12px 16px 24px; }
    .section { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px; margin-bottom: 12px; }
    h1, h2, h3 { margin: 0; }
    h2 { margin-bottom: 10px; font-size: 20px; }
    textarea, input, button, select { font: inherit; }
    textarea, input[type="text"], input[type="number"], input[type="password"], select {
      width: 100%; box-sizing: border-box; padding: 9px; border: 1px solid #d1d5db; border-radius: 8px;
    }
    textarea { min-height: 140px; resize: vertical; }
    .muted { color: #6b7280; font-size: 13px; }

    .topbar {
      position: sticky; top: 0; z-index: 30; margin-bottom: 12px;
      background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(3px);
      border: 1px solid #e5e7eb; border-radius: 10px; padding: 10px 12px;
      display: flex; justify-content: space-between; align-items: flex-start; gap: 10px;
    }
    .topbar-right { text-align: right; min-width: 280px; }
    .counter-line { font-weight: 600; display: flex; justify-content: flex-end; gap: 8px; flex-wrap: wrap; align-items: center; }
    .running-indicator { color: #2563eb; font-size: 13px; display: none; }
    .running-indicator.active { display: inline-block; }
    .dot { display: inline-block; width: 8px; height: 8px; border-radius: 999px; background: #2563eb; margin-right: 4px; animation: pulse 1.2s ease-in-out infinite; }
    @keyframes pulse { 0%, 100% { opacity: .35; } 50% { opacity: 1; } }

    .global-progress { height: 6px; background: #e5e7eb; border-radius: 999px; margin-top: 6px; overflow: hidden; }
    .global-progress > div { height: 100%; width: 0; background: #2563eb; transition: width .2s ease; }

    .form-grid { display: grid; grid-template-columns: 170px 1fr auto auto auto; gap: 8px; align-items: end; margin-top: 10px; }
    .field label { display: block; margin-bottom: 4px; font-weight: 600; font-size: 14px; }

    button { border: none; border-radius: 8px; padding: 9px 12px; cursor: pointer; }
    button.primary { background: #2563eb; color: #fff; font-weight: 700; }
    button.primary:hover { background: #1d4ed8; }
    button.secondary { background: #e5e7eb; color: #111827; }
    button.secondary:hover { background: #d1d5db; }
    button.danger { background: #b91c1c; color: #fff; }
    button.danger:hover { background: #991b1b; }
    button:disabled { opacity: .6; cursor: not-allowed; }

    .blocks { display: grid; gap: 10px; margin-top: 8px; }
    .block { border: 1px solid #e5e7eb; border-radius: 10px; padding: 10px; background: #fff; }
    .block-head { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 8px; }
    .badge { padding: 2px 9px; border-radius: 999px; font-size: 12px; background: #e5e7eb; }
    .badge.ok { background: #dcfce7; }
    .badge.err { background: #fee2e2; }
    .badge.run { background: #dbeafe; }
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .block textarea { min-height: 110px; }

    details { border: 1px solid #e5e7eb; border-radius: 10px; background: #fff; }
    details > summary { cursor: pointer; padding: 12px; font-weight: 700; }
    details[open] > summary { border-bottom: 1px solid #e5e7eb; }
    details .details-body { padding: 12px; }

    .inline-checks { display: flex; flex-wrap: wrap; gap: 10px 14px; }
    .inline-checks label { display: flex; align-items: center; gap: 6px; font-size: 14px; }
    .inline-checks input[type="checkbox"] { width: auto; }

    .link-btn { background: none; color: #b91c1c; padding: 0; border: 0; text-decoration: underline; }
    .link-btn:disabled { color: #9ca3af; text-decoration: none; opacity: 1; }

    .mini-details { margin-top: 8px; border: 1px dashed #e5e7eb; border-radius: 8px; padding: 7px; font-size: 13px; }

    @media (max-width: 960px) {
      .form-grid { grid-template-columns: 1fr; }
      .topbar { flex-direction: column; }
      .topbar-right { text-align: left; }
      .counter-line { justify-content: flex-start; }
      .grid-2 { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
<div class="container">
  <header class="topbar">
    <h1>OCR Korrektur Tool (Variante C)</h1>
    <div class="topbar-right">
      <div class="counter-line">
        <span>Global: <span id="globalStats">0/0 fertig, Fehler: 0, läuft: 0</span></span>
        <button id="errorCountBtn" class="link-btn" disabled>Fehler: 0</button>
        <span id="runningIndicator" class="running-indicator"><span class="dot"></span>läuft…</span>
      </div>
      <div class="global-progress" aria-hidden="true"><div id="globalProgressFill"></div></div>
    </div>
  </header>

  <section class="section">
    <h2>1) Text</h2>
    <label for="fullText"><strong>Gesamter Text (Input)</strong></label>
    <textarea id="fullText" placeholder="Text hier einfügen..."></textarea>

    <div class="form-grid">
      <div class="field">
        <label for="chunkSize">Chunk size</label>
        <input id="chunkSize" type="number" min="300" step="100" value="2000">
      </div>
      <div class="field">
        <label for="modelSelect">Modell wählen</label>
        <select id="modelSelect">
          <option value="https://openrouter.ai/arcee-ai/trinity-large-preview:free" selected>arcee-ai/trinity-large-preview:free</option>
          <option value="https://openrouter.ai/stepfun/step-3.5-flash:free">stepfun/step-3.5-flash:free</option>
        </select>
      </div>
      <button id="btnSplitCorrect" class="primary">Aufteilen &amp; Korrigieren</button>
      <button id="btnSplit" class="secondary">Aufteilen</button>
      <button id="btnReset" class="secondary">Reset</button>
    </div>

    <div style="margin-top: 10px;">
      <button id="btnStop" class="danger">Stop All</button>
    </div>
  </section>

  <section class="section" id="resultsSection">
    <h2>2) Ergebnisse</h2>
    <p id="emptyHint" class="muted">Noch keine Blöcke – bitte aufteilen.</p>
    <div id="blocks" class="blocks" hidden></div>
  </section>

  <details open>
    <summary>3) Export</summary>
    <div class="details-body">
      <div style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:8px;">
        <button id="btnMerge" class="secondary">Alles zusammenfügen (korrigiert)</button>
        <button id="btnFluid" class="secondary">Flüssigen Text exportieren</button>
        <button id="btnCopy" class="secondary">Copy</button>
      </div>
      <textarea id="exportText" style="min-height:180px;"></textarea>
    </div>
  </details>

  <details style="margin-top: 12px;">
    <summary>Erweiterte Einstellungen</summary>
    <div class="details-body">
      <div class="field" style="margin-bottom:10px;">
        <label for="modelInput">Model input (URL oder Model-ID)</label>
        <input id="modelInput" type="text" value="https://openrouter.ai/arcee-ai/trinity-large-preview:free">
        <div class="muted">Manuelles Model überschreibt Dropdown.</div>
      </div>

      <div class="inline-checks">
        <label><input type="checkbox" id="enablePreCleanup">Pre-Cleanup anwenden</label>
        <label><input type="checkbox" id="preferParagraphSplit" checked>Am Absatzende trennen</label>
        <label><input type="checkbox" id="splitOnWordBoundary" checked>Notfalls an Wortgrenze trennen</label>
        <label><input type="checkbox" id="enableScrollSync" checked>Scroll-Sync</label>
      </div>
    </div>
  </details>

  <details style="margin-top: 12px;" id="apiSystemDetails">
    <summary>API &amp; System</summary>
    <div class="details-body">
      <div class="muted" style="margin-bottom:8px;">
        API erreichbar: <strong id="apiReach">unbekannt</strong> ·
        Key-Quelle: <strong id="keySource"><?= $hasEnvKey ? 'ENV' : 'Session/User Input' ?></strong> ·
        Key gespeichert: <strong id="keyStored"><?= ($hasEnvKey || $hasSessionKey) ? 'ja' : 'nein' ?></strong> ·
        Session aktiv: <strong id="sessionState"><?= session_status() === PHP_SESSION_ACTIVE ? 'ja' : 'nein' ?></strong>
      </div>

      <div class="field" style="margin-bottom:8px;">
        <label for="apiKey">OpenRouter API Key</label>
        <input id="apiKey" type="password" placeholder="sk-or-v1-..." <?= $hasEnvKey ? 'disabled' : '' ?>>
      </div>
      <div style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:8px;">
        <button id="btnSaveKey" class="secondary" <?= $hasEnvKey ? 'disabled' : '' ?>>Key speichern</button>
        <button id="btnDeleteKey" class="secondary" <?= $hasEnvKey ? 'disabled' : '' ?>>Key löschen</button>
        <button id="btnPing" class="secondary">Test API</button>
      </div>
      <?php if ($hasEnvKey): ?>
      <p class="muted">Key kommt aus ENV (OPENROUTER_API_KEY). Manuelle Eingabe ist deaktiviert.</p>
      <?php endif; ?>
      <p class="muted" id="statusMessage">Bereit.</p>
    </div>
  </details>
</div>

<script>
window.CSRF_TOKEN = <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

(() => {
  const MODEL_STORAGE_KEY = 'variantC_model_input';
  const MODEL_SELECT_STORAGE_KEY = 'variantC_model_select';
  const DEFAULT_MODEL = 'https://openrouter.ai/arcee-ai/trinity-large-preview:free';
  const KNOWN_MODELS = [
    'https://openrouter.ai/arcee-ai/trinity-large-preview:free',
    'https://openrouter.ai/stepfun/step-3.5-flash:free'
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
    emptyHint: document.getElementById('emptyHint'),
    exportText: document.getElementById('exportText'),
    globalStats: document.getElementById('globalStats'),
    globalProgressFill: document.getElementById('globalProgressFill'),
    errorCountBtn: document.getElementById('errorCountBtn'),
    runningIndicator: document.getElementById('runningIndicator'),
    apiReach: document.getElementById('apiReach'),
    keyStored: document.getElementById('keyStored'),
    statusMessage: document.getElementById('statusMessage'),
    enablePreCleanup: document.getElementById('enablePreCleanup'),
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
    if (['sendet…', 'antwortet…', 'in Warteschlange'].includes(s)) return 'run';
    return '';
  }

  function updateGlobalStats() {
    const total = state.blocks.length;
    const done = state.blocks.filter((b) => b.status === 'fertig').length;
    const errors = state.blocks.filter((b) => b.status === 'Fehler').length;
    const running = state.blocks.filter((b) => ['sendet…', 'antwortet…'].includes(b.status)).length;

    el.globalStats.textContent = `${done}/${total} fertig, Fehler: ${errors}, läuft: ${running}`;
    el.errorCountBtn.textContent = `Fehler: ${errors}`;
    el.errorCountBtn.disabled = errors === 0;
    el.runningIndicator.classList.toggle('active', running > 0);

    const ratio = total === 0 ? 0 : (done / total) * 100;
    el.globalProgressFill.style.width = `${Math.max(0, Math.min(100, ratio))}%`;
  }

  function renderBlocks() {
    const hasBlocks = state.blocks.length > 0;
    el.blocks.hidden = !hasBlocks;
    el.emptyHint.hidden = hasBlocks;

    el.blocks.innerHTML = '';
    state.blocks.forEach((b, i) => {
      const card = document.createElement('div');
      card.className = 'block';
      card.dataset.status = b.status;
      card.id = `block-${b.id}`;

      const showTime = ['sendet…', 'antwortet…', 'fertig'].includes(b.status);
      const elapsed = typeof b.elapsedMs === 'number' ? `${(b.elapsedMs / 1000).toFixed(2)}s` : 'läuft…';

      card.innerHTML = `
        <div class="block-head">
          <strong>Block ${i + 1}</strong>
          <span>|</span>
          <span class="badge ${statusClass(b.status)}">${b.status}</span>
          ${showTime ? `<span>| Zeit: ${escapeHtml(elapsed)}</span>` : ''}
          ${b.status === 'Fehler' ? `<button data-retry="${b.id}" class="secondary">Retry</button>` : ''}
          <details>
            <summary>Details</summary>
            <div class="mini-details">
              Model: ${escapeHtml(b.meta.model || getEffectiveModel() || '-')}<br>
              HTTP-Code: ${b.httpStatus ?? '-'}<br>
              inputChars: ${b.meta.inputChars ?? b.originalText.length}<br>
              outputChars: ${b.meta.outputChars ?? b.correctedText.length}<br>
              elapsedMs: ${typeof b.elapsedMs === 'number' ? b.elapsedMs : '-'}<br>
              error.message: ${escapeHtml(b.errorMessage || '-')}
            </div>
          </details>
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

  function cleanupUrlsAndQuotes(text) {
    let t = text;
    t = t.replace(/\b(?:www\.\s*)?[a-z0-9-]+(?:\s*\.\s*[a-z]{2,})+\b/giu, (domainToken) => (
      domainToken
        .replace(/^www\.\s*/iu, 'www.')
        .replace(/\s*\.\s*/gu, '.')
    ));
    t = t.replace(/»\s+/gu, '»').replace(/\s+«/gu, '«');
    return t;
  }

  function collapseSpacedLetterChains(text) {
    return text.replace(/(?:\p{L}\s){3,}\p{L}/gu, (chain) => chain.replace(/\s+/gu, ''));
  }

  function collapseSpacesPerLine(text) {
    return text
      .split('\n')
      .map((line) => line.replace(/[ ]{2,}/gu, ' '))
      .join('\n');
  }

  function preCleanupOCR(text) {
    let t = (text || '').replace(/\r\n|\r/g, '\n').normalize('NFKC');
    t = t
      .replace(/ﬀ/gu, 'ff')
      .replace(/ﬁ/gu, 'fi')
      .replace(/ﬂ/gu, 'fl')
      .replace(/ﬃ/gu, 'ffi')
      .replace(/ﬄ/gu, 'ffl');

    t = collapseSpacedLetterChains(t);
    t = cleanupUrlsAndQuotes(t);
    t = t.replace(/\t/gu, ' ');
    t = collapseSpacesPerLine(t);
    return t;
  }

  function postCleanup(text) {
    let t = cleanupUrlsAndQuotes(text || '');
    t = t.replace(/\s+([,.;:!?])/gu, '$1');
    t = t.replace(/(?<=\p{L})\?+(?=\p{L})/gu, '');
    t = t.replace(/([.!?])(?![\s\n]|$)/gu, '$1 ');
    t = t.replace(/ {2,}/gu, ' ');
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
    const rawText = el.fullText.value || '';
    const text = el.enablePreCleanup.checked ? preCleanupOCR(rawText) : rawText;
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

  function getEffectiveModel() {
    const manual = el.modelInput.value.trim();
    if (manual !== '') return manual;
    return el.modelSelect.value || DEFAULT_MODEL;
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
      chunkText: block.originalText,
      preCleanupEnabled: el.enablePreCleanup.checked,
      modelInput: getEffectiveModel(),
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
      block.correctedText = postCleanup(correctedText);
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
    localStorage.setItem(MODEL_SELECT_STORAGE_KEY, el.modelSelect.value);
  });

  el.modelInput.addEventListener('input', () => {
    saveModelInputDebounced();
  });

  el.errorCountBtn.addEventListener('click', () => {
    const firstError = state.blocks.find((b) => b.status === 'Fehler');
    if (!firstError) return;
    const node = document.getElementById(`block-${firstError.id}`);
    if (node) node.scrollIntoView({ behavior: 'smooth', block: 'start' });
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
    el.exportText.value = postCleanup(normalizeAndFluidifyText(el.exportText.value));
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
  const storedSelection = localStorage.getItem(MODEL_SELECT_STORAGE_KEY);
  el.modelInput.value = storedModel && storedModel.trim() !== '' ? storedModel.trim() : DEFAULT_MODEL;

  if (storedSelection && KNOWN_MODELS.includes(storedSelection)) {
    el.modelSelect.value = storedSelection;
  } else {
    el.modelSelect.value = DEFAULT_MODEL;
  }

  renderBlocks();
})();
</script>
</body>
</html>
