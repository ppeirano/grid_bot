<?php
// ============================================================
// CONFIG
// ============================================================
function load_env($path) {
    if (!file_exists($path)) return [];
    $vars = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$key, $val] = explode('=', $line, 2);
        $vars[trim($key)] = trim($val);
    }
    return $vars;
}
$env = load_env(__DIR__ . '/../credentials.env');
$db_host     = $env['DB_HOST']     ?? 'localhost';
$db_port     = (int)($env['DB_PORT'] ?? 3306);
$db_user     = $env['DB_USER']     ?? 'root';
$db_password = $env['DB_PASSWORD'] ?? '';
$db_name     = $env['DB_NAME']     ?? 'grid_bot';
$anthropic_key = $env['ANTHROPIC_API_KEY'] ?? '';

function get_pdo($host, $port, $user, $pass, $name) {
    try {
        $pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) { return null; }
}
$pdo = get_pdo($db_host, $db_port, $db_user, $db_password, $db_name);
$db_error = ($pdo === null);

$bots = []; $all_trades = []; $prices_data = []; $bot_trades = [];

if (!$db_error) {
    $stmt = $pdo->query("
        SELECT b.*,
               s.usdt_balance, s.xrp_btc_balance, s.realized_pnl,
               s.last_price, s.grid_lower as state_grid_lower, s.grid_upper as state_grid_upper,
               s.updated_at as state_updated,
               (SELECT COUNT(*) FROM trades t WHERE t.bot_name = b.bot_name) as total_trades,
               (SELECT COUNT(*) FROM trades t WHERE t.bot_name = b.bot_name AND t.action = 'BUY') as total_buys,
               (SELECT COUNT(*) FROM trades t WHERE t.bot_name = b.bot_name AND t.action = 'SELL') as total_sells,
               (SELECT COALESCE(SUM(profit),0) FROM trades t WHERE t.bot_name = b.bot_name AND t.action = 'SELL') as gross_profit
        FROM bots b
        LEFT JOIN bot_state s ON s.bot_name = b.bot_name
        ORDER BY b.created_at ASC
    ");
    $bots = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("SELECT * FROM trades ORDER BY executed_at DESC LIMIT 50");
    $all_trades = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($bots as $bot) {
        $name = $bot['bot_name'];
        // Load 24h of prices, downsampled to 500 points
        $stmt2 = $pdo->prepare("SELECT price, recorded_at FROM prices WHERE bot_name = ? AND recorded_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) ORDER BY recorded_at ASC");
        $stmt2->execute([$name]);
        $all_prices = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        $max_points = 500;
        if (count($all_prices) > $max_points) {
            $step = ceil(count($all_prices) / $max_points);
            $sampled = [];
            for ($pi = 0; $pi < count($all_prices); $pi += $step) $sampled[] = $all_prices[$pi];
            $sampled[] = end($all_prices);
            $all_prices = $sampled;
        }
        $prices_data[$name] = $all_prices;

        $stmt3 = $pdo->prepare("SELECT * FROM trades WHERE bot_name = ? ORDER BY executed_at DESC LIMIT 30");
        $stmt3->execute([$name]);
        $bot_trades[$name] = $stmt3->fetchAll(PDO::FETCH_ASSOC);
    }
}

function fmt_num($n, $dec = 2) { return number_format((float)$n, $dec, '.', ','); }
function pnl_class($val) { return $val >= 0 ? 'positive' : 'negative'; }

// Portfolio totals
$total_capital = 0; $total_value = 0; $total_realized = 0; $total_trades_count = 0;
foreach ($bots as $bot) {
    $total_capital += (float)$bot['capital_usdt'];
    $lp = (float)($bot['last_price'] ?? 0);
    $total_value   += (float)($bot['usdt_balance'] ?? $bot['capital_usdt']) + (float)($bot['xrp_btc_balance'] ?? 0) * $lp;
    $total_realized += (float)($bot['gross_profit'] ?? 0);
    $total_trades_count += (int)$bot['total_trades'];
}
$total_pnl = $total_value - $total_capital;
$total_pnl_pct = $total_capital > 0 ? ($total_pnl / $total_capital * 100) : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Grid Bot Monitor</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@3.0.1/dist/chartjs-plugin-annotation.min.js"></script>
<style>
:root {
    --bg:#0a0d12; --bg2:#111520; --bg3:#181e2c; --border:#1e2840;
    --accent:#00e5ff; --accent2:#7c3aed; --green:#00e676; --red:#ff1744;
    --yellow:#ffd600; --text:#e2e8f0; --muted:#64748b;
    --mono:'Space Mono',monospace; --sans:'DM Sans',sans-serif;
}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:var(--sans);min-height:100vh}
body::before{content:'';position:fixed;inset:0;pointer-events:none;z-index:9999;
    background:repeating-linear-gradient(0deg,transparent,transparent 2px,rgba(0,229,255,.015) 2px,rgba(0,229,255,.015) 4px)}
.header{background:var(--bg2);border-bottom:1px solid var(--border);padding:.85rem 2rem;display:flex;align-items:center;justify-content:space-between}
.header-title{font-family:var(--mono);font-size:1.1rem;color:var(--accent);letter-spacing:.1em;text-transform:uppercase}
.header-title span{color:var(--muted)}
.live-badge{display:flex;align-items:center;gap:.5rem;font-size:.75rem;color:var(--muted);font-family:var(--mono)}
.live-dot{width:8px;height:8px;border-radius:50%;background:var(--green);box-shadow:0 0 8px var(--green);animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
.tab-bar{background:var(--bg2);border-bottom:1px solid var(--border);padding:0 2rem;display:flex;overflow-x:auto}
.tab-btn{font-family:var(--mono);font-size:.78rem;letter-spacing:.08em;text-transform:uppercase;padding:.85rem 1.5rem;border:none;background:transparent;color:var(--muted);cursor:pointer;border-bottom:2px solid transparent;transition:all .15s;white-space:nowrap}
.tab-btn:hover{color:var(--text)}
.tab-btn.active{color:var(--accent);border-bottom-color:var(--accent)}
.tab-pane{display:none}
.tab-pane.active{display:block}
.main{padding:1.5rem 2rem}
.bot-card{background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;position:relative;overflow:hidden}
.bot-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--accent),var(--accent2))}
.bot-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem}
.bot-name{font-family:var(--mono);font-size:1.3rem;color:var(--accent);letter-spacing:.05em}
.bot-symbol{font-size:.8rem;color:var(--muted);margin-left:.5rem}
.paper-badge{font-family:var(--mono);font-size:.65rem;padding:2px 8px;border-radius:4px;background:rgba(124,58,237,.2);color:var(--accent2);border:1px solid var(--accent2);text-transform:uppercase;letter-spacing:.1em}
.real-badge{background:rgba(255,23,68,.15);color:var(--red);border-color:var(--red)}
.metric{background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:.85rem 1rem}
.metric-label{font-size:.68rem;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:.3rem}
.metric-value{font-family:var(--mono);font-size:1.05rem;color:var(--text)}
.metric-value.positive{color:var(--green)}
.metric-value.negative{color:var(--red)}
.metric-value.accent{color:var(--accent)}
.grid-viz{background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:1rem;height:100%}
.grid-viz-label{font-size:.68rem;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:.75rem}
.grid-levels{display:flex;flex-direction:column-reverse;gap:3px}
.grid-level{display:flex;align-items:center;gap:.5rem;font-family:var(--mono);font-size:.72rem}
.grid-level-bar{height:16px;border-radius:3px;min-width:4px;flex:1;background:var(--bg);border:1px solid var(--border);position:relative;overflow:hidden}
.grid-level-bar.has-position{background:rgba(0,229,255,.12);border-color:var(--accent)}
.grid-level-bar.is-current{border-color:var(--yellow);background:rgba(255,214,0,.15)}
.grid-level-price{color:var(--muted);width:60px;text-align:right;font-size:.68rem}
.grid-level-qty{color:var(--accent);width:55px;font-size:.68rem}
.grid-level-sell{color:var(--green);width:60px;font-size:.65rem}
.chart-wrap{background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:1rem;height:320px}
.tf-btn{font-family:var(--mono);font-size:.65rem;padding:3px 8px;border-radius:4px;background:var(--bg3);color:var(--muted);border:1px solid var(--border);cursor:pointer;letter-spacing:.05em;transition:all .15s}
.tf-btn:hover{color:var(--text);border-color:var(--accent)}
.tf-btn.active{color:var(--accent);border-color:var(--accent);background:rgba(0,229,255,.08)}
.section-title{font-family:var(--mono);font-size:.8rem;color:var(--muted);text-transform:uppercase;letter-spacing:.1em;margin-bottom:1rem;padding-bottom:.5rem;border-bottom:1px solid var(--border)}
.trades-table{width:100%;border-collapse:collapse;font-size:.8rem}
.trades-table th{font-family:var(--mono);font-size:.65rem;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;padding:.5rem .75rem;text-align:left;border-bottom:1px solid var(--border)}
.trades-table td{padding:.5rem .75rem;border-bottom:1px solid rgba(30,40,64,.5);font-family:var(--mono);font-size:.75rem}
.trades-table tr:hover td{background:var(--bg3)}
.badge-buy{color:var(--green)}
.badge-sell{color:var(--red)}
.badge-bot{color:var(--accent)}
.db-error{background:rgba(255,23,68,.1);border:1px solid var(--red);border-radius:8px;padding:1.5rem;font-family:var(--mono);color:var(--red);margin:2rem}
.footer{text-align:center;padding:1rem;font-size:.7rem;color:var(--muted);font-family:var(--mono);border-top:1px solid var(--border);margin-top:2rem}
</style>
</head>
<body>
<script>
function showTab(name, btn) {
    document.querySelectorAll('.tab-pane').forEach(function(p){p.classList.remove('active')});
    document.querySelectorAll('.tab-btn').forEach(function(b){b.classList.remove('active')});
    var pane = document.getElementById('tab-'+name);
    if(pane) pane.classList.add('active');
    if(btn) btn.classList.add('active');
    location.hash = name;
}
function setTimeframe(botName, tf, btn) {
    btn.closest('.tf-wrap').querySelectorAll('.tf-btn').forEach(function(b){b.classList.remove('active')});
    btn.classList.add('active');
    var c = window['chart_'+botName];
    if(c) c.updateChart(tf);
}
</script>

<div class="header">
    <div class="header-title">Grid<span>/</span>Bot <span>// Monitor</span></div>
    <div class="live-badge"><div class="live-dot"></div>AUTO-REFRESH 15s &nbsp;|&nbsp; <?= date('H:i:s') ?></div>
</div>

<?php if($db_error): ?>
<div class="db-error">[ERROR] No se pudo conectar a MySQL. Verificá credentials.env</div>
<?php elseif(empty($bots)): ?>
<div class="main"><div class="db-error" style="color:var(--yellow);border-color:var(--yellow);background:rgba(255,214,0,.05)">[ESPERANDO] Ejecutá run_xrp.py o run_btc.py primero.</div></div>
<?php else: ?>

<div class="tab-bar">
    <?php foreach($bots as $i => $bot): ?>
    <button class="tab-btn <?= $i===0?'active':'' ?>" onclick="showTab('<?= $bot['bot_name'] ?>', this)">
        <?= htmlspecialchars($bot['bot_name']) ?>
        <span style="color:var(--muted);font-size:.65rem;margin-left:4px"><?= htmlspecialchars($bot['symbol']) ?></span>
    </button>
    <?php endforeach; ?>
    <button class="tab-btn" onclick="showTab('portfolio', this)">Portfolio</button>
    <button class="tab-btn" onclick="showTab('ai', this)">AI Analysis</button>
    <button class="tab-btn" onclick="showTab('optimize', this)">Optimizacion</button>
</div>

<div class="main">

<?php foreach($bots as $bot):
    $name       = $bot['bot_name'];
    $usdt       = (float)($bot['usdt_balance'] ?? $bot['capital_usdt']);
    $asset      = (float)($bot['xrp_btc_balance'] ?? 0);
    $last_price = (float)($bot['last_price'] ?? 0);
    $realized   = (float)($bot['gross_profit'] ?? 0);
    $unrealized = $asset * $last_price;
    $total      = $usdt + $unrealized;
    $pnl        = $total - (float)$bot['capital_usdt'];
    $pnl_pct    = $bot['capital_usdt'] > 0 ? ($pnl / $bot['capital_usdt'] * 100) : 0;
    $is_paper   = (int)$bot['paper_trading'];
    $lower      = (float)($bot['state_grid_lower'] ?: $bot['grid_lower']);
    $upper      = (float)($bot['state_grid_upper'] ?: $bot['grid_upper']);
    $levels     = (int)$bot['grid_levels'];
    $step_size  = $levels > 0 ? ($upper - $lower) / $levels : 0;
    $decimals   = $upper > 1000 ? 0 : 4;
    $grid       = [];
    for($i=0; $i<=$levels; $i++) $grid[] = round($lower + $i*$step_size, 8);
    $current_level = -1;
    for($i=0; $i<count($grid)-1; $i++) {
        if($last_price >= $grid[$i] && $last_price < $grid[$i+1]) { $current_level = $i; break; }
    }
    $positions_raw = [];
    $stmt_p = $pdo->prepare("SELECT positions FROM bot_state WHERE bot_name = ?");
    $stmt_p->execute([$name]);
    $pr = $stmt_p->fetch(PDO::FETCH_ASSOC);
    if($pr && $pr['positions']) {
        $decoded = json_decode($pr['positions'], true) ?? [];
        // Normalizar: formato nuevo {"qty":x,"price":y} o viejo (solo qty)
        $positions_raw = [];
        foreach($decoded as $idx => $val) {
            $positions_raw[$idx] = is_array($val) ? $val : ['qty' => (float)$val, 'price' => 0];
        }
    }
    $stock_pct = $total > 0 ? ($unrealized / $total * 100) : 0;
    $stock_color = $stock_pct > 60 ? 'negative' : ($stock_pct > 40 ? 'accent' : 'positive');
?>
<!-- TAB BOT: <?= $name ?> -->
<div id="tab-<?= $name ?>" class="tab-pane <?= $bot === reset($bots) ? 'active' : '' ?>">
<div class="bot-card">
    <div class="bot-header">
        <div>
            <span class="bot-name"><?= htmlspecialchars($name) ?></span>
            <span class="bot-symbol"><?= htmlspecialchars($bot['symbol']) ?></span>
        </div>
        <div style="display:flex;align-items:center;gap:.75rem">
            <button onclick="resetBot('<?= $name ?>')"
                style="font-family:var(--mono);font-size:.65rem;padding:3px 10px;border-radius:4px;background:rgba(255,23,68,.1);color:var(--red);border:1px solid var(--red);cursor:pointer;letter-spacing:.08em;text-transform:uppercase">
                Resetear y recentrar
            </button>
            <span class="<?= $is_paper ? 'paper-badge' : 'real-badge' ?>"><?= $is_paper ? 'PAPER' : 'REAL' ?></span>
        </div>
    </div>

    <!-- FILA 1: datos clave -->
    <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:.6rem;margin-bottom:.6rem">
        <div class="metric"><div class="metric-label">Precio actual</div><div class="metric-value accent">$<?= fmt_num($last_price, $decimals) ?></div></div>
        <div class="metric"><div class="metric-label">Valor total</div><div class="metric-value">$<?= fmt_num($total) ?></div></div>
        <div class="metric">
            <div class="metric-label">P&L total</div>
            <div class="metric-value <?= pnl_class($pnl) ?>"><?= $pnl>=0?'+':'' ?>$<?= fmt_num($pnl) ?> (<?= fmt_num($pnl_pct) ?>%)</div>
        </div>
        <div class="metric">
            <div class="metric-label">P&L realizado</div>
            <div class="metric-value <?= pnl_class($realized) ?>"><?= $realized>=0?'+':'' ?>$<?= fmt_num($realized,4) ?></div>
        </div>
        <div class="metric">
            <div class="metric-label">Stock / Capital</div>
            <div class="metric-value <?= $stock_color ?>"><?= fmt_num($stock_pct,1) ?>% <span style="font-size:.75rem;color:var(--muted)">asset</span></div>
        </div>
        <div class="metric">
            <div class="metric-label">B / S</div>
            <div class="metric-value"><span class="badge-buy"><?= (int)$bot['total_buys'] ?></span> / <span class="badge-sell"><?= (int)$bot['total_sells'] ?></span></div>
        </div>
    </div>
    <!-- FILA 2: datos secundarios -->
    <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:.6rem;margin-bottom:1.25rem">
        <div class="metric"><div class="metric-label">Balance USDT</div><div class="metric-value">$<?= fmt_num($usdt) ?></div></div>
        <div class="metric"><div class="metric-label">Asset</div><div class="metric-value"><?= fmt_num($asset,6) ?></div></div>
        <div class="metric"><div class="metric-label">Capital</div><div class="metric-value">$<?= fmt_num($bot['capital_usdt']) ?></div></div>
        <div class="metric"><div class="metric-label">Trades</div><div class="metric-value"><?= (int)$bot['total_trades'] ?></div></div>
        <div class="metric"><div class="metric-label">Niveles</div><div class="metric-value"><?= $levels ?></div></div>
        <div class="metric"><div class="metric-label">Rango grid</div><div class="metric-value" style="font-size:.78rem">$<?= fmt_num($lower,$decimals) ?> — $<?= fmt_num($upper,$decimals) ?></div></div>
    </div>

    <!-- GRID + CHART -->
    <div class="row g-3">
        <div class="col-12 col-md-4">
            <div class="grid-viz">
                <div class="grid-viz-label" style="display:grid;grid-template-columns:60px 1fr 55px 60px;gap:.25rem;font-size:.6rem;margin-bottom:.5rem">
                    <span style="text-align:right">COMPRA</span>
                    <span></span>
                    <span>QTY</span>
                    <span style="color:var(--green)">VENTA</span>
                </div>
                <div class="grid-levels">
                <?php for($i=0; $i<$levels; $i++):
                    $pos_qty  = isset($positions_raw[$i]) ? (float)$positions_raw[$i]['qty'] : 0;
                    $has_pos  = $pos_qty > 0;
                    $is_cur   = ($i === $current_level);
                    $bc       = $is_cur ? 'is-current' : ($has_pos ? 'has-position' : '');
                    $sell_price = isset($grid[$i+1]) ? $grid[$i+1] : null;
                    $sell_fmt   = $sell_price ? '$'.fmt_num($sell_price, $decimals==0?0:3) : '—';
                ?>
                <div class="grid-level">
                    <div class="grid-level-price">$<?= fmt_num($grid[$i],$decimals==0?0:3) ?></div>
                    <div class="grid-level-bar <?= $bc ?>">
                        <?php if($is_cur): ?><div style="position:absolute;top:50%;left:4px;transform:translateY(-50%);width:6px;height:6px;border-radius:50%;background:var(--yellow);box-shadow:0 0 6px var(--yellow)"></div><?php endif; ?>
                    </div>
                    <?php if($has_pos): ?>
                    <div class="grid-level-qty"><?= fmt_num($pos_qty,4) ?></div>
                    <div class="grid-level-sell"><?= $sell_fmt ?></div>
                    <?php else: ?>
                    <div class="grid-level-qty" style="color:var(--muted)">—</div>
                    <div class="grid-level-sell" style="color:var(--muted)">—</div>
                    <?php endif; ?>
                </div>
                <?php endfor; ?>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-8">
            <div class="tf-wrap" style="display:flex;justify-content:flex-end;gap:.4rem;margin-bottom:.5rem">
                <?php foreach(['30m','2h','6h','12h','24h'] as $tf): ?>
                <button onclick="setTimeframe('<?= $name ?>','<?= $tf ?>',this)" class="tf-btn <?= $tf==='6h'?'active':'' ?>" data-tf="<?= $tf ?>"><?= $tf ?></button>
                <?php endforeach; ?>
            </div>
            <div class="chart-wrap"><canvas id="chart_<?= $name ?>"></canvas></div>
        </div>
    </div>
</div>

<!-- TRADES DEL BOT -->
<div class="mt-3">
<div class="section-title">Trades — <?= htmlspecialchars($name) ?></div>
<?php if(empty($bot_trades[$name])): ?>
<p style="color:var(--muted);font-size:.8rem;font-family:var(--mono)">Sin trades todavia.</p>
<?php else: ?>
<div style="overflow-x:auto">
<table class="trades-table">
    <thead><tr><th>Hora</th><th>Accion</th><th>Nivel</th><th>Precio</th><th>Cantidad</th><th>USDT</th><th>Ganancia</th></tr></thead>
    <tbody>
    <?php foreach($bot_trades[$name] as $t): ?>
    <tr>
        <td><?= date('H:i:s',strtotime($t['executed_at'])) ?></td>
        <td class="<?= $t['action']==='BUY'?'badge-buy':'badge-sell' ?>"><?= $t['action'] ?></td>
        <td><?= $t['level_idx'] ?></td>
        <td>$<?= fmt_num($t['price'],$decimals) ?></td>
        <td><?= fmt_num($t['qty'],6) ?></td>
        <td>$<?= fmt_num($t['usdt_amount']) ?></td>
        <td class="<?= $t['profit']!==null?pnl_class($t['profit']):'' ?>">
            <?= $t['profit']!==null?($t['profit']>=0?'+':'').'$'.fmt_num($t['profit'],4):'—' ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
</div>
</div><!-- /tab-pane bot -->
<?php endforeach; ?>

<!-- TAB PORTFOLIO -->
<div id="tab-portfolio" class="tab-pane">
<div class="bot-card">
    <div class="section-title" style="margin-bottom:1rem">Resumen del Portfolio</div>
    <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:.6rem;margin-bottom:1.5rem">
        <div class="metric"><div class="metric-label">Capital total</div><div class="metric-value accent">$<?= fmt_num($total_capital) ?></div></div>
        <div class="metric"><div class="metric-label">Valor total</div><div class="metric-value">$<?= fmt_num($total_value) ?></div></div>
        <div class="metric">
            <div class="metric-label">P&L total</div>
            <div class="metric-value <?= pnl_class($total_pnl) ?>"><?= $total_pnl>=0?'+':'' ?>$<?= fmt_num($total_pnl) ?> (<?= fmt_num($total_pnl_pct) ?>%)</div>
        </div>
        <div class="metric">
            <div class="metric-label">P&L realizado</div>
            <div class="metric-value <?= pnl_class($total_realized) ?>"><?= $total_realized>=0?'+':'' ?>$<?= fmt_num($total_realized,4) ?></div>
        </div>
        <div class="metric"><div class="metric-label">Total trades</div><div class="metric-value"><?= $total_trades_count ?></div></div>
        <div class="metric"><div class="metric-label">Bots activos</div><div class="metric-value"><?= count($bots) ?></div></div>
    </div>
    <div class="row g-3">
    <?php foreach($bots as $bot):
        $lp  = (float)($bot['last_price'] ?? 0);
        $tv  = (float)($bot['usdt_balance'] ?? $bot['capital_usdt']) + (float)($bot['xrp_btc_balance'] ?? 0) * $lp;
        $pnl = $tv - (float)$bot['capital_usdt'];
        $pct = $bot['capital_usdt'] > 0 ? ($pnl / $bot['capital_usdt'] * 100) : 0;
    ?>
    <div class="col-12 col-md-6">
    <div class="bot-card" style="cursor:pointer;margin-bottom:0" onclick="showTab('<?= $bot['bot_name'] ?>', document.querySelector('.tab-btn'))">
        <div class="bot-header">
            <div><span class="bot-name"><?= htmlspecialchars($bot['bot_name']) ?></span><span class="bot-symbol"><?= htmlspecialchars($bot['symbol']) ?></span></div>
            <span class="<?= (int)$bot['paper_trading']?'paper-badge':'real-badge' ?>"><?= (int)$bot['paper_trading']?'PAPER':'REAL' ?></span>
        </div>
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:.6rem">
            <div class="metric"><div class="metric-label">Precio</div><div class="metric-value accent">$<?= fmt_num($lp,($bot['grid_upper']>1000?0:4)) ?></div></div>
            <div class="metric"><div class="metric-label">Valor</div><div class="metric-value">$<?= fmt_num($tv) ?></div></div>
            <div class="metric"><div class="metric-label">P&L</div><div class="metric-value <?= pnl_class($pnl) ?>"><?= $pnl>=0?'+':'' ?>$<?= fmt_num($pnl) ?> (<?= fmt_num($pct) ?>%)</div></div>
            <div class="metric"><div class="metric-label">Trades</div><div class="metric-value"><?= (int)$bot['total_trades'] ?> (<span class="badge-buy"><?= (int)$bot['total_buys'] ?></span>/<span class="badge-sell"><?= (int)$bot['total_sells'] ?></span>)</div></div>
        </div>
    </div>
    </div>
    <?php endforeach; ?>
    </div>
</div>
<div class="section-title mt-4">Todos los trades</div>
<?php if(empty($all_trades)): ?>
<p style="color:var(--muted);font-size:.8rem;font-family:var(--mono)">Sin trades todavia.</p>
<?php else: ?>
<div style="overflow-x:auto">
<table class="trades-table">
    <thead><tr><th>Hora</th><th>Bot</th><th>Accion</th><th>Nivel</th><th>Precio</th><th>Cantidad</th><th>USDT</th><th>Ganancia</th></tr></thead>
    <tbody>
    <?php foreach($all_trades as $t): ?>
    <tr>
        <td><?= date('H:i:s',strtotime($t['executed_at'])) ?></td>
        <td class="badge-bot"><?= htmlspecialchars($t['bot_name']) ?></td>
        <td class="<?= $t['action']==='BUY'?'badge-buy':'badge-sell' ?>"><?= $t['action'] ?></td>
        <td><?= $t['level_idx'] ?></td>
        <td>$<?= fmt_num($t['price'],4) ?></td>
        <td><?= fmt_num($t['qty'],6) ?></td>
        <td>$<?= fmt_num($t['usdt_amount']) ?></td>
        <td class="<?= $t['profit']!==null?pnl_class($t['profit']):'' ?>"><?= $t['profit']!==null?($t['profit']>=0?'+':'').'$'.fmt_num($t['profit'],4):'—' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
</div>

<!-- TAB AI -->
<div id="tab-ai" class="tab-pane">
<div class="bot-card">
    <div class="bot-header">
        <div><span class="bot-name" style="color:var(--accent2)">AI</span><span class="bot-symbol">Análisis del portfolio</span></div>
        <button id="ai-run-btn" onclick="runAiAnalysis()" style="font-family:var(--mono);font-size:.75rem;padding:6px 16px;border-radius:6px;background:rgba(124,58,237,.2);color:var(--accent2);border:1px solid var(--accent2);cursor:pointer;letter-spacing:.08em;text-transform:uppercase">Analizar ahora</button>
    </div>
    <div id="ai-output" style="font-family:var(--sans);font-size:.9rem;line-height:1.7;color:var(--text);min-height:120px">
        <span style="color:var(--muted);font-family:var(--mono);font-size:.8rem">Presioná "Analizar ahora" para obtener un análisis del estado actual del portfolio.</span>
    </div>
</div>
</div>

<!-- TAB OPTIMIZACION -->
<div id="tab-optimize" class="tab-pane">
<div class="bot-card">
    <div class="bot-header">
        <div><span class="bot-name" style="color:var(--yellow)">Optimización</span><span class="bot-symbol">Análisis de volatilidad histórica</span></div>
        <div style="display:flex;align-items:center;gap:1rem">
            <select id="opt-days" style="font-family:var(--mono);font-size:.75rem;padding:5px 10px;border-radius:6px;background:var(--bg3);color:var(--text);border:1px solid var(--border)">
                <option value="30">30 días</option>
                <option value="60" selected>60 días</option>
                <option value="90">90 días</option>
            </select>
            <button id="opt-run-btn" onclick="runOptimization()" style="font-family:var(--mono);font-size:.75rem;padding:6px 16px;border-radius:6px;background:rgba(255,214,0,.15);color:var(--yellow);border:1px solid var(--yellow);cursor:pointer;letter-spacing:.08em;text-transform:uppercase">Analizar</button>
        </div>
    </div>
    <div id="opt-output"><span style="color:var(--muted);font-family:var(--mono);font-size:.8rem">Presioná "Analizar" para calcular los parámetros óptimos del grid basados en volatilidad histórica.</span></div>
</div>
</div>

</div><!-- /main -->
<?php endif; ?>

<div class="footer">GRID BOT MONITOR &nbsp;//&nbsp; AUTO-REFRESH 15s &nbsp;//&nbsp; <?= date('Y-m-d H:i:s') ?></div>

<?php
// Build AI data
$ai_data = ['timestamp' => date('Y-m-d H:i:s'), 'bots' => []];
if(!$db_error) {
    foreach($bots as $bot) {
        $bname = $bot['bot_name'];
        $lp    = (float)($bot['last_price'] ?? 0);
        $usdt  = (float)($bot['usdt_balance'] ?? $bot['capital_usdt']);
        $asset = (float)($bot['xrp_btc_balance'] ?? 0);
        $total = $usdt + $asset * $lp;
        $pnl   = $total - (float)$bot['capital_usdt'];
        $lower = (float)($bot['state_grid_lower'] ?: $bot['grid_lower']);
        $upper = (float)($bot['state_grid_upper'] ?: $bot['grid_upper']);
        $stmt_t = $pdo->prepare("SELECT action,price,qty,profit,executed_at FROM trades WHERE bot_name=? ORDER BY executed_at DESC LIMIT 30");
        $stmt_t->execute([$bname]);
        $last_trades = $stmt_t->fetchAll(PDO::FETCH_ASSOC);
        $stmt_r = $pdo->prepare("SELECT MIN(price) as min_p,MAX(price) as max_p,COUNT(*) as ticks FROM prices WHERE bot_name=? AND recorded_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $stmt_r->execute([$bname]);
        $price_1h = $stmt_r->fetch(PDO::FETCH_ASSOC);
        $stmt_c = $pdo->prepare("SELECT DATE(executed_at) as day, SUM(CASE WHEN action='BUY' THEN 1 ELSE 0 END) as buys, SUM(CASE WHEN action='SELL' THEN 1 ELSE 0 END) as sells, COALESCE(SUM(CASE WHEN action='SELL' THEN profit ELSE 0 END),0) as daily_profit FROM trades WHERE bot_name=? AND executed_at > DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY DATE(executed_at) ORDER BY day ASC");
        $stmt_c->execute([$bname]);
        $daily_stats = $stmt_c->fetchAll(PDO::FETCH_ASSOC);
        $stmt_v = $pdo->prepare("SELECT DATE(recorded_at) as day, MIN(price) as min_p, MAX(price) as max_p, ROUND((MAX(price)-MIN(price))/AVG(price)*100,2) as range_pct FROM prices WHERE bot_name=? AND recorded_at > DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY DATE(recorded_at) ORDER BY day ASC");
        $stmt_v->execute([$bname]);
        $daily_vol = $stmt_v->fetchAll(PDO::FETCH_ASSOC);
        $total_sells_7d = array_sum(array_column($daily_stats,'sells'));
        $total_profit_7d = array_sum(array_column($daily_stats,'daily_profit'));
        $days_active = count($daily_stats);
        $avg_cycles = $days_active > 0 ? round($total_sells_7d/$days_active,1) : 0;
        $avg_profit = $days_active > 0 ? round($total_profit_7d/$days_active,4) : 0;
        $profit_per_cycle = $total_sells_7d > 0 ? $total_profit_7d/max($total_sells_7d,1) : 0;
        $ai_data['bots'][] = [
            'name'=>$bname,'symbol'=>$bot['symbol'],'paper_trading'=>(bool)$bot['paper_trading'],
            'capital'=>(float)$bot['capital_usdt'],'current_price'=>$lp,
            'usdt_balance'=>$usdt,'asset_balance'=>$asset,'total_value'=>$total,
            'pnl'=>$pnl,'pnl_pct'=>$bot['capital_usdt']>0?round($pnl/$bot['capital_usdt']*100,2):0,
            'realized_pnl'=>(float)($bot['gross_profit']??0),
            'grid_lower'=>$lower,'grid_upper'=>$upper,'grid_levels'=>(int)$bot['grid_levels'],
            'grid_step'=>round(($upper-$lower)/$bot['grid_levels'],6),
            'total_trades'=>(int)$bot['total_trades'],'buys'=>(int)$bot['total_buys'],'sells'=>(int)$bot['total_sells'],
            'last_trades'=>$last_trades,'price_1h'=>$price_1h,
            'daily_stats_7d'=>$daily_stats,'daily_volatility'=>$daily_vol,
            'summary_7d'=>[
                'days_active'=>$days_active,'total_cycles'=>$total_sells_7d,
                'avg_cycles_day'=>$avg_cycles,'total_profit'=>round($total_profit_7d,4),
                'avg_profit_day'=>$avg_profit,
                'projected_monthly_current'=>round($avg_profit*30,2),
                'projected_monthly_2000usd'=>round($profit_per_cycle*$avg_cycles*30*(2000/$bot['capital_usdt']),2),
                'projected_monthly_5000usd'=>round($profit_per_cycle*$avg_cycles*30*(5000/$bot['capital_usdt']),2),
            ],
        ];
    }
}
?>
<script>
window._portfolioData = <?= json_encode($ai_data) ?>;
window._botSymbols = <?= json_encode(array_map(fn($b) => ['name'=>$b['bot_name'],'symbol'=>$b['symbol'],'lower'=>(float)($b['state_grid_lower']?:$b['grid_lower']),'upper'=>(float)($b['state_grid_upper']?:$b['grid_upper']),'levels'=>(int)$b['grid_levels']], $bots)) ?>;
</script>

<!-- CHARTS -->
<script>
<?php foreach($bots as $bot):
    $name     = $bot['bot_name'];
    $g_lower  = (float)($bot['state_grid_lower'] ?: $bot['grid_lower']);
    $g_upper  = (float)($bot['state_grid_upper'] ?: $bot['grid_upper']);
    $g_levels = (int)$bot['grid_levels'];
    $decimals = $g_upper > 1000 ? 0 : 4;
    $g_grid   = [];
    for($gi=0; $gi<=$g_levels; $gi++) $g_grid[] = round($g_lower + $gi*($g_upper-$g_lower)/$g_levels, 8);
    $g_pos = [];
    $sp = $pdo->prepare("SELECT positions FROM bot_state WHERE bot_name=?");
    $sp->execute([$name]);
    $pr2 = $sp->fetch(PDO::FETCH_ASSOC);
    if($pr2 && $pr2['positions']) { $dec2=json_decode($pr2['positions'],true); if($dec2) foreach($dec2 as $lvl=>$val) { $q=is_array($val)?$val['qty']:$val; if($q>0) $g_pos[]=(int)$lvl; } }
    $g_last = (float)($bot['last_price'] ?? 0);
    $chart_labels=[]; $chart_prices=[];
    if(isset($prices_data[$name])) foreach($prices_data[$name] as $p) { $chart_labels[]=date('H:i:s',strtotime($p['recorded_at'])); $chart_prices[]=(float)$p['price']; }
?>
(function(){
    const ctx = document.getElementById('chart_<?= $name ?>');
    if(!ctx) return;
    const allLabels = <?= json_encode($chart_labels) ?>;
    const allPrices = <?= json_encode($chart_prices) ?>;
    const gridLevels = <?= json_encode($g_grid) ?>;
    const openPos = <?= json_encode($g_pos) ?>;
    const lastPrice = <?= $g_last ?>;
    const dec = <?= $decimals ?>;
    const tfMap = {'30m':180,'2h':720,'6h':2160,'12h':4320,'24h':8640};

    function buildAnnotations(prices) {
        const anns = {};
        if(!prices.length) return anns;
        const mn = Math.min(...prices)*0.998, mx = Math.max(...prices)*1.002;
        gridLevels.forEach(function(level,i) {
            if(level < mn*0.97 || level > mx*1.03) return;
            const hp = openPos.includes(i);
            anns['g'+i] = { type:'line', yMin:level, yMax:level,
                borderColor: hp?'rgba(0,229,255,.6)':'rgba(100,116,139,.25)',
                borderWidth: hp?1.5:.7, borderDash: hp?[]:[4,4], label:{display:false} };
        });
        if(lastPrice>0) anns['price'] = { type:'line', yMin:lastPrice, yMax:lastPrice,
            borderColor:'#ffd600', borderWidth:2,
            label:{display:true, content:'$'+lastPrice.toFixed(dec), color:'#ffd600',
                font:{size:9,family:'Space Mono',weight:'bold'}, position:'end',
                backgroundColor:'rgba(255,214,0,.15)', padding:{x:4,y:2}} };
        return anns;
    }

    function filterTf(tf) {
        const pts = tfMap[tf]||2160;
        const cut = Math.max(0, allLabels.length - pts);
        return { labels:allLabels.slice(cut), prices:allPrices.slice(cut) };
    }

    function getDynAxis(prices) {
        if(!prices.length) return {min:<?= $g_lower ?>, max:<?= $g_upper ?>};
        const mn=Math.min(...prices), mx=Math.max(...prices);
        const pad=(mx-mn)*0.15||mx*0.005;
        return {min:mn-pad, max:mx+pad};
    }

    const init = filterTf('6h');
    const axis = getDynAxis(init.prices);

    const chart = new Chart(ctx, {
        type:'line',
        data:{ labels:init.labels, datasets:[{data:init.prices, borderColor:'#00e5ff', borderWidth:1.5,
            pointRadius:0, tension:.2, fill:true, backgroundColor:'rgba(0,229,255,.04)'}] },
        options:{
            responsive:true, maintainAspectRatio:false, animation:false,
            interaction:{mode:'index',intersect:false},
            plugins:{
                legend:{display:false},
                tooltip:{callbacks:{label:c=>'$'+c.parsed.y.toFixed(dec)},
                    backgroundColor:'#111520',borderColor:'#1e2840',borderWidth:1,
                    titleColor:'#64748b',bodyColor:'#00e5ff',
                    titleFont:{family:'Space Mono',size:9},bodyFont:{family:'Space Mono',size:10}},
                annotation:{annotations:buildAnnotations(init.prices)}
            },
            scales:{
                x:{ticks:{color:'#64748b',font:{family:'Space Mono',size:9},maxTicksLimit:6},grid:{color:'#1e2840'}},
                y:{ticks:{color:'#64748b',font:{family:'Space Mono',size:9},callback:v=>'$'+v.toFixed(dec)},
                   grid:{color:'#1e2840'}, min:axis.min, max:axis.max}
            }
        }
    });

    window['chart_<?= $name ?>'] = {
        updateChart: function(tf) {
            const d = filterTf(tf);
            const ax = getDynAxis(d.prices);
            chart.data.labels = d.labels;
            chart.data.datasets[0].data = d.prices;
            chart.options.scales.y.min = ax.min;
            chart.options.scales.y.max = ax.max;
            chart.options.plugins.annotation.annotations = buildAnnotations(d.prices);
            chart.update('none');
        }
    };
})();
<?php endforeach; ?>

// Restore tab from hash on load + auto-refresh
(function() {
    // Restore active tab
    var hash = location.hash.replace('#','');
    if(hash && document.getElementById('tab-'+hash)) {
        var btns = document.querySelectorAll('.tab-btn');
        for(var i=0;i<btns.length;i++) {
            if((btns[i].getAttribute('onclick')||'').indexOf("'"+hash+"'")!==-1) {
                showTab(hash, btns[i]);
                break;
            }
        }
    }

    // Auto-refresh cada 15s — se pausa si hay una operación en curso (AI, optimización, reset)
    window._pauseRefresh = false;
    function scheduleRefresh() {
        setTimeout(function() {
            if (window._pauseRefresh) {
                scheduleRefresh();
            } else {
                window.location.href = location.pathname + '?' + Date.now() + location.hash;
            }
        }, 15000);
    }
    scheduleRefresh();
})();

// AI Analysis
async function runAiAnalysis() {
    const btn = document.getElementById('ai-run-btn');
    const out = document.getElementById('ai-output');
    window._pauseRefresh = true;
    btn.disabled=true; btn.textContent='Analizando...';
    out.innerHTML='<span style="color:var(--muted);font-family:var(--mono);font-size:.8rem">Consultando a Claude...</span>';
    const prompt = `Sos un experto en trading algorítmico y grid bots. Analizá el siguiente estado del portfolio y dá un resumen claro y directo en español.

DATOS DEL PORTFOLIO:
${JSON.stringify(window._portfolioData, null, 2)}

Tu análisis debe incluir:

## 1. Estado general
Cómo viene el portfolio. Está generando valor?

## 2. Análisis por bot
Para cada bot: ciclos/día promedio, ganancia proyectada, si el grid está bien calibrado para la volatilidad actual.

## 3. Proyección de escalado
Usando los valores de summary_7d.projected_monthly_* ya calculados, presentá OBLIGATORIAMENTE esta tabla:

| Bot | Capital actual | $2,000 | $5,000 |
|-----|---------------|--------|--------|
| [nombre] | $X/mes | $X/mes | $X/mes |
| **TOTAL** | **$X/mes** | **$X/mes** | **$X/mes** |

## 4. Riesgos concretos
Basados en los datos reales, no generalidades.

## 5. Recomendación concreta
Una sola recomendación clara por bot.

Sé directo. Usá los números reales. Evitá frases genéricas.`;
    try {
        const resp = await fetch('ai_proxy.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({prompt})});
        const data = await resp.json();
        const text = data.content?.[0]?.text || 'Sin respuesta.';
        function renderMd(text) {
            const lines = text.split('\n'); let html=''; let i=0;
            while(i<lines.length) {
                const line=lines[i];
                if(line.trim().startsWith('|') && i+1<lines.length && lines[i+1].trim().match(/^\|[-| :]+\|$/)) {
                    let tbl='<div style="overflow-x:auto;margin:1rem 0"><table style="width:100%;border-collapse:collapse;font-family:var(--mono);font-size:.8rem">';
                    const hdrs=line.split('|').filter(h=>h.trim()!=='');
                    tbl+='<thead><tr>'+hdrs.map(h=>`<th style="padding:.5rem .75rem;border-bottom:2px solid var(--accent);color:var(--accent);text-align:left;white-space:nowrap">${h.trim().replace(/\*\*/g,'')}</th>`).join('')+'</tr></thead><tbody>';
                    i+=2;
                    while(i<lines.length && lines[i].trim().startsWith('|')) {
                        const cells=lines[i].split('|').filter(c=>c.trim()!=='');
                        const isTotal=cells[0]&&cells[0].includes('TOTAL');
                        tbl+=`<tr style="${isTotal?'border-top:1px solid var(--border)':''}">`;
                        cells.forEach(c=>{ const v=c.trim().replace(/\*\*/g,''); const col=v.includes('$')?'var(--green)':'var(--text)'; tbl+=`<td style="padding:.4rem .75rem;border-bottom:1px solid rgba(30,40,64,.5);color:${isTotal?'var(--text)':col};font-weight:${isTotal?'bold':'normal'};white-space:nowrap">${v}</td>`; });
                        tbl+='</tr>'; i++;
                    }
                    html+=tbl+'</tbody></table></div>'; continue;
                }
                if(line.startsWith('## ')) html+=`<h3 style="color:var(--accent);font-family:var(--mono);font-size:.95rem;margin:1.2rem 0 .4rem">${line.slice(3)}</h3>`;
                else if(line.startsWith('### ')) html+=`<h4 style="color:var(--accent2);font-family:var(--mono);font-size:.85rem;margin:1rem 0 .3rem">${line.slice(4)}</h4>`;
                else if(line.startsWith('- ')) html+=`<div style="padding:.15rem 0 .15rem 1rem">• ${line.slice(2).replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>')}</div>`;
                else if(line.match(/^\d+\. /)) html+=`<div style="padding:.2rem 0"><b>${line.match(/^(\d+)\./)[1]}.</b> ${line.replace(/^\d+\. /,'').replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>')}</div>`;
                else if(line.trim()==='') html+='<br>';
                else html+=`<p style="margin:.3rem 0">${line.replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>')}</p>`;
                i++;
            }
            return html;
        }
        out.innerHTML=`<div style="padding:.5rem 0">${renderMd(text)}</div><div style="margin-top:1rem;font-size:.7rem;color:var(--muted);font-family:var(--mono)">Analizado: ${new Date().toLocaleTimeString()}</div>`;
    } catch(e) { out.innerHTML=`<span style="color:var(--red);font-family:var(--mono)">Error: ${e.message}</span>`; }
    btn.disabled=false; btn.textContent='Analizar ahora';
    window._pauseRefresh = false;
}

// Reset bot
async function resetBot(botName) {
    if (!confirm(`Resetear ${botName}?\n\nEsto va a:\n1. Liquidar todas las posiciones abiertas al precio actual\n2. Recentrar el grid alrededor del precio actual\n\nDespués tenés que reiniciar el bot manualmente.`)) return;

    window._pauseRefresh = true;
    const btn = event.currentTarget;
    btn.disabled = true;
    btn.textContent = 'Procesando...';

    try {
        const resp = await fetch('reset_bot.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ bot: botName })
        });
        const data = await resp.json();
        if (data.ok) {
            alert(
                `✅ ${botName} reseteado\n\n` +
                `Posiciones cerradas: ${data.positions_closed}\n` +
                `Liquidado: $${data.total_liquidated}\n` +
                `Ganancia/pérdida: $${data.total_profit}\n` +
                `Nuevo rango: $${data.new_lower} — $${data.new_upper}\n` +
                `USDT disponible: $${data.usdt_balance}\n\n` +
                `Reiniciá el bot para aplicar.`
            );
            location.reload();
        } else {
            alert(`❌ Error: ${data.error}`);
        }
    } catch(e) {
        alert(`❌ Error: ${e.message}`);
    }
    btn.disabled = false;
    btn.textContent = 'Resetear y recentrar';
    window._pauseRefresh = false;
}

// Optimization
async function runOptimization() {
    const btn=document.getElementById('opt-run-btn');
    const out=document.getElementById('opt-output');
    const days=document.getElementById('opt-days').value;
    window._pauseRefresh = true;
    btn.disabled=true; btn.textContent='Analizando...';
    out.innerHTML='<span style="color:var(--muted);font-family:var(--mono);font-size:.8rem">Descargando datos de Binance...</span>';
    const bots=window._botSymbols;
    const results=[];
    for(const bot of bots) {
        try {
            const resp=await fetch('optimize_proxy.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({symbol:bot.symbol,days:parseInt(days)})});
            const data=await resp.json();
            if(data.ok) results.push({bot,data}); else results.push({bot,error:data.error});
        } catch(e) { results.push({bot,error:e.message}); }
    }
    let html='<div class="row g-3">';
    for(const r of results) {
        if(r.error) { html+=`<div class="col-12 col-md-6"><div style="background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:1.2rem"><div style="color:var(--red);font-family:var(--mono)">[ERROR] ${r.bot.name}: ${r.error}</div></div></div>`; continue; }
        const d=r.data;
        const rngDiff=Math.abs((d.suggested_upper-d.suggested_lower)-(r.bot.upper-r.bot.lower));
        const rangeOk=rngDiff/(r.bot.upper-r.bot.lower)<0.15;
        const status=rangeOk?'✅ Bien calibrado':'⚠️ Ajuste sugerido';
        const sc=rangeOk?'var(--green)':'var(--yellow)';
        const dec=d.current_price>1000?0:4;
        html+=`<div class="col-12 col-md-6"><div style="background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:1.2rem">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
                <span style="font-family:var(--mono);font-size:1.1rem;color:var(--accent)">${r.bot.name}</span>
                <span style="font-size:.8rem;color:${sc};font-family:var(--mono)">${status}</span>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-bottom:1rem;font-size:.78rem;font-family:var(--mono)">
                <div style="background:var(--bg2);border-radius:6px;padding:.6rem">
                    <div style="color:var(--muted);font-size:.65rem;margin-bottom:.2rem">RANGO ACTUAL</div>
                    <div>$${r.bot.lower.toFixed(dec)} — $${r.bot.upper.toFixed(dec)}</div>
                    <div style="color:var(--muted);font-size:.65rem">${r.bot.levels} niveles</div>
                </div>
                <div style="background:var(--bg2);border-radius:6px;padding:.6rem;border:1px solid ${sc}44">
                    <div style="color:var(--muted);font-size:.65rem;margin-bottom:.2rem">SUGERIDO</div>
                    <div style="color:${sc}">$${d.suggested_lower.toFixed(dec)} — $${d.suggested_upper.toFixed(dec)}</div>
                    <div style="color:var(--muted);font-size:.65rem">${d.suggested_levels} niveles</div>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.5rem;margin-bottom:1rem;font-size:.75rem;font-family:var(--mono)">
                <div><div style="color:var(--muted);font-size:.6rem">ATR DIARIO</div><div>$${d.daily_atr.toFixed(dec)} (${d.daily_atr_pct}%)</div></div>
                <div><div style="color:var(--muted);font-size:.6rem">TRADES/DÍA</div><div>~${d.exp_trades_day}</div></div>
                <div><div style="color:var(--muted);font-size:.6rem">GANANCIA/MES</div><div style="color:var(--green)">~${d.monthly_profit_pct}%</div></div>
            </div>
            <div style="font-size:.7rem;color:var(--muted);font-family:var(--mono);margin-bottom:.75rem">P10/P90: $${d.p10.toFixed(dec)} / $${d.p90.toFixed(dec)} &nbsp;|&nbsp; Min/Max: $${d.price_min.toFixed(dec)} / $${d.price_max.toFixed(dec)}</div>
            ${!rangeOk?`<button onclick="applyOptimization('${r.bot.name}',${d.suggested_lower},${d.suggested_upper},${d.suggested_levels})" style="width:100%;font-family:var(--mono);font-size:.72rem;padding:6px;border-radius:6px;background:rgba(255,214,0,.1);color:var(--yellow);border:1px solid var(--yellow);cursor:pointer;text-transform:uppercase;letter-spacing:.08em">Aplicar parámetros sugeridos</button>`:''}
        </div></div>`;
    }
    html+=`</div><div style="margin-top:1rem;font-size:.7rem;color:var(--muted);font-family:var(--mono)">Analizado: ${new Date().toLocaleTimeString()} | ${days} días</div>`;
    out.innerHTML=html;
    btn.disabled=false; btn.textContent='Analizar';
    window._pauseRefresh = false;
}

async function applyOptimization(botName,lower,upper,levels) {
    if(!confirm(`Aplicar nuevo rango a ${botName}?\n$${lower} — $${upper} (${levels} niveles)\n\nReiniciá el bot para aplicar.`)) return;
    const resp=await fetch('apply_config.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({bot:botName,lower,upper,levels})});
    const data=await resp.json();
    alert(data.ok?`✅ Config actualizada para ${botName}. Reiniciá el bot.`:`❌ Error: ${data.error}`);
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
