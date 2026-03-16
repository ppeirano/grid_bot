# Grid Bot — Documentación del Proyecto

## Resumen
Bot de trading algorítmico con estrategia **Grid Trading** para criptomonedas, con dashboard de monitoreo en PHP y notificaciones por Telegram. Desarrollado en Python + MySQL + XAMPP.

Corriendo en **paper trading** (simulado) con dos instancias: XRP/USDT y BTC/USDT.

---

## Estructura de archivos

```
grid_bot/
├── credentials.env        ← todas las claves (NO compartir)
├── config.py              ← parámetros iniciales (solo primera vez)
├── bot.py                 ← lógica principal del grid bot
├── database.py            ← capa MySQL
├── telegram_notify.py     ← notificaciones Telegram
├── run_xrp.py             ← arranca el bot XRP
├── run_btc.py             ← arranca el bot BTC
├── reset_db.py            ← limpia BD y logs para empezar de cero
├── optimize_grid.py       ← análisis de volatilidad por línea de comandos
├── get_chat_id.py         ← helper para obtener chat ID de Telegram
├── test_telegram.py       ← test de conexión Telegram
├── requirements.txt
└── dashboard/
    ├── index.php           ← dashboard de monitoreo
    ├── ai_proxy.php        ← proxy para llamadas a Claude API
    ├── optimize_proxy.php  ← análisis de volatilidad histórica
    ├── apply_config.php    ← aplica cambios de configuración a la BD
    └── reset_bot.php       ← liquida posiciones y recentra el grid
```

---

## Instalación

### Requisitos
- Python 3.11+
- XAMPP (MySQL + PHP + Apache)
- Cuenta en Binance (solo API de lectura para paper trading)
- Bot de Telegram creado con @BotFather

### Setup

```bash
# 1. Instalar dependencias Python
pip install -r requirements.txt

# 2. Configurar credentials.env

# 3. Colocar toda la carpeta en XAMPP
# C:\xampp\htdocs\grid_bot\

# 4. Arrancar los bots (terminales separadas)
py run_xrp.py
py run_btc.py

# 5. Abrir dashboard
# http://localhost/grid_bot/dashboard/
```

---

## Credenciales (`credentials.env`)

```env
# MySQL
DB_HOST=localhost
DB_PORT=3306
DB_USER=root
DB_PASSWORD=tu_password
DB_NAME=grid_bot

# Binance API (solo lectura en paper trading)
BINANCE_API_KEY=tu_api_key
BINANCE_API_SECRET=tu_api_secret

# Telegram
TELEGRAM_TOKEN=token_del_bot
TELEGRAM_CHAT_ID=tu_chat_id

# Anthropic (para AI Analysis en el dashboard)
ANTHROPIC_API_KEY=tu_api_key
```

### Cómo obtener el chat ID de Telegram
1. Crear bot con @BotFather → `/newbot`
2. Mandarle un mensaje al bot desde Telegram
3. Correr `py get_chat_id.py`

---

## Configuración (`config.py`)

`config.py` define los parámetros **solo la primera vez**. Una vez que el bot existe en la BD, los cambios se hacen desde el dashboard.

```python
BOT_CONFIGS = {
    "XRP": {
        "symbol":       "XRPUSDT",
        "grid_lower":   1.30,
        "grid_upper":   1.55,
        "grid_levels":  20,
        "capital_usdt": 200.0,
    },
    "BTC": {
        "symbol":       "BTCUSDT",
        "grid_lower":   65000,
        "grid_upper":   80000,
        "grid_levels":  20,
        "capital_usdt": 200.0,
    },
}

PAPER_TRADING            = True   # False para operar real
STOP_LOSS_PCT            = 15.0   # % máximo de pérdida
GRID_RECENTER_THRESHOLD  = 0.15   # recentra si precio está en el 15% del borde
GRID_RECENTER_INTERVAL   = 30     # minutos entre chequeos de recentrado
CHECK_INTERVAL_SECONDS   = 10     # frecuencia de tick
```

---

## Cómo funciona la estrategia

### Grid Trading
El bot divide un rango de precios en N niveles equidistantes y opera así:
- Cuando el precio **baja** y cruza un nivel → compra
- Cuando el precio **sube** y cruza un nivel → vende lo que compró abajo

Cada ciclo compra/venta genera una ganancia igual al step del grid menos comisiones.

**Gana con volatilidad lateral. Pierde si hay tendencia sostenida fuera del rango.**

### Comportamiento al arrancar
1. **Recentra el grid** alrededor del precio actual (siempre)
2. **Compra en el nivel actual** — una sola posición inicial
3. Opera normalmente desde ahí

Esto garantiza que el bot siempre arranca bien posicionado y con una posición abierta lista para ciclar en cualquier dirección.

### Por qué no compra en todos los niveles al inicializar
En un grid bot real las órdenes son **límite** — solo se ejecutan cuando el precio llega a ese nivel. Comprar a $66,500 con el precio en $70,700 es imposible en el mercado real. El bot solo compra cuando el precio baja y toca cada nivel naturalmente.

### Prioridad de configuración
```
Primera vez   → config.py   (INSERT IGNORE — solo si el bot no existe en BD)
Reinicios     → BD          (lee siempre desde tabla bots)
Dashboard     → BD          (apply_config.php actualiza tabla bots)
```
El estado (balances, posiciones) siempre se preserva entre reinicios.

### Stop-loss global
Si el valor total del portfolio cae más del `STOP_LOSS_PCT`, el bot para. Requiere reinicio manual.

### Rango dinámico
Cada `GRID_RECENTER_INTERVAL` minutos, si el precio está cerca del borde:
1. Liquida posiciones abiertas al precio actual
2. Recentra el grid manteniendo el mismo ancho

### Reconexión automática
Ante caídas de red usa backoff exponencial (10s → 20s → ... → 300s máx).

---

## Base de datos MySQL

### Tablas
| Tabla | Descripción |
|-------|-------------|
| `bots` | Configuración activa (grid_lower, grid_upper, grid_levels) |
| `bot_state` | Estado actual (balances, posiciones, último precio) |
| `trades` | Historial de todas las operaciones |
| `prices` | Precios registrados cada 10 segundos |

La BD se crea automáticamente al primer arranque.

### Operaciones útiles

```sql
-- Ver configuración actual
SELECT bot_name, grid_lower, grid_upper, grid_levels FROM bots;

-- Ver estado actual
SELECT bot_name, usdt_balance, xrp_btc_balance, realized_pnl FROM bot_state;

-- Migración desde versión anterior
ALTER TABLE bot_state
ADD COLUMN grid_lower DECIMAL(20,8),
ADD COLUMN grid_upper DECIMAL(20,8);
```

---

## Limpiar y empezar de cero

```bash
# Limpiar un bot (BD + log)
py reset_db.py --bot BTC
py reset_db.py --bot XRP

# Limpiar todos
py reset_db.py --all
```

---

## Dashboard

URL: `http://localhost/grid_bot/dashboard/`

### Solapas
| Solapa | Descripción |
|--------|-------------|
| **BTC / XRP** | Dos filas de métricas, grid de posiciones, gráfico con timeframes, trades |
| **Portfolio** | Resumen consolidado, P&L total, cards de cada bot |
| **AI Analysis** | Análisis por Claude con tabla de proyección de escalado |
| **Optimización** | Análisis de volatilidad histórica y parámetros óptimos |

### Métricas — Fila 1 (datos clave)
Precio actual | Valor total | P&L total | P&L realizado | **Stock/Capital** | B/S

### Métricas — Fila 2 (datos secundarios)
Balance USDT | Asset | Capital | Trades | Niveles | Rango grid

### Stock/Capital
Indica qué % del portfolio está en el asset (XRP o BTC):
- 🟢 Verde — menos del 40% (posición liviana)
- 🔵 Cyan — entre 40% y 60% (moderado)
- 🔴 Rojo — más del 60% (mucho inventario, revisá antes de pausar)

### Gráfico
- Selector de timeframe: 30m / 2h / 6h / 12h / 24h
- Eje Y dinámico ajustado al rango del precio visible
- Líneas del grid: cyan sólido = con posición, gris punteado = vacío
- Línea amarilla = precio actual

### Auto-refresh
Cada 15 segundos vía JavaScript usando `window.location.href` con timestamp (`?ts#BTC`).
El `<meta http-equiv="refresh">` no funciona cuando hay un hash en la URL, por eso se usa JS.
La solapa activa se preserva via el hash al final de la URL.

### Botón "Resetear y recentrar"
Disponible en cada bot. Liquida todas las posiciones al precio actual, recentra el grid y deja el estado listo para reiniciar el bot.

**Siempre parar el bot antes de usar este botón.**

### Cambiar parámetros desde el dashboard
1. Solapa **Optimización** → Analizar
2. Si el rango no está bien calibrado → **Aplicar parámetros sugeridos**
3. Reiniciar el bot — toma los nuevos parámetros de la BD

---

## Notificaciones Telegram

| Evento | Sonido | Cuándo |
|--------|--------|--------|
| 🛒 Compra | No | El precio baja y cruza un nivel |
| 💰 Venta con ganancia | No | Ciclo completado exitosamente |
| 📐 Grid recentrado | No | Precio cerca del borde |
| 🚨 Stop-loss | Sí | Portfolio cayó más del límite |
| ⚠️ Red caída | No | 10+ errores consecutivos |

---

## Optimización de parámetros

### Desde el dashboard (recomendado)
Solapa **Optimización** → elige período (30/60/90 días) → muestra ATR, trades/día estimados y rango óptimo → botón Aplicar.

### Por línea de comandos
```bash
py optimize_grid.py ALL           # XRP y BTC, 60 días
py optimize_grid.py XRP --days 90
py optimize_grid.py BTC --days 60 --capital 500
```

### Cuándo re-optimizar
- Cada 2-4 semanas
- Después de un movimiento fuerte del mercado
- Si el bot opera muy poco (rango muy ancho)
- Si el rango dinámico se activa frecuentemente (rango muy angosto)

### Comisiones y rentabilidad
Binance cobra 0.1% por operación (0.075% con BNB activado).
Cada ciclo tiene dos operaciones → **0.2% de costo por ciclo** (0.15% con BNB).

Para que un ciclo sea rentable:
```
step / precio > 0.2%
```

Con BNB activado se recomienda tenerlo habilitado en Binance — el ahorro es automático.

---

## Agregar un nuevo bot

1. Agregar en `config.py`:
```python
"ETH": {
    "symbol":       "ETHUSDT",
    "grid_lower":   2000,
    "grid_upper":   2500,
    "grid_levels":  20,
    "capital_usdt": 200.0,
}
```

2. Crear `run_eth.py`:
```python
from database import init_db
from bot import run_bot
from config import BOT_CONFIGS

if __name__ == "__main__":
    init_db()
    run_bot("ETH", BOT_CONFIGS["ETH"])
```

3. Correr `py run_eth.py` — aparece automáticamente en el dashboard.

---

## Pasar a trading real

1. Generar API keys en Binance con permisos de **spot trading**
2. Actualizar `credentials.env`
3. En `config.py`: `PAPER_TRADING = False`
4. Limpiar estado paper: `py reset_db.py --all`
5. Arrancar con capital conservador

> ⚠️ Recomendado: mínimo 2-3 semanas de paper trading antes de operar real.

---

## Pendientes / Ideas para el futuro

- [ ] Gráfico de velas japonesas (OHLC desde API Binance)
- [ ] Backtesting con datos históricos
- [ ] RSI como filtro de entrada
- [ ] Re-optimización automática periódica con alerta Telegram
- [ ] Modo semi-automático (notifica y espera confirmación)
- [ ] Métricas avanzadas: Sharpe ratio, max drawdown, win rate
- [ ] Soporte para múltiples exchanges

---

## Estado al 14/03/2026

| Bot | Capital | Modo | Estado |
|-----|---------|------|--------|
| XRP | $200 | Paper | Corriendo — grid recentrado, posición inicial abierta |
| BTC | $200 | Paper | Corriendo — grid recentrado, posición inicial abierta |

Ambos bots arrancaron de cero con el fix de inicialización correcto.
Mercado en compresión (Fear & Greed: 15). Esperando volatilidad para ciclar.
