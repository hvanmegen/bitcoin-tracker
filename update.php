<?php
// update.php

$PRECISION = 3;

$url  = 'https://api.coingecko.com/api/v3/simple/price?ids=bitcoin&vs_currencies=eur&precision=' . $PRECISION;
$file = __DIR__ . '/bitcoin.json';

$INTERVAL    = 60;
$MAX_ENTRIES = 60 * 24 * 7;

$now = time();

/* load existing data */
$data = @json_decode(@file_get_contents($file), true);
if (!is_array($data) || !isset($data['prices'])) {
    $data = [
        'currency' => 'eur',
        'meta'     => [],
        'prices'   => []
    ];
}

/* fetch price */
$json = @file_get_contents($url);
if ($json === false) exit;

$decoded = json_decode($json, true);
if (!isset($decoded['bitcoin']['eur'])) exit;

$value = round((float)$decoded['bitcoin']['eur'], $PRECISION);

/* prevent duplicate timestamps */
$last = end($data['prices']);
if ($last && isset($last['ts']) && $last['ts'] === $now) {
    exit;
}

$prices = &$data['prices'];
$count  = count($prices);

if ($count >= 1 && round($prices[$count - 1]['value'], $PRECISION) === $value) {
    // identical price fetched

    if ($count >= 2) {
        // interpolate between last-2 and current real value
        $prev   = (float)$prices[$count - 2]['value'];
        $interp = round(($prev + $value) / 2, $PRECISION);

        // replace last-1 with interpolated value
        $prices[$count - 1]['value'] = $interp;
    }

    // append real value as authoritative last point
    $prices[] = [
        'ts'    => $now,
        'value' => $value
    ];
} else {
    // normal append
    $prices[] = [
        'ts'    => $now,
        'value' => $value
    ];
}

/* trim history */
if (count($prices) > $MAX_ENTRIES) {
    $prices = array_slice($prices, -$MAX_ENTRIES);
}

/* meta */
$data['meta'] = [
    'updated_at' => $now,
    'interval'   => $INTERVAL,
    'precision'  => $PRECISION
];

/* write */
file_put_contents(
    $file,
    json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
    LOCK_EX
);
