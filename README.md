# Bitcoin Tracker

Minimalist Bitcoin price monitor with a live sparkline, trend-based mood copy, and a CoinGecko-powered feed.

## Features
- Live price display with smooth interpolation between updates
- Sparkline showing the recent window, colored by trend
- Anchor comparisons (1h/24h/48h/72h) and a lightweight conclusion sentence
- Tone toggle (Pro / Auto / Degen) with auto mode following weekday office hours
- Light/dark-aware styling and lightweight vanilla JS

## Data
- `update.php` fetches EUR prices from CoinGecko and writes `bitcoin.json` (default interval: 60s; keeps ~1 week of minute data).
- `moods.json` holds both Pro and Degen phrase sets for the conclusion text.

## Running
1) Serve the directory with PHP (or any static server for `index.php` with PHP available to emit server time).
2) Schedule `update.php` via cron/systemd to keep `bitcoin.json` fresh, e.g.:
   - `* * * * * /usr/bin/php /path/to/update.php >/dev/null 2>&1`

## Notes
- Assets reference `//cdn.qmp-media.nl/bc/` for the video/poster; adjust if you host locally.
- Auto tone uses local browser time: Pro during weekdays 08:00–17:30, otherwise Degen (or manual override).
