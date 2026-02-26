<?php
declare(strict_types=1);

function ocr_correct_text(string $text): string
{
    $out = $text;

    // 1) Unicode NFKC, falls Intl verfügbar.
    if (class_exists('Normalizer')) {
        $normalized = Normalizer::normalize($out, Normalizer::FORM_KC);
        if ($normalized !== false) {
            $out = $normalized;
        }
    }

    // 2) Ligaturen explizit ersetzen (ß bleibt unverändert).
    $out = str_replace(
        ['ﬀ', 'ﬁ', 'ﬂ', 'ﬃ', 'ﬄ'],
        ['ff', 'fi', 'fl', 'ffi', 'ffl'],
        $out
    );

    // 3) Zeilenumbrüche normalisieren + Layout-Umbrüche innerhalb von Absätzen glätten.
    $out = preg_replace("/\r\n?|\n/u", "\n", $out) ?? $out;
    $out = preg_replace('/[ \t]+\n/u', "\n", $out) ?? $out;
    $out = preg_replace('/\n[ \t]+/u', "\n", $out) ?? $out;
    $out = preg_replace('/\n{3,}/u', "\n\n", $out) ?? $out; // max. eine Leerzeile
    $out = preg_replace('/([^\n])\n([^\n])/u', '$1 $2', $out) ?? $out; // Einzelumbruch im Absatz -> Space

    // 4) Spaces mitten im Wort entfernen (zwischen Unicode-Buchstaben).
    $out = preg_replace('/(\p{L})[ \t]+(\p{L})/u', '$1$2', $out) ?? $out;

    // 5) Allgemeine Whitespace-/Satzzeichenregeln.
    $out = preg_replace('/[ \t]{2,}/u', ' ', $out) ?? $out;
    $out = preg_replace('/[ \t]+([,.;:!?])/u', '$1', $out) ?? $out;
    $out = preg_replace('/([,.;:!?])([^\s\n])/u', '$1 $2', $out) ?? $out;

    return trim($out);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'correct') {
    header('Content-Type: application/json; charset=utf-8');
    $text = (string)($_POST['text'] ?? '');
    echo json_encode(['corrected' => ocr_correct_text($text)], JSON_UNESCAPED_UNICODE);
    exit;
}
?><!doctype html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>OCR Textkorrektur (PHP)</title>
  <style>
    :root { color-scheme: light dark; }
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; margin: 0; background: #f3f4f6; color: #111827; }
    .container { max-width: 1200px; margin: 0 auto; padding: 16px; }
    .panel { background: #fff; border: 1px solid #d1d5db; border-radius: 10px; padding: 12px; margin-bottom: 14px; }
    .toolbar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
    .toolbar input[type="number"] { width: 120px; padding: 6px; }
    button { border: 1px solid #9ca3af; background: #e5e7eb; color: #111827; padding: 8px 12px; border-radius: 8px; cursor: pointer; }
    button:hover { background: #d1d5db; }
    textarea { width: 100%; box-sizing: border-box; min-height: 170px; padding: 8px; border: 1px solid #9ca3af; border-radius: 8px; resize: vertical; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; line-height: 1.4; }
    .block { border: 1px solid #d1d5db; border-radius: 10px; margin-bottom: 14px; overflow: hidden; background: #fff; }
    .block-header { display: flex; justify-content: space-between; gap: 10px; padding: 8px 10px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-size: 13px; }
    .block-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; padding: 10px; }
    .field { display: flex; flex-direction: column; gap: 6px; }
    .field label { font-weight: 600; font-size: 13px; }
    .muted { color: #4b5563; font-size: 12px; }
    @media (max-width: 900px) { .block-grid { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
  <div class="container">
    <h1>OCR/PDF-Textkorrektur (PHP, lokal)</h1>

    <div class="panel">
      <div class="toolbar">
        <label>Chunk-Größe:
          <input id="chunkSize" type="number" min="1000" step="500" value="10000" />
        </label>
        <button id="splitBtn">Aufteilen &amp; Korrigieren</button>
        <button id="mergeBtn">Alles zusammenfügen (korrigiert)</button>
        <button id="copyBtn">In Zwischenablage kopieren</button>
        <button id="resetBtn">Reset</button>
      </div>
      <p class="muted">Kern-Korrekturfunktion liegt in PHP; die UI ruft sie pro Block auf (debounced).</p>
      <textarea id="sourceInput" placeholder="Hier gesamten Rohtext einfügen..."></textarea>
    </div>

    <div id="blocks"></div>

    <div class="panel">
      <h2>Zusammengefügter korrigierter Text</h2>
      <textarea id="mergedOutput" placeholder="Hier erscheint der zusammengefügte korrigierte Text..."></textarea>
    </div>
  </div>

  <script>
    const state = { blocks: [] };

    const sourceInput = document.getElementById('sourceInput');
    const chunkSizeInput = document.getElementById('chunkSize');
    const blocksEl = document.getElementById('blocks');
    const mergedOutput = document.getElementById('mergedOutput');

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

    async function correctWithPhp(text) {
      const fd = new FormData();
      fd.append('action', 'correct');
      fd.append('text', text);
      const resp = await fetch(window.location.href, { method: 'POST', body: fd });
      if (!resp.ok) throw new Error('PHP-Korrektur fehlgeschlagen');
      const data = await resp.json();
      return data.corrected || '';
    }

    function renderBlocks() {
      const fragment = document.createDocumentFragment();

      state.blocks.forEach((block, idx) => {
        const wrapper = document.createElement('div');
        wrapper.className = 'block';

        const header = document.createElement('div');
        header.className = 'block-header';
        header.innerHTML = `<strong>Block ${idx + 1}</strong><span>Original: ${block.original.length} | Korrigiert: ${block.corrected.length} Zeichen</span>`;

        const grid = document.createElement('div');
        grid.className = 'block-grid';

        const left = document.createElement('div');
        left.className = 'field';
        const leftLabel = document.createElement('label');
        leftLabel.textContent = 'Original (editierbar)';
        const leftTa = document.createElement('textarea');
        leftTa.value = block.original;

        const right = document.createElement('div');
        right.className = 'field';
        const rightLabel = document.createElement('label');
        rightLabel.textContent = 'Korrigiert (editierbar)';
        const rightTa = document.createElement('textarea');
        rightTa.value = block.corrected;

        const recompute = debounce(async () => {
          block.original = leftTa.value;
          try {
            block.corrected = await correctWithPhp(block.original);
            rightTa.value = block.corrected;
          } catch {
            // Fallback: bei Fehler bleibt bestehender Text erhalten.
          }
          header.innerHTML = `<strong>Block ${idx + 1}</strong><span>Original: ${block.original.length} | Korrigiert: ${block.corrected.length} Zeichen</span>`;
        }, 300);

        leftTa.addEventListener('input', recompute);
        rightTa.addEventListener('input', () => {
          block.corrected = rightTa.value;
          header.innerHTML = `<strong>Block ${idx + 1}</strong><span>Original: ${block.original.length} | Korrigiert: ${block.corrected.length} Zeichen</span>`;
        });

        left.append(leftLabel, leftTa);
        right.append(rightLabel, rightTa);
        grid.append(left, right);
        wrapper.append(header, grid);
        fragment.append(wrapper);
      });

      blocksEl.replaceChildren(fragment);
    }

    document.getElementById('splitBtn').addEventListener('click', async () => {
      const size = Math.max(1000, Number(chunkSizeInput.value) || 10000);
      const chunks = splitIntoChunks(sourceInput.value, size);

      // Sequenziell, um Server nicht mit vielen parallelen Requests zu überfluten.
      state.blocks = [];
      for (const c of chunks) {
        const corrected = await correctWithPhp(c).catch(() => c);
        state.blocks.push({ original: c, corrected });
      }

      renderBlocks();
      mergedOutput.value = '';
    });

    document.getElementById('mergeBtn').addEventListener('click', () => {
      mergedOutput.value = state.blocks.map((b) => b.corrected).join('');
    });

    document.getElementById('copyBtn').addEventListener('click', async () => {
      const text = mergedOutput.value;
      if (!text) return;
      try {
        await navigator.clipboard.writeText(text);
        alert('Korrigierter Text wurde in die Zwischenablage kopiert.');
      } catch {
        alert('Kopieren fehlgeschlagen. Bitte manuell kopieren.');
      }
    });

    document.getElementById('resetBtn').addEventListener('click', () => {
      sourceInput.value = '';
      mergedOutput.value = '';
      state.blocks = [];
      blocksEl.replaceChildren();
    });
  </script>
</body>
</html>
