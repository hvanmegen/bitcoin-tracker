# Bitcoin Tracker

Minimalist Bitcoin price monitor with a live sparkline, trend-based mood copy, and a CoinGecko-powered feed.

## What you get
- Live price display with smooth interpolation between updates
- Sparkline for the recent window (trend-colored)
- Anchor comparisons (1h / 24h / 48h / 72h) and a short “Bitcoin is …” summary
- Tone toggle with three modes:
  - **Pro**: neutral, professional wording
  - **Degen**: slangy/emoji phrasing (crypto in-jokes)
  - **Auto**: switches based on local browser time (Pro on weekdays 08:00–17:30, Degen otherwise)
- Light/dark-aware styling, plain vanilla JS

## Data flow
- `update.php` fetches EUR prices from CoinGecko and writes `bitcoin.json` (default interval: 60s; keeps ~1 week of minute data).
- `moods.json` contains both Pro and Degen phrase sets for the conclusion text.

## Running
1) Serve the directory with PHP (or another server that runs `index.php` to emit server time).
2) Keep `bitcoin.json` fresh with cron/systemd, e.g.:
   - `* * * * * /usr/bin/php /path/to/update.php >/dev/null 2>&1`

## Notes
- The page derives its base URL at runtime. You can override the asset host (e.g., add `cdn.`) near the top of `index.php` (`$ASSET_HOST`).
- Auto tone is client-clock based; a manual choice overrides it.
