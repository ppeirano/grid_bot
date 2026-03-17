# ============================================================
# CONFIGURACIÓN GLOBAL - Grid Bot v3
# Las credenciales se leen desde credentials.env
# ============================================================

import os
from pathlib import Path

def _load_env(path="credentials.env"):
    env_path = Path(__file__).parent / path
    if not env_path.exists():
        raise FileNotFoundError(f"No se encontro {env_path}. Crea el archivo credentials.env.")
    with open(env_path) as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            key, _, val = line.partition("=")
            os.environ.setdefault(key.strip(), val.strip())

_load_env()

# --- Base de datos MySQL ---
DB_HOST     = os.environ["DB_HOST"]
DB_PORT     = int(os.environ.get("DB_PORT", 3306))
DB_USER     = os.environ["DB_USER"]
DB_PASSWORD = os.environ["DB_PASSWORD"]
DB_NAME     = os.environ["DB_NAME"]

# --- API Binance ---
API_KEY    = os.environ["BINANCE_API_KEY"]
API_SECRET = os.environ["BINANCE_API_SECRET"]

# --- Modo global ---
PAPER_TRADING = True      # True = simulado, False = real

# --- Intervalo de chequeo (segundos) ---
CHECK_INTERVAL_SECONDS = 10

# --- Stop-loss global ---
STOP_LOSS_PCT = 15.0

# --- Comision por operacion (Binance spot: 0.1%, con BNB: 0.075%) ---
TRADING_FEE_PCT = 0.1

# --- Rango dinamico ---
GRID_RECENTER_THRESHOLD = 0.15
GRID_RECENTER_INTERVAL  = 30

# --- Configuraciones por bot ---
BOT_CONFIGS = {
    "XRP": {
        "symbol": "XRPUSDT",
        "grid_lower": 1.30,
        "grid_upper": 1.55,
        "grid_levels": 20,
        "capital_usdt": 200.0,
    },
    "BTC": {
        "symbol": "BTCUSDT",
        "grid_lower": 65000,
        "grid_upper": 80000,
        "grid_levels": 20,
        "capital_usdt": 200.0,
    },
}
