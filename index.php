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

$hasEnvKey = trim((string)getenv('OPENROUTER_API_KEY')) !== '';
?><!doctype html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Variante C • OCR Korrektur Tool (PHP + OpenRouter)</title>
  <style>
    body { margin: 0; font-family: Inter, system-ui, -apple-system, sans-serif; background: #f3f4f6; color: #111827; }
    .app { max-width: 1400px; margin: 0 auto; padding: 16px; }
    .panel { background: #fff; border: 1px solid #d1d5db; border-radius: 12px; padding: 14px; margin-bottom: 14px; }
    .row { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
    label { font-size: 14px; display: flex; gap: 6px; align-items: center; }
    input, textarea, button { font: inherit; }
    input[type="number"], input[type="text"], input[type="password"] { border: 1px solid #9ca3af; border-radius: 8px; padding: 7px 8px; }
    input[type="number"] { width: 120px; }
    input[type="text"] { min-width: 360px; }
    textarea { width: 100%; min-height: 220px; border: 1px solid #9ca3af; border-radius: 8px; padding: 10px; resize: vertical; box-sizing: border-box; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
    .small { font-size: 13px; color: #4b5563; }
    button { border: 1px solid #9ca3af; background: #e5e7eb; color: #111827; border-radius: 8px; padding: 8px 12px; cursor: pointer; }
    button:hover { background: #d1d5db; }
    button:disabled { opacity: .55; cursor: not-allowed; }
    .statusline { font-weight: 600; }
    .block { background: #fff; border: 1px solid #d1d5db; border-radius: 12px; margin-bottom: 12px; overflow: hidden; }
    .block-head { display: flex; justify-content: space-between; gap: 10px; padding: 8px 12px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; flex-wrap: wrap; }
    .badge { border-radius: 999px; border: 1px solid #cbd5e1; padding: 2px 8px; font-size: 12px; font-weight: 700; }
    .badge.waiting { background: #f3f4f6; }
    .badge.running { background: #dbeafe; color: #1d4ed8; border-color: #93c5fd; }
    .badge.done { background: #dcfce7; color: #166534; border-color: #86efac; }
    .badge.error { background: #fee2e2; color: #991b1b; border-color: #fca5a5; }
    .badge.aborted { background: #fef3c7; color: #92400e; border-color: #fcd34d; }
    .loader { height: 4px; background: #e5e7eb; }
    .loader span { display: block; height: 100%; width: 0; }
    .loader.running span { width: 35%; background: #2563eb; animation: slide 1.1s linear infinite; }
    .loader.done span { width: 100%; background: #16a34a; }
    .loader.error span { width: 100%; background: #dc2626; }
    .loader.aborted span { width: 100%; background: #d97706; }
    .block-body { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; padding: 10px; }
    .field { display: flex; flex-direction: column; gap: 6px; }
    .field > strong { font-size: 13px; }
    .error-text { font-size: 12px; color: #991b1b; }
    @keyframes slide { from { margin-left: -40%; } to { margin-left: 100%; } }
    @media (max-width: 980px) { .block-body { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
  <div class="app">
    <div class="panel">
      <h1>Variante C – PHP + OpenRouter + Web-UI</h1>
      <p class="small">Session-Cookie ist HttpOnly und bei HTTPS zusätzlich Secure. Für echten Schutz bitte HTTPS nutzen.</p>

      <label for="sourceInput"><strong>Gesamter Text (Input)</strong></label>
      <textarea id="sourceInput" placeholder="Sehr langen Text hier einfügen (bis ca. 200.000 Zeichen)..."></textarea>

      <div class="row" style="margin-top: 10px;">
        <label>Chunk-Größe <input id="chunkSize" type="number" min="500" step="500" value="10000"></label>
        <label>Model-Feld <input id="modelInput" type="text" value="https://openrouter.ai/arcee-ai/trinity-large-preview:free"></label>
        <label id="apiKeyWrap" <?= $hasEnvKey ? 'style="display:none"' : '' ?>>OpenRouter API Key <input id="apiKeyInput" type="password" autocomplete="off" placeholder="or-..."></label>
        <button id="saveKeyBtn" <?= $hasEnvKey ? 'disabled' : '' ?>>Key speichern</button>
        <button id="clearKeyBtn">Key löschen</button>
      </div>

      <div class="row" style="margin-top: 10px;">
        <button id="splitBtn">Aufteilen</button>
        <button id="splitAndCorrectBtn">Aufteilen &amp; Korrigieren</button>
        <button id="stopAllBtn">Stop All</button>
        <button id="mergeBtn">Alles zusammenfügen (korrigiert)</button>
        <button id="fluidBtn">Flüssigen Text exportieren (korrigiert)</button>
        <button id="copyBtn">In Zwischenablage kopieren</button>
        <button id="resetBtn">Reset</button>
      </div>

      <p id="globalMsg" class="small"></p>
      <p id="globalProgress" class="statusline">0 / 0 Blöcke fertig • Fehler: 0 • läuft: 0</p>
    </div>

    <div id="blocksContainer"></div>

    <div class="panel">
      <h2>Export (flüssiger korrigierter Text)</h2>
      <textarea id="exportOutput" placeholder="Hier erscheint der flüssige Export..."></textarea>
      <p id="exportStats" class="small">Zeichenanzahl: 0</p>
    </div>
  </div>

  <script>
    const csrfToken = <?= json_encode($_SESSION['csrf_token']) ?>;
    const hasEnvKey = <?= $hasEnvKey ? 'true' : 'false' ?>;

    const STATUS = {
      WAITING: 'wartet',
      QUEUED: 'in Warteschlange',
      SENDING: 'sendet…',
      RESPONDING: 'antwortet…',
      DONE: 'fertig',
      ABORTED: 'abgebrochen',
      ERROR: 'Fehler',
    };

    const state = {
      blocks: [],
      queue: [],
      queueSet: new Set(),
      active: 0,
      maxConcurrency: 2,
      controllers: new Map(),
      stopRequested: false,
    };

    const els = {
      sourceInput: document.getElementById('sourceInput'),
      chunkSize: document.getElementById('chunkSize'),
      modelInput: document.getElementById('modelInput'),
      apiKeyInput: document.getElementById('apiKeyInput'),
      globalMsg: document.getElementById('globalMsg'),
      globalProgress: document.getElementById('globalProgress'),
      blocksContainer: document.getElementById('blocksContainer'),
      exportOutput: document.getElementById('exportOutput'),
      exportStats: document.getElementById('exportStats'),
    };

    function showMsg(msg, isError = false) {
      els.globalMsg.textContent = msg;
      els.globalMsg.style.color = isError ? '#991b1b' : '#065f46';
    }

    function splitIntoChunks(text, chunkSize) {
      const chunks = [];
      for (let i = 0; i < text.length; i += chunkSize) {
        chunks.push(text.slice(i, i + chunkSize));
      }
      return chunks;
    }

    function debounce(fn, wait = 300) {
      let t;
      return (...args) => {
        clearTimeout(t);
        t = setTimeout(() => fn(...args), wait);
      };
    }

    function makeBlock(text, idx) {
      return {
        id: idx,
        originalText: text,
        correctedText: '',
        status: STATUS.WAITING,
        error: '',
        startedAt: 0,
        elapsedMs: 0,
        debouncedRequeue: null,
        refs: null,
      };
    }

    function setBlockStatus(block, next, error = '') {
      block.status = next;
      block.error = error;
      renderBlock(block.id);
      updateGlobalStats();
    }

    function queueBlock(id) {
      if (state.queueSet.has(id)) return;
      const block = state.blocks[id];
      if (!block) return;
      state.queue.push(id);
      state.queueSet.add(id);
      if (![STATUS.SENDING, STATUS.RESPONDING].includes(block.status)) {
        setBlockStatus(block, STATUS.QUEUED);
      }
      processQueue();
    }

    function extractErrorMessage(errorObj) {
      const status = Number(errorObj?.httpStatus || 0);
      const msg = errorObj?.errorObj?.message || errorObj?.message || 'Unbekannter Fehler';
      if (status === 401 || status === 403) return 'API-Key ungültig oder fehlt.';
      if (status === 429) return 'Rate Limit erreicht. Bitte Retry nutzen.';
      if (status >= 500) return 'OpenRouter-Fehler (5xx), später erneut versuchen.';
      return msg;
    }

    async function postApi(action, payload, signal) {
      const res = await fetch(`api.php?action=${encodeURIComponent(action)}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrfToken, ...payload }),
        signal,
      });
      const data = await res.json().catch(() => ({ ok: false, error: { message: 'Ungültige JSON-Antwort.' } }));
      if (!res.ok || !data.ok) {
        const err = new Error(data?.error?.message || `HTTP ${res.status}`);
        err.httpStatus = data.httpStatus || res.status;
        err.errorObj = data.error || { message: err.message };
        throw err;
      }
      return data;
    }

    async function correctSingleBlock(id) {
      const block = state.blocks[id];
      if (!block) return;
      const controller = new AbortController();
      state.controllers.set(id, controller);
      block.startedAt = Date.now();
      setBlockStatus(block, STATUS.SENDING);

      try {
        setTimeout(() => {
          if (state.controllers.get(id) === controller && state.blocks[id]?.status === STATUS.SENDING) {
            setBlockStatus(block, STATUS.RESPONDING);
          }
        }, 250);

        const data = await postApi('correctBlock', {
          blockId: id,
          chunkText: block.originalText,
          modelInput: els.modelInput.value,
        }, controller.signal);

        block.correctedText = data.correctedText || '';
        block.elapsedMs = Number(data?.meta?.elapsedMs || (Date.now() - block.startedAt));
        setBlockStatus(block, STATUS.DONE);
      } catch (error) {
        if (controller.signal.aborted || state.stopRequested) {
          block.elapsedMs = Date.now() - block.startedAt;
          setBlockStatus(block, STATUS.ABORTED);
        } else {
          block.elapsedMs = Date.now() - block.startedAt;
          setBlockStatus(block, STATUS.ERROR, extractErrorMessage(error));
        }
      } finally {
        state.controllers.delete(id);
      }
    }

    function processQueue() {
      while (state.active < state.maxConcurrency && state.queue.length > 0) {
        const id = state.queue.shift();
        state.queueSet.delete(id);
        const block = state.blocks[id];
        if (!block || state.stopRequested) continue;

        state.active += 1;
        correctSingleBlock(id)
          .finally(() => {
            state.active -= 1;
            renderBlock(id);
            updateGlobalStats();
            processQueue();
          });
      }
      updateGlobalStats();
    }

    function stopAll() {
      state.stopRequested = true;
      state.queue.length = 0;
      state.queueSet.clear();

      for (const [id, controller] of state.controllers.entries()) {
        controller.abort();
        const block = state.blocks[id];
        if (block) setBlockStatus(block, STATUS.ABORTED);
      }

      state.blocks.forEach((b) => {
        if (b.status === STATUS.QUEUED) setBlockStatus(b, STATUS.ABORTED);
      });

      setTimeout(() => { state.stopRequested = false; }, 50);
      updateGlobalStats();
    }

    function createBlockDom(block) {
      const wrap = document.createElement('div');
      wrap.className = 'block';

      const header = document.createElement('div');
      header.className = 'block-head';

      const leftMeta = document.createElement('div');
      const rightMeta = document.createElement('div');

      const badge = document.createElement('span');
      badge.className = 'badge waiting';

      const retryBtn = document.createElement('button');
      retryBtn.textContent = 'Retry';
      retryBtn.addEventListener('click', () => queueBlock(block.id));

      header.append(leftMeta, rightMeta);
      rightMeta.append(badge, retryBtn);

      const loader = document.createElement('div');
      loader.className = 'loader';
      loader.innerHTML = '<span></span>';

      const errorText = document.createElement('div');
      errorText.className = 'error-text';

      const body = document.createElement('div');
      body.className = 'block-body';

      const left = document.createElement('div');
      left.className = 'field';
      const leftTitle = document.createElement('strong');
      leftTitle.textContent = 'Original';
      const leftArea = document.createElement('textarea');
      leftArea.value = block.originalText;
      left.append(leftTitle, leftArea);

      const right = document.createElement('div');
      right.className = 'field';
      const rightTitle = document.createElement('strong');
      rightTitle.textContent = 'Korrektur';
      const rightArea = document.createElement('textarea');
      right.append(rightTitle, rightArea);

      body.append(left, right);
      wrap.append(header, loader, errorText, body);

      block.debouncedRequeue = debounce(() => {
        queueBlock(block.id);
      }, 300);

      leftArea.addEventListener('input', (e) => {
        block.originalText = e.target.value;
        setBlockStatus(block, STATUS.WAITING);
        block.debouncedRequeue();
        renderBlock(block.id);
      });

      rightArea.addEventListener('input', (e) => {
        block.correctedText = e.target.value;
        renderBlock(block.id);
      });

      block.refs = { wrap, leftMeta, rightMeta, badge, retryBtn, loader, errorText, leftArea, rightArea };
      renderBlock(block.id);
      return wrap;
    }

    function statusBadgeClass(status) {
      if ([STATUS.QUEUED, STATUS.SENDING, STATUS.RESPONDING].includes(status)) return 'running';
      if (status === STATUS.DONE) return 'done';
      if (status === STATUS.ERROR) return 'error';
      if (status === STATUS.ABORTED) return 'aborted';
      return 'waiting';
    }

    function loaderClass(status) {
      if ([STATUS.QUEUED, STATUS.SENDING, STATUS.RESPONDING].includes(status)) return 'running';
      if (status === STATUS.DONE) return 'done';
      if (status === STATUS.ERROR) return 'error';
      if (status === STATUS.ABORTED) return 'aborted';
      return '';
    }

    function renderBlock(id) {
      const block = state.blocks[id];
      if (!block || !block.refs) return;
      const inChars = block.originalText.length;
      const outChars = block.correctedText.length;
      const sec = block.elapsedMs > 0 ? `${(block.elapsedMs / 1000).toFixed(1)}s` : '—';

      block.refs.leftMeta.textContent = `Block #${id + 1} • Input: ${inChars} • Output: ${outChars}`;
      block.refs.badge.textContent = `${block.status} • ${sec}`;
      block.refs.badge.className = `badge ${statusBadgeClass(block.status)}`;
      block.refs.loader.className = `loader ${loaderClass(block.status)}`;
      block.refs.errorText.textContent = block.error || '';
      block.refs.rightArea.value = block.correctedText;
      block.refs.retryBtn.style.display = block.status === STATUS.ERROR ? 'inline-block' : 'none';
    }

    function updateGlobalStats() {
      const done = state.blocks.filter((b) => b.status === STATUS.DONE).length;
      const errors = state.blocks.filter((b) => b.status === STATUS.ERROR).length;
      const running = state.blocks.filter((b) => [STATUS.QUEUED, STATUS.SENDING, STATUS.RESPONDING].includes(b.status)).length;
      els.globalProgress.textContent = `${done} / ${state.blocks.length} Blöcke fertig • Fehler: ${errors} • läuft: ${running}`;
    }

    function buildBlocks(correctImmediately = false) {
      stopAll();
      state.blocks = [];
      els.blocksContainer.innerHTML = '';

      const source = els.sourceInput.value;
      const chunkSize = Math.max(500, Number(els.chunkSize.value) || 10000);
      const chunks = splitIntoChunks(source, chunkSize);
      const frag = document.createDocumentFragment();

      chunks.forEach((chunk, idx) => {
        const block = makeBlock(chunk, idx);
        state.blocks.push(block);
        frag.append(createBlockDom(block));
      });

      els.blocksContainer.append(frag);
      showMsg(`Aufgeteilt in ${state.blocks.length} Blöcke.`);
      updateGlobalStats();

      if (correctImmediately) {
        state.blocks.forEach((block) => queueBlock(block.id));
      }
    }

    function mergedCorrectedText() {
      return state.blocks.map((b) => b.correctedText || '').join('');
    }

    // Export-Heuristik: Layout-Zeilenumbrüche entfernen, Absätze erhalten.
    function toFluidText(input) {
      let text = input.replace(/\r\n?/g, '\n').replace(/\n{3,}/g, '\n\n');
      const paragraphs = text.split(/\n\n+/);
      const outParagraphs = [];

      const bulletRe = /^\s*(?:[-•*]|\d+[.)])\s+/;
      const allCapsRe = /^[^a-zäöüß]*[A-ZÄÖÜ][A-ZÄÖÜ0-9\s,.;:!?()/-]+$/;
      const specialLineRe = /(ISBN|https?:\/\/|www\.|©)/i;

      for (const para of paragraphs) {
        const lines = para.split('\n').map((l) => l.trim()).filter(Boolean);
        if (lines.length === 0) continue;

        let buffer = lines[0];
        for (let i = 1; i < lines.length; i += 1) {
          const prev = buffer;
          const next = lines[i];

          const prevWords = prev.split(/\s+/).filter(Boolean).length;
          const nextStartsLower = /^[a-zäöüß]/u.test(next);
          const prevLooksHeading = allCapsRe.test(prev) || prevWords <= 3 || bulletRe.test(prev);
          const nextIsBullet = bulletRe.test(next);
          const preserveByColon = /:$/.test(prev) && !nextStartsLower;
          const preserveSpecial = specialLineRe.test(prev) || specialLineRe.test(next);

          if (/-$/.test(prev) && /^\p{L}/u.test(next)) {
            buffer = prev.slice(0, -1) + next;
            continue;
          }

          if (prevLooksHeading || nextIsBullet || preserveByColon || preserveSpecial) {
            outParagraphs.push(buffer);
            buffer = next;
            continue;
          }

          buffer = `${prev} ${next}`;
        }

        outParagraphs.push(buffer);
      }

      return outParagraphs.join('\n\n').replace(/\n{3,}/g, '\n\n').trim();
    }

    document.getElementById('saveKeyBtn').addEventListener('click', async () => {
      if (hasEnvKey) {
        showMsg('OPENROUTER_API_KEY ist serverseitig gesetzt.');
        return;
      }

      const apiKey = els.apiKeyInput.value.trim();
      if (!apiKey) {
        showMsg('Bitte API-Key eingeben.', true);
        return;
      }

      try {
        await postApi('setApiKey', { apiKey });
        els.apiKeyInput.value = '';
        showMsg('API-Key wurde in der Session gespeichert.');
      } catch (error) {
        showMsg(error.message, true);
      }
    });

    document.getElementById('clearKeyBtn').addEventListener('click', async () => {
      try {
        await postApi('clearApiKey', {});
        showMsg('Session-Key gelöscht.');
      } catch (error) {
        showMsg(error.message, true);
      }
    });

    document.getElementById('splitBtn').addEventListener('click', () => buildBlocks(false));
    document.getElementById('splitAndCorrectBtn').addEventListener('click', () => buildBlocks(true));
    document.getElementById('stopAllBtn').addEventListener('click', () => {
      stopAll();
      showMsg('Alle laufenden Requests wurden abgebrochen.');
    });

    document.getElementById('mergeBtn').addEventListener('click', () => {
      const merged = mergedCorrectedText();
      els.exportOutput.value = merged;
      els.exportStats.textContent = `Zeichenanzahl: ${merged.length}`;
      showMsg('Korrigierte Blöcke wurden zusammengefügt.');
    });

    document.getElementById('fluidBtn').addEventListener('click', () => {
      const fluid = toFluidText(mergedCorrectedText());
      els.exportOutput.value = fluid;
      els.exportStats.textContent = `Zeichenanzahl: ${fluid.length}`;
      showMsg('Flüssiger Export erzeugt.');
    });

    document.getElementById('copyBtn').addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(els.exportOutput.value);
        showMsg('Export in Zwischenablage kopiert.');
      } catch (error) {
        showMsg('Kopieren fehlgeschlagen: ' + error.message, true);
      }
    });

    document.getElementById('resetBtn').addEventListener('click', () => {
      stopAll();
      state.blocks = [];
      els.sourceInput.value = '';
      els.blocksContainer.innerHTML = '';
      els.exportOutput.value = '';
      els.exportStats.textContent = 'Zeichenanzahl: 0';
      updateGlobalStats();
      showMsg('Zurückgesetzt.');
    });
  </script>
</body>
</html>
