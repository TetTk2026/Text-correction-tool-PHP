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
    textarea,input,button { font: inherit; }
    textarea,input[type="text"],input[type="number"],input[type="password"] {
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
        <input id="chunkSize" type="number" min="500" step="100" value="10000">
      </div>
      <div>
        <label for="modelInput"><strong>Model input (URL oder Model-ID)</strong></label>
        <input id="modelInput" type="text" value="https://openrouter.ai/arcee-ai/trinity-large-preview:free">
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

    <div class="row" style="margin-top:10px;">
      <button id="btnSplit">Aufteilen</button>
      <button id="btnSplitCorrect">Aufteilen &amp; Korrigieren</button>
      <button id="btnStop" class="warn">Stop All</button>
      <button id="btnReset" class="alt">Reset</button>
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
    </div>

    <div class="row" style="margin-top:10px;">
      <div class="status-chip">Global: <strong id="globalStats">0/0 fertig, Fehler: 0, läuft: 0</strong></div>
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
    splitOnWordBoundary: document.getElementById('splitOnWordBoundary')
  };

  const safeError = (msg) => ({ ok: false, error: { message: msg || 'Unbekannter Fehler' }, httpStatus: 0 });

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
        console.error('[apiCall] JSON parse error', { action, blockId: data.blockId ?? null, httpStatus: res.status });
        return safeError('Ungültige Serverantwort (kein JSON).');
      }

      if (!res.ok || !parsed.ok) {
        console.error('[apiCall] request failed', {
          action,
          blockId: data.blockId ?? null,
          httpStatus: res.status,
          message: parsed?.error?.message || 'Fehler'
        });
      }

      return { ...parsed, httpStatus: parsed.httpStatus ?? res.status };
    } catch (err) {
      if (err.name === 'AbortError') {
        return { ok: false, aborted: true, error: { message: 'Abgebrochen' }, httpStatus: 0 };
      }
      console.error('[apiCall] network error', { action, blockId: data.blockId ?? null, message: err.message });
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
    const done = state.blocks.filter(b => b.status === 'fertig').length;
    const errors = state.blocks.filter(b => b.status === 'Fehler').length;
    const running = state.running.size;
    el.globalStats.textContent = `${done}/${total} fertig, Fehler: ${errors}, läuft: ${running}`;
  }

  function renderBlocks() {
    el.blocks.innerHTML = '';
    state.blocks.forEach((b, i) => {
      const card = document.createElement('div');
      card.className = 'block';
      const elapsed = b.startTime ? ((Date.now() - b.startTime) / 1000).toFixed(1) + 's' : '-';
      card.innerHTML = `
        <div class="block-head">
          <strong>Block ${i + 1}</strong>
          <span>inputChars: ${b.originalText.length}</span>
          <span>outputChars: ${b.correctedText.length}</span>
          <span class="badge ${statusClass(b.status)}">${b.status}</span>
          <span>HTTP: ${b.httpStatus ?? '-'}</span>
          <span>Zeit: ${elapsed}</span>
          ${['sendet…','antwortet…'].includes(b.status) ? '<div class="progress"><div></div></div>' : ''}
          <button data-retry="${b.id}" class="alt tight">Retry</button>
        </div>
        <div class="grid-2">
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

    document.querySelectorAll('[data-original]').forEach(t => {
      t.addEventListener('input', (e) => {
        const id = Number(e.target.dataset.original);
        const block = state.blocks.find(x => x.id === id);
        if (block) block.originalText = e.target.value;
      });
    });

    document.querySelectorAll('[data-corrected]').forEach(t => {
      t.addEventListener('input', (e) => {
        const id = Number(e.target.dataset.corrected);
        const block = state.blocks.find(x => x.id === id);
        if (block) block.correctedText = e.target.value;
        updateGlobalStats();
      });
    });

    document.querySelectorAll('[data-retry]').forEach(btn => {
      btn.addEventListener('click', () => enqueueRetry(Number(btn.dataset.retry)));
    });

    updateGlobalStats();
  }

  function escapeHtml(v) {
    return (v || '').replace(/[&<>"']/g, (ch) => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[ch]));
  }

  function splitIntoChunksSmart(text, maxLen = 5000, options = {}) {
    const {
      preferParagraphSplit = true,
      minChunkFraction = 0.6,
      keepDelimiter = true,
      splitOnWordBoundary = true
    } = options;

    const normalizedText = (text || '').replace(/\r\n|\r/g, '\n');
    const chunks = [];
    const clampedMaxLen = Math.max(1, Number(maxLen) || 5000);
    const minFraction = Math.min(0.95, Math.max(0.1, Number(minChunkFraction) || 0.6));

    const isLetter = (char) => /\p{L}/u.test(char || '');
    const leadingNewlineTrim = /^\n(?!\n)/;

    let rest = normalizedText;
    while (rest.length > clampedMaxLen) {
      const searchStart = Math.min(Math.floor(clampedMaxLen * minFraction), rest.length);
      const searchEnd = Math.min(clampedMaxLen, rest.length);
      let splitPoint = -1;

      if (preferParagraphSplit) {
        const paragraphWindow = rest.slice(searchStart, searchEnd);
        let match;
        const paragraphRegex = /\n{2,}/g;
        while ((match = paragraphRegex.exec(paragraphWindow)) !== null) {
          splitPoint = searchStart + match.index + (keepDelimiter ? match[0].length : 0);
        }
      }

      if (splitPoint < 0 && splitOnWordBoundary) {
        const chunkWindow = rest.slice(searchStart, searchEnd);
        const whitespaceRegex = /\s+/g;
        let match;
        while ((match = whitespaceRegex.exec(chunkWindow)) !== null) {
          splitPoint = searchStart + match.index + match[0].length;
        }
      }

      if (splitPoint < 0) {
        splitPoint = clampedMaxLen;
      }

      if (splitPoint <= 0) {
        splitPoint = Math.min(clampedMaxLen, rest.length);
      }

      if (splitPoint < rest.length && isLetter(rest[splitPoint - 1]) && isLetter(rest[splitPoint])) {
        let safePoint = splitPoint - 1;
        while (safePoint > 0 && !/\s/u.test(rest[safePoint])) {
          safePoint--;
        }
        if (safePoint > 0) {
          while (safePoint < rest.length && /\s/u.test(rest[safePoint])) {
            safePoint++;
          }
          splitPoint = safePoint;
        }
      }

      const chunk = rest.slice(0, splitPoint);
      let nextRest = rest.slice(splitPoint);
      nextRest = nextRest.replace(leadingNewlineTrim, '');

      if (!chunk.length || chunk.length === rest.length) {
        chunks.push(chunk || rest.slice(0, clampedMaxLen));
        rest = chunk.length ? nextRest : rest.slice(clampedMaxLen);
        continue;
      }

      chunks.push(chunk);
      rest = nextRest;
    }

    if (rest.length) {
      chunks.push(rest);
    }

    return chunks;
  }

  function splitText() {
    const text = el.fullText.value || '';
    const size = Math.max(500, Number(el.chunkSize.value) || 5000);
    const textChunks = splitIntoChunksSmart(text, size, {
      preferParagraphSplit: el.preferParagraphSplit.checked,
      splitOnWordBoundary: el.splitOnWordBoundary.checked,
      minChunkFraction: 0.6,
      keepDelimiter: true
    });

    const blocks = textChunks.map((chunk, i) => ({
      id: i + 1,
      originalText: chunk,
      correctedText: '',
      status: 'wartet',
      errorMessage: '',
      httpStatus: null,
      startTime: null
    }));

    state.blocks = blocks;
    state.queue = [];
    state.running.clear();
    state.stopRequested = false;
    renderBlocks();
  }

  function enqueueAll() {
    state.queue = state.blocks.map(b => ({ blockId: b.id, type: 'correct' }));
    state.blocks.forEach(b => { b.status = 'in Warteschlange'; b.errorMessage = ''; b.httpStatus = null; });
    renderBlocks();
    drainQueue();
  }

  function enqueueRetry(blockId) {
    const b = state.blocks.find(x => x.id === blockId);
    if (!b) return;
    b.status = 'in Warteschlange';
    b.errorMessage = '';
    b.httpStatus = null;
    state.queue.push({ blockId, type: 'correct' });
    renderBlocks();
    drainQueue();
  }

  async function runTask(task) {
    const block = state.blocks.find(b => b.id === task.blockId);
    if (!block) return;
    const controller = new AbortController();
    state.running.set(task.blockId, controller);

    block.status = 'sendet…';
    block.startTime = Date.now();
    block.errorMessage = '';
    block.httpStatus = null;
    renderBlocks();

    const result = await apiCall('correctBlock', {
      blockId: block.id,
      chunkText: block.originalText,
      modelInput: el.modelInput.value,
      __signal: controller.signal
    });

    if (result.aborted || state.stopRequested) {
      block.status = 'abgebrochen';
      block.errorMessage = 'Request wurde abgebrochen.';
      block.httpStatus = result.httpStatus ?? 0;
    } else if (!result.ok) {
      block.status = 'Fehler';
      block.errorMessage = result?.error?.message || 'Unbekannter Fehler';
      block.httpStatus = result.httpStatus ?? 0;
    } else {
      block.status = 'fertig';
      const correctedText = result.correctedText || '';
      block.correctedText = el.smoothLineBreaks.checked ? normalizeAndFluidifyText(correctedText) : correctedText;
      block.httpStatus = result.httpStatus ?? 200;
    }

    state.running.delete(task.blockId);
    renderBlocks();
    drainQueue();
  }

  function drainQueue() {
    if (state.stopRequested) return;
    while (state.running.size < state.maxConcurrent && state.queue.length > 0) {
      const next = state.queue.shift();
      const block = state.blocks.find(b => b.id === next.blockId);
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
      const b = state.blocks.find(x => x.id === id);
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
    el.exportText.value = state.blocks.map(b => b.correctedText || b.originalText).join('\n\n');
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
    const isHeadingLikePair = (current, next) => !/[.!?:;]$/u.test(current) && (isHeadingLike(current) || isHeadingLike(next));
    const shouldKeepLineBreak = (current, next) => (
      isListLine(current) ||
      isListLine(next) ||
      isMetaLine(current) ||
      isMetaLine(next) ||
      isHeadingLike(current) ||
      isHeadingLikePair(current, next)
    );

    const cleanupWhitespace = (line) => line
      .replace(/[ \t]+/gu, ' ')
      .replace(/\s+([,.;:!?])/gu, '$1')
      .replace(/([,.;:!?])(?!\s|$)/gu, '$1 ')
      .replace(/\s{2,}/gu, ' ')
      .trim();

    for (const paragraph of paragraphs) {
      const lines = paragraph.split('\n').map((line) => line.trim()).filter(Boolean);
      if (!lines.length) continue;

      let current = lines[0];
      const built = [];

      for (let i = 1; i < lines.length; i++) {
        const next = lines[i];
        if (shouldKeepLineBreak(current, next)) {
          built.push(current);
          current = next;
        } else {
          current = `${current.trimEnd()} ${next.trimStart()}`;
        }
      }

      built.push(current);
      outputParagraphs.push(built.map(cleanupWhitespace).join('\n'));
    }

    return outputParagraphs.join('\n\n').replace(/\n{3,}/g, '\n\n').trim();
  }

  // Testfall:
  // Input: "Zu ihren Spezialgebieten zählen die Themen Bindungsangst, Stärkung des\n\nSelbstwertgefühls ..."
  // Output: Zeilen innerhalb eines Absatzes werden zusammengeführt, die Leerzeile als Absatztrenner bleibt erhalten.

  // Chunking-Testfälle:
  // 1) "Links auf Webseiten Dritter ..." darf nie mitten im Wort getrennt werden.
  // 2) Bei vorhandenem "\n\n" soll in der Nähe des Limits bevorzugt am Absatzende getrennt werden.
  // 3) Ohne Absatztrenner wird an der letzten Wortgrenze vor dem Limit getrennt.
  // 4) Extremfall ohne Whitespace (z.B. 12000x "A") nutzt den Hard-Cut-Fallback.
  function runChunkingSelfTests() {
    const t1 = splitIntoChunksSmart('Links auf Webseiten Dritter sind wichtig', 6);
    console.assert(!/^inks/u.test(t1[1] || ''), 'Chunking Test 1 fehlgeschlagen');

    const t2 = splitIntoChunksSmart('Absatz A\n\nAbsatz B\n\nAbsatz C', 12, { preferParagraphSplit: true });
    console.assert(/\n\n$/.test(t2[0] || ''), 'Chunking Test 2 fehlgeschlagen');

    const t3 = splitIntoChunksSmart('Dies ist eine sehr lange Zeile ohne Absatztrenner', 20, { preferParagraphSplit: true });
    console.assert(/\s$/.test(t3[0] || '') || (t3[1] || '').startsWith(' '), 'Chunking Test 3 fehlgeschlagen');

    const t4 = splitIntoChunksSmart('A'.repeat(12000), 5000, { splitOnWordBoundary: true });
    console.assert(t4.length === 3 && t4[0].length === 5000 && t4[1].length === 5000, 'Chunking Test 4 fehlgeschlagen');
  }
  runChunkingSelfTests();


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
    el.exportText.value = normalizeAndFluidifyText(el.exportText.value);
  });
  document.getElementById('btnCopy').addEventListener('click', async () => {
    try { await navigator.clipboard.writeText(el.exportText.value); el.statusMessage.textContent = 'In Zwischenablage kopiert.'; }
    catch { el.statusMessage.textContent = 'Kopieren fehlgeschlagen.'; }
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

  renderBlocks();
})();
</script>
</body>
</html>
