# OCR Korrektur Tool – Variante C (PHP + OpenRouter + Vanilla JS)

Dieses Projekt enthält **Variante C** mit:
- `index.php` (UI + Queue + Chunking + Scroll-Sync)
- `api.php` (OpenRouter Proxy + Pre-/Post-Cleanup + Metadaten)

## Start

```bash
php -S localhost:8000
```

Dann im Browser öffnen:

- <http://localhost:8000/index.php>

## Features

- Modell-Auswahl per Dropdown + frei editierbares Model-Input-Feld.
- Model-Input wird via `localStorage` gespeichert (API-Key wird **nicht** gespeichert).
- Smart Chunking (Default: 2000) mit Absatz-/Wortgrenzen.
- Globaler Live-Status oben rechts (`fertig/Fehler/läuft`).
- Scroll-Sync pro Block (ratio-basiert, Ping-Pong-Schutz).
- Zwei-stufige OCR-Korrektur:
  - lokales Pre-Cleanup (JS + PHP)
  - LLM-Korrektur via OpenRouter
  - Post-Cleanup inkl. Fluidify/Whitespace/URL/Quote-Regeln.
- Pro Block Metadaten: `model`, `inputChars`, `outputChars`, `elapsedMs`.

## API-Key

- Entweder über ENV setzen: `OPENROUTER_API_KEY`
- Oder im UI eingeben und in der Session halten.
