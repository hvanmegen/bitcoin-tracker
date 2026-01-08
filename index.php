<?php
// index.php
// Inject authoritative server time so the browser can compute skew
$SERVER_NOW = time();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>Bitcoin Monitor</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=0.7">
    <link rel="canonical" href="https://qmp-media.nl/bc/">

    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#1f1f1f" media="(prefers-color-scheme: dark)">
    <meta name="theme-color" content="#f4f4f4" media="(prefers-color-scheme: light)">

    <meta name="robots" content="index, follow">
    <meta name="referrer" content="strict-origin-when-cross-origin">

    <meta property="og:url" content="https://qmp-media.nl/bc/">
    <meta property="og:site_name" content="Bitcoin Monitor">
    <meta property="og:title" content="Bitcoin Monitor">
    <meta property="og:description" content="A minimalist Bitcoin Monitor with a realtime feel, animated price, sparkline history and anchor comparisons. Powered with data from CoinGecko.">
    <meta property="og:type" content="website">
    <meta property="og:image" content="https://cdn.qmp-media.nl/bc/bitcoin.png">
    <meta property="og:image:width" content="512">
    <meta property="og:image:height" content="512">
    <meta property="og:locale" content="en_US">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Bitcoin Monitor">
    <meta name="twitter:description" content="Animated Bitcoin price monitor with minute-level updates and historical anchors. Data from CoinGecko.">
    <meta name="twitter:image" content="https://cdn.qmp-media.nl/bc/bitcoin.png">

    <link rel="stylesheet" href="style.css">

    <script>
        /* =========================
           SERVER / CLIENT TIME SYNC
        ========================= */

        const SERVER_NOW = <?php echo (int)$SERVER_NOW; ?>; // epoch seconds
        const CLIENT_NOW = Date.now() / 1000;               // epoch seconds
        const CLOCK_SKEW = CLIENT_NOW - SERVER_NOW;         // client = server + skew

        console.log(
            '[time] server:',
            new Date(SERVER_NOW * 1000).toLocaleTimeString(),
            'client:',
            new Date(CLIENT_NOW * 1000).toLocaleTimeString(),
            'skew:',
            CLOCK_SKEW.toFixed(3),
            'sec'
        );

        /* =========================
           CONFIG
        ========================= */
        const CONFIG = {
            feedFile: 'bitcoin.json',
            renderIntervalMs: 10,
            staleMultiplier: 2,
            sparkTargetWindowSec: 24 * 3600,
            jitterMinSec: 1,
            jitterMaxSec: 2
        };

        /* =========================
           FORMATTERS
        ========================= */
        const fmtMoney = new Intl.NumberFormat('nl-NL', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });

        const fmtPct = new Intl.NumberFormat('nl-NL', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });

        const euro = v => '€ ' + fmtMoney.format(v);
        const pct = v => fmtPct.format(v);
        const clamp = (v, a, b) => Math.max(a, Math.min(b, v));

        /* =========================
           STATE
        ========================= */
        const state = {
            samples: [],
            anchors: [],

            intervalSec: null,
            updatedAtServerSec: null,

            lastValue: null,
            targetValue: null,

            animStartServerSec: null,
            animDurationSec: null,

            trend: 'neutral',

            fetchTimer: null,
            signature: null,

			hasRenderedOnce: false
        };

        /* =========================
           DOM HELPERS
        ========================= */
        const elPrice = () => document.getElementById('price');
        const elSpark = () => document.getElementById('spark');
        const elSparkLabel = () => document.getElementById('sparkLabel');
        const elAnchors = () => document.getElementById('anchors');
        const elConclusion = () => document.getElementById('conclusion');

        /* =========================
           FETCH
        ========================= */
        function fetchFeed() {
            fetch(CONFIG.feedFile + '?t=' + Date.now(), { cache: 'no-store' })
                .then(r => r.ok ? r.json() : null)
                .then(j => j && ingestFeed(j))
                .catch(() => {});
        }

        /* =========================
           SCHEDULER (SERVER-TIME BASED)
        ========================= */
		function scheduleNextFetch() {
			if (!state.updatedAtServerSec || !state.intervalSec) return;

			// Hard safety rule (SERVER time):
			// never fetch before updated_at + interval + 2 seconds
			const earliestServerFetch =
				state.updatedAtServerSec +
				state.intervalSec +
				2;

			// Convert to CLIENT time using skew (client = server + skew)
			const earliestClientFetch =
				earliestServerFetch + CLOCK_SKEW;

			// Add jitter AFTER the safety window (client-side)
			const jitterSec =
				CONFIG.jitterMinSec +
				Math.random() * (CONFIG.jitterMaxSec - CONFIG.jitterMinSec);

			const targetClientFetch =
				earliestClientFetch + jitterSec;

			let delayMs =
				(targetClientFetch - Date.now() / 1000) * 1000;

			// Clamp
			if (delayMs < 500) delayMs = 500;

			// Single timer invariant
			clearTimeout(state.fetchTimer);
			state.fetchTimer = setTimeout(fetchFeed, delayMs);

			// Compute scheduled fire time in both clocks (for truthful logs)
			const nowClientSec = Date.now() / 1000;
			const fireAtClientSec = nowClientSec + (delayMs / 1000);
			const fireAtServerSec = fireAtClientSec - CLOCK_SKEW;

			const delaySec = (delayMs / 1000).toFixed(3);

			console.log(
				'[fetch] waiting',
				delaySec,
				'sec .. earliest allowed',
				new Date(earliestServerFetch * 1000).toLocaleTimeString(),
				'.. scheduled at',
				new Date(fireAtServerSec * 1000).toLocaleTimeString(),
				'(server time)'
			);
		}

        /* =========================
           INGEST FEED
        ========================= */
		function ingestFeed(raw) {
			if (!raw || !Array.isArray(raw.prices) || raw.prices.length < 2) return;

			// Normalize and sort samples
			const samples = raw.prices
				.map(p => ({
					ts: Number(p.ts),
					value: Number(p.value)
				}))
				.filter(p => Number.isFinite(p.ts) && Number.isFinite(p.value))
				.sort((a, b) => a.ts - b.ts);

			if (samples.length < 2) return;

			const latest = samples[samples.length - 1];
			const signature = latest.ts + ':' + latest.value;

			// Ignore duplicate data
			if (signature === state.signature) return;
			state.signature = signature;

			// Update core state
			state.samples = samples;
			state.intervalSec = Number(raw.meta && raw.meta.interval) || 60;
			state.updatedAtServerSec =
				Number(raw.meta && raw.meta.updated_at) || latest.ts;

			const prev = samples[samples.length - 2];
			const curr = latest;

			state.lastValue = prev.value;
			state.targetValue = curr.value;

			state.trend =
				curr.value > prev.value ? 'up' :
				curr.value < prev.value ? 'down' :
				'neutral';

			// Compute current server time using skew
			const nowServerSec = (Date.now() / 1000) - CLOCK_SKEW;

			// Predict next server-side update (cron runs exactly on the minute)
			const nextServerUpdate =
				state.updatedAtServerSec +
				state.intervalSec;

			// Define animation contract:
			// animate immediately from lastValue to targetValue,
			// over the time remaining until the NEXT expected update
			state.animStartServerSec = nowServerSec;
			state.animDurationSec = Math.max(
				1,
				nextServerUpdate - nowServerSec
			);

			// Log data timing
			console.log(
				'[data] new sample @',
				new Date(state.updatedAtServerSec * 1000).toLocaleTimeString(),
				'next expected @',
				new Date(nextServerUpdate * 1000).toLocaleTimeString()
			);

			// Log animation intent (THIS is the authoritative animation definition)
			console.log(
				'[anim] from',
				state.lastValue,
				'to',
				state.targetValue,
				'.. start @',
				new Date(state.animStartServerSec * 1000).toLocaleTimeString(),
				'.. duration',
				state.animDurationSec.toFixed(3),
				'sec'
			);

			// Rebuild derived UI (heavy work only here)
			buildAnchors();
			drawSparkline();

			const c = getConclusion();
			if (elConclusion()) elConclusion().textContent = c ?? '';

			// Predict and schedule next fetch (server-time based, skew-aware)
			scheduleNextFetch();
		}

        /* =========================
           RENDER LOOP
        ========================= */
		function render() {
			setTimeout(render, CONFIG.renderIntervalMs);

			if (!state.targetValue) return;

			const p = elPrice();
			if (!p) return;

			// first render: show lastValue exactly, no interpolation
			if (!state.hasRenderedOnce) {
				const arrow =
					state.trend === 'up' ? '▲ ' :
					state.trend === 'down' ? '▼ ' :
					'';

				const cls =
					state.trend === 'up' ? 'up' :
					state.trend === 'down' ? 'down' :
					'';

				p.className = 'price ' + cls;
				p.textContent = arrow + euro(state.lastValue);

				state.hasRenderedOnce = true;
				return;
			}

			// normal animated render
			const nowServerSec = (Date.now() / 1000) - CLOCK_SKEW;
			const t = clamp(
				(nowServerSec - state.animStartServerSec) / state.animDurationSec,
				0,
				1
			);

			const value =
				state.lastValue +
				(state.targetValue - state.lastValue) * t;

			const stale =
				nowServerSec - state.updatedAtServerSec >
				state.intervalSec * CONFIG.staleMultiplier;

			const arrow =
				state.trend === 'up' ? '▲ ' :
				state.trend === 'down' ? '▼ ' :
				'';

			const cls =
				state.trend === 'up' ? 'up' :
				state.trend === 'down' ? 'down' :
				'';

			p.className = 'price ' + cls + (stale ? ' stale' : '');
			p.textContent = arrow + euro(value);

			updateAnchorIndicators(value);
		}


        /* =========================
           ANCHORS
        ========================= */
        function findBefore(ts) {
            return [...state.samples].reverse().find(p => p.ts <= ts);
        }

        function fmtAgo(sec) {
            const h = Math.floor(sec / 3600);

            if (h >= 24 * 7) {
                const w = Math.floor(h / (24 * 7));
                const remH = h % (24 * 7);
                const d = Math.floor(remH / 24);
                const rh = remH % 24;
                if (d === 0 && rh === 0) return w + 'w';
                if (rh === 0) return w + 'w ' + d + 'd';
                return w + 'w ' + d + 'd ' + rh + 'h';
            }

            if (h >= 24) {
                const d = Math.floor(h / 24);
                const rh = h % 24;
                return rh === 0 ? d + 'd' : d + 'd ' + rh + 'h';
            }

            if (h >= 1) return h + 'h';
            return Math.floor(sec / 60) + ' min';
        }

        function buildAnchors() {
            const latestTs = state.samples.at(-1).ts;
            const rows = [];

            [1, 12, 24, 48, 72].forEach(h => {
                const p = findBefore(latestTs - h * 3600);
                if (p) rows.push({ label: fmtAgo(h * 3600) + ' ago', value: p.value });
            });

            const first = state.samples[0];
            rows.push({
                label: fmtAgo(latestTs - first.ts) + ' ago',
                value: first.value
            });

            state.anchors = rows;
            renderAnchorsTable();
        }

        function renderAnchorsTable() {
            const w = elAnchors();
            w.innerHTML = '';

            const t = document.createElement('table');
            t.className = 'anchors';

            state.anchors.forEach(a => {
                const tr = document.createElement('tr');
                tr.innerHTML =
                    '<td class="label">' + a.label + ':</td>' +
                    '<td class="value"><span class="price">' + euro(a.value) +
                    '</span><span class="indicator" data-ref="' + a.value + '"></span></td>';
                t.appendChild(tr);
            });

            w.appendChild(t);
        }

        function updateAnchorIndicators(current) {
            document.querySelectorAll('.indicator').forEach(el => {
                const ref = Number(el.dataset.ref);
                const diff = current - ref;
                const sign = diff >= 0 ? '+' : '-';
                const arrow = diff >= 0 ? '▲' : '▼';
                const cls = diff >= 0 ? 'up' : 'down';

                el.className = 'indicator ' + cls;
                el.textContent =
                    ' ' + arrow +
                    ' ' + sign + pct(Math.abs(diff / ref * 100)) +
                    '% (' + euro(Math.abs(diff)).replace('€ ', '€ ' + sign) + ')';
            });
        }

        /* =========================
           SPARKLINE
        ========================= */
        function drawSparkline() {
            const c = elSpark();
            const ctx = c.getContext('2d');

            const pts = Math.floor(CONFIG.sparkTargetWindowSec / state.intervalSec);
            const data = state.samples.slice(-pts);
            if (data.length < 2) return;

            const vals = data.map(p => p.value);
            let min = Math.min(...vals);
            let max = Math.max(...vals);
            const pad = (max - min) * 0.05 || 1;
            min -= pad;
            max += pad;

            ctx.clearRect(0, 0, c.width, c.height);
            ctx.beginPath();

            vals.forEach((v, i) => {
                const x = i / (vals.length - 1) * c.width;
                const y = c.height - (v - min) / (max - min) * c.height;
                i ? ctx.lineTo(x, y) : ctx.moveTo(x, y);
            });

            const style = getComputedStyle(document.documentElement);
            const firstVal = vals[0];
            const lastVal = vals[vals.length - 1];
            const windowTrend =
                lastVal > firstVal ? 'up' :
                lastVal < firstVal ? 'down' :
                'neutral';

            ctx.strokeStyle =
                windowTrend === 'up' ? style.getPropertyValue('--up') :
                windowTrend === 'down' ? style.getPropertyValue('--down') :
                style.getPropertyValue('--neutral');

            ctx.lineWidth = 3;
            ctx.stroke();

            const hours = Math.round((data.length * state.intervalSec) / 3600);
            elSparkLabel().textContent = 'last ' + hours + 'h';
        }

        /* =========================
           CONCLUSION
        ========================= */
		function pctChange(cur, ref) {
		    return (cur - ref) / ref * 100;
		}

		function stddev(arr) {
		    if (arr.length < 2) return 0;
		    const mean = arr.reduce((a, b) => a + b, 0) / arr.length;
		    const v = arr.reduce((a, b) => a + (b - mean) ** 2, 0) / (arr.length - 1);
		    return Math.sqrt(v);
		}

		// Volatility = stddev of 1-min % returns over last N minutes
		function minuteVolatility(samples, minutes = 60) {
		    if (!samples?.length) return 0;
		    const endTs = samples.at(-1).ts;
		    const startTs = endTs - minutes * 60;

		    const window = samples.filter(s => s.ts >= startTs);
		    if (window.length < 3) return 0;

		    const rets = [];
		    for (let i = 1; i < window.length; i++) {
		        const a = window[i - 1].value;
		        const b = window[i].value;
		        if (!a || !b) continue;
		        rets.push(((b - a) / a) * 100);
		    }
		    return stddev(rets);
		}

		// Robust slope over last N minutes (median of 1-min returns)
		function median(arr) {
		    if (!arr.length) return 0;
		    const a = [...arr].sort((x, y) => x - y);
		    const m = Math.floor(a.length / 2);
		    return a.length % 2 ? a[m] : (a[m - 1] + a[m]) / 2;
		}

		function medianMinuteReturn(samples, minutes = 30) {
		    const endTs = samples.at(-1).ts;
		    const startTs = endTs - minutes * 60;
		    const window = samples.filter(s => s.ts >= startTs);
		    if (window.length < 3) return 0;

		    const rets = [];
		    for (let i = 1; i < window.length; i++) {
		        const a = window[i - 1].value;
		        const b = window[i].value;
		        if (!a || !b) continue;
		        rets.push(((b - a) / a) * 100);
		    }
		    return median(rets);
		}

		// Convert a % move over a horizon into a normalized score using volatility
		function normalizedMove(pct, volPerMin, horizonMin) {
		    // expected move ~ vol * sqrt(time)
		    const denom = Math.max(0.0001, volPerMin * Math.sqrt(Math.max(1, horizonMin)));
		    return pct / denom; // "z-ish"
		}

		// Map continuous value to discrete -4..4
		function quantizeScore(x) {
		    // squish extremes; keep sensitivity around 0
		    const squished = Math.tanh(x / 1.6) * 4; // ~[-4..4]
		    return clamp(Math.round(squished), -4, 4);
		}

		function computeTrendScore(cur, samples, findBeforeFn) {
		    if (!cur || !samples?.length) return 0;

		    const vol = minuteVolatility(samples, 60);       // % per minute
		    const mom = medianMinuteReturn(samples, 30);     // % per minute (median)

		    const endTs = samples.at(-1).ts;

		    const horizons = [
		        { sec: 15 * 60,  w: 1.0, min: 15 },
		        { sec: 60 * 60,  w: 1.2, min: 60 },
		        { sec: 6 * 3600, w: 1.4, min: 360 },
		        { sec: 24*3600,  w: 1.8, min: 1440 }
		    ];

		    let acc = 0;
		    let wsum = 0;

		    for (const h of horizons) {
		        const ref = findBeforeFn(endTs - h.sec);
		        if (!ref) continue;

		        const p = pctChange(cur, ref.value);
		        const z = normalizedMove(p, vol || 0.02, h.min); // fallback vol if empty
		        acc += z * h.w;
		        wsum += h.w;
		    }

		    // Momentum nudge (prevents "flat" when it’s steadily creeping)
		    // Scale mom to a 30-min cumulative move:
		    const mom30 = mom * 30; // % over ~30 mins (median-based)
		    const momZ = normalizedMove(mom30, vol || 0.02, 30);
		    acc += momZ * 0.6;
		    wsum += 0.6;

		    if (!wsum) return 0;

		    return quantizeScore(acc / wsum);
		}

		function sinceStartTrend(cur, samples) {
		    const first = samples?.[0];
		    if (!first?.value) return 'flat';

		    const p = pctChange(cur, first.value);

		    // adaptive threshold: needs to beat noise a bit
		    const vol = minuteVolatility(samples, 120); // longer window
		    const minutes = Math.max(1, Math.round((samples.at(-1).ts - first.ts) / 60));
		    const noise = (vol || 0.02) * Math.sqrt(minutes);

		    if (Math.abs(p) < Math.max(0.25, noise * 0.8)) return 'flat';
		    return p > 0 ? 'up' : 'down';
		}

		function getConclusion() {
		    const cur = state.targetValue;
		    if (!cur || !state.samples.length) return null;

		    const score = computeTrendScore(cur, state.samples, findBefore);

		    const base = bitcoinMood(score);
		    const long = sinceStartTrend(cur, state.samples);

		    // Don’t use base.includes('up/down') (your strings won’t match)
		    if (score > 0 && long === 'down')
		        return 'Bitcoin is ' + base + ', but still down on the longer run';
		    if (score < 0 && long === 'up')
		        return 'Bitcoin is ' + base + ', but still up on the longer run';

		    return 'Bitcoin is ' + base;
		}

		let moodData = null;

		async function loadMood() {
		    const res = await fetch('moods.json');
		    moodData = await res.json();
		}

		function bitcoinMood(score) {
		    if (!moodData) return 'doing something';

		    const clamp = Math.max(-4, Math.min(4, score));
		    const list = moodData[String(clamp)];
		    return list[Math.floor(Math.random() * list.length)];
		}


        /* =========================
           BOOT
        ========================= */
        document.addEventListener('DOMContentLoaded', () => {
			loadMood();
            fetchFeed();
            render();
        });
    </script>
</head>

<body>
    <div class="container">
        <video loop autoplay muted poster="//cdn.qmp-media.nl/bc/bitcoin.png">
            <source src="//cdn.qmp-media.nl/bc/bitcoin.webm" type="video/webm">
            <source src="//cdn.qmp-media.nl/bc/bitcoin.mp4" type="video/mp4">
        </video>

        <div id="price" class="price">Loading…</div>

        <div class="sparks">
            <canvas id="spark" width="300" height="40"></canvas>
            <div id="sparkLabel" class="spark-label"></div>
        </div>

        <div id="anchors"></div>
        <div id="conclusion" class="conclusion"></div>

        <div class="source">
            <a href="https://coingecko.com/" rel="nofollow" target="_blank">
                powered with data from CoinGecko
            </a>
        </div>

        <div class="vanillajs">
            <a href="http://vanilla-js.com/" rel="nofollow" target="_blank">
                <img src="//cdn.qmp-media.nl/bc/vanilla_js.png" alt="Vanilla JS">
            </a>
        </div>
    </div>
</body>

</html>
