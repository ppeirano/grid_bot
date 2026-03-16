<?php
// optimize_proxy.php — Análisis de volatilidad y optimización del grid
header('Content-Type: application/json');

$input  = json_decode(file_get_contents('php://input'), true);
$symbol = strtoupper($input['symbol'] ?? 'XRPUSDT');
$days   = (int)($input['days'] ?? 60);
$days   = max(7, min(90, $days));

// ============================================================
// DATOS HISTÓRICOS DESDE BINANCE
// ============================================================
function get_klines($symbol, $days) {
    $limit    = min($days * 24, 1000);
    $end_ms   = round(microtime(true) * 1000);
    $start_ms = $end_ms - $days * 24 * 3600 * 1000;
    $url = "https://api.binance.com/api/v3/klines?" . http_build_query([
        'symbol'    => $symbol,
        'interval'  => '1h',
        'startTime' => $start_ms,
        'endTime'   => $end_ms,
        'limit'     => $limit
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) throw new Exception("Error Binance: $err");
    $data = json_decode($resp, true);
    if (!is_array($data)) throw new Exception("Respuesta inválida de Binance");
    return $data;
}

// ============================================================
// MÉTRICAS
// ============================================================
function calc_atr($highs, $lows, $closes, $period = 14) {
    $trs = [];
    for ($i = 1; $i < count($closes); $i++) {
        $trs[] = max(
            $highs[$i] - $lows[$i],
            abs($highs[$i] - $closes[$i-1]),
            abs($lows[$i]  - $closes[$i-1])
        );
    }
    return array_sum(array_slice($trs, -$period)) / $period;
}

function calc_daily_atr($highs, $lows, $closes) {
    $dh = []; $dl = []; $dc = [];
    for ($i = 0; $i + 24 <= count($closes); $i += 24) {
        $dh[] = max(array_slice($highs,  $i, 24));
        $dl[] = min(array_slice($lows,   $i, 24));
        $dc[] = $closes[$i + 23];
    }
    if (count($dc) < 2) return 0;
    $trs = [];
    for ($i = 1; $i < count($dc); $i++) {
        $trs[] = max($dh[$i]-$dl[$i], abs($dh[$i]-$dc[$i-1]), abs($dl[$i]-$dc[$i-1]));
    }
    return array_sum($trs) / count($trs);
}

function calc_percentile($arr, $pct) {
    $sorted = $arr; sort($sorted);
    $idx = (int)floor(count($sorted) * $pct / 100);
    return $sorted[min($idx, count($sorted)-1)];
}

function calc_std($arr) {
    $n    = count($arr);
    if ($n < 2) return 0;
    $mean = array_sum($arr) / $n;
    $var  = array_sum(array_map(fn($x) => ($x-$mean)**2, $arr)) / ($n-1);
    return sqrt($var);
}

function calc_expected_trades($closes, $lower, $upper, $levels) {
    $step   = ($upper - $lower) / $levels;
    $trades = 0;
    for ($i = 1; $i < count($closes); $i++) {
        $trades += abs($closes[$i] - $closes[$i-1]) / $step;
    }
    return round($trades / count($closes) * 24, 1);
}

// ============================================================
// ANÁLISIS PRINCIPAL
// ============================================================
try {
    $klines  = get_klines($symbol, $days);
    if (count($klines) < 48) throw new Exception("Pocos datos ($symbol)");

    $opens   = array_map(fn($k) => (float)$k[1], $klines);
    $highs   = array_map(fn($k) => (float)$k[2], $klines);
    $lows    = array_map(fn($k) => (float)$k[3], $klines);
    $closes  = array_map(fn($k) => (float)$k[4], $klines);

    $current   = end($closes);
    $atr_14h   = calc_atr($highs, $lows, $closes);
    $daily_atr = calc_daily_atr($highs, $lows, $closes);
    $std       = calc_std($closes);
    $p5        = calc_percentile($closes, 5);
    $p10       = calc_percentile($closes, 10);
    $p90       = calc_percentile($closes, 90);
    $p95       = calc_percentile($closes, 95);
    $min_price = min($closes);
    $max_price = max($closes);

    // Rango sugerido: p5-p95 + 10% margen
    $margin   = ($p95 - $p5) * 0.10;
    $sug_low  = round($p5  - $margin, $current > 1000 ? 0 : 4);
    $sug_high = round($p95 + $margin, $current > 1000 ? 0 : 4);

    // Asegurar precio actual dentro del rango
    $rng = $sug_high - $sug_low;
    if ($current < $sug_low || $current > $sug_high) {
        $sug_low  = round($current - $rng/2, $current > 1000 ? 0 : 4);
        $sug_high = round($current + $rng/2, $current > 1000 ? 0 : 4);
    }

    // Niveles óptimos
    $ideal_step   = $atr_14h * 2;
    $ideal_levels = max(10, min(50, (int)round($rng / $ideal_step)));
    $ideal_levels = round($ideal_levels / 5) * 5 ?: 10;
    $actual_step  = $rng / $ideal_levels;

    // Proyección
    $exp_trades       = calc_expected_trades($closes, $sug_low, $sug_high, $ideal_levels);
    $profit_per_trade = $actual_step / $current;
    $daily_profit_pct = $exp_trades * $profit_per_trade * 100;
    $daily_atr_pct    = ($daily_atr / $current) * 100;

    echo json_encode([
        'ok'             => true,
        'symbol'         => $symbol,
        'days'           => $days,
        'current_price'  => $current,
        'price_min'      => $min_price,
        'price_max'      => $max_price,
        'daily_atr'      => round($daily_atr, 6),
        'daily_atr_pct'  => round($daily_atr_pct, 2),
        'atr_14h'        => round($atr_14h, 6),
        'std'            => round($std, 6),
        'p10'            => $p10,
        'p90'            => $p90,
        'p5'             => $p5,
        'p95'            => $p95,
        'suggested_lower'  => $sug_low,
        'suggested_upper'  => $sug_high,
        'suggested_levels' => $ideal_levels,
        'actual_step'      => round($actual_step, 6),
        'exp_trades_day'   => $exp_trades,
        'daily_profit_pct' => round($daily_profit_pct, 3),
        'monthly_profit_pct' => round($daily_profit_pct * 30, 2),
    ]);

} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
