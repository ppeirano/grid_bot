"""
Capa de base de datos - Grid Bot v3
"""

import json
import re
from contextlib import contextmanager
import mysql.connector
from config import DB_HOST, DB_PORT, DB_USER, DB_PASSWORD, DB_NAME


@contextmanager
def get_connection(use_db=True):
    """Context manager que garantiza cierre de conexión incluso ante errores."""
    conn = mysql.connector.connect(
        host=DB_HOST, port=DB_PORT,
        user=DB_USER, password=DB_PASSWORD,
        **({"database": DB_NAME} if use_db else {})
    )
    try:
        yield conn
    finally:
        conn.close()


def init_db():
    # Validar nombre de BD: solo letras, números y guiones bajos
    if not re.match(r'^[a-zA-Z_][a-zA-Z0-9_]*$', DB_NAME):
        raise ValueError(f"Nombre de base de datos inválido: {DB_NAME}")

    with get_connection(use_db=False) as conn:
        cursor = conn.cursor()
        cursor.execute(f"CREATE DATABASE IF NOT EXISTS `{DB_NAME}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
        conn.database = DB_NAME

        cursor.execute("""
            CREATE TABLE IF NOT EXISTS bots (
                id INT AUTO_INCREMENT PRIMARY KEY,
                bot_name VARCHAR(20) NOT NULL UNIQUE,
                symbol VARCHAR(20) NOT NULL,
                paper_trading TINYINT(1) DEFAULT 1,
                grid_lower DECIMAL(20,8) NOT NULL,
                grid_upper DECIMAL(20,8) NOT NULL,
                grid_levels INT NOT NULL,
                capital_usdt DECIMAL(20,8) NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        """)

        cursor.execute("""
            CREATE TABLE IF NOT EXISTS bot_state (
                id INT AUTO_INCREMENT PRIMARY KEY,
                bot_name VARCHAR(20) NOT NULL,
                usdt_balance DECIMAL(20,8) NOT NULL,
                xrp_btc_balance DECIMAL(20,8) NOT NULL,
                realized_pnl DECIMAL(20,8) NOT NULL DEFAULT 0,
                last_price DECIMAL(20,8),
                positions JSON,
                grid_lower DECIMAL(20,8),
                grid_upper DECIMAL(20,8),
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_bot (bot_name)
            )
        """)

        cursor.execute("""
            CREATE TABLE IF NOT EXISTS trades (
                id INT AUTO_INCREMENT PRIMARY KEY,
                bot_name VARCHAR(20) NOT NULL,
                action ENUM('BUY','SELL') NOT NULL,
                level_idx INT NOT NULL,
                price DECIMAL(20,8) NOT NULL,
                qty DECIMAL(20,8) NOT NULL,
                usdt_amount DECIMAL(20,8) NOT NULL,
                profit DECIMAL(20,8) DEFAULT NULL,
                executed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_bot_name (bot_name),
                INDEX idx_executed_at (executed_at)
            )
        """)

        cursor.execute("""
            CREATE TABLE IF NOT EXISTS prices (
                id INT AUTO_INCREMENT PRIMARY KEY,
                bot_name VARCHAR(20) NOT NULL,
                price DECIMAL(20,8) NOT NULL,
                recorded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_bot_recorded (bot_name, recorded_at)
            )
        """)

        conn.commit()
        cursor.close()
    print(f"[DB] Base de datos '{DB_NAME}' inicializada OK")


def load_bot_config(bot_name: str) -> dict:
    """Lee config del bot desde la BD. Si no existe lanza error."""
    with get_connection() as conn:
        cursor = conn.cursor(dictionary=True)
        cursor.execute("SELECT * FROM bots WHERE bot_name = %s", (bot_name,))
        row = cursor.fetchone()
        cursor.close()
    if not row:
        raise ValueError(f"Bot '{bot_name}' no encontrado en BD. Corré init_db() primero.")
    return {
        "grid_lower":  float(row["grid_lower"]),
        "grid_upper":  float(row["grid_upper"]),
        "grid_levels": int(row["grid_levels"]),
        "capital_usdt": float(row["capital_usdt"]),
    }


def upsert_bot(bot_name, symbol, paper_trading, grid_lower, grid_upper, grid_levels, capital_usdt):
    """Inserta el bot si no existe. Si ya existe NO sobreescribe — los cambios del dashboard tienen prioridad."""
    with get_connection() as conn:
        cursor = conn.cursor()
        cursor.execute("""
            INSERT IGNORE INTO bots (bot_name, symbol, paper_trading, grid_lower, grid_upper, grid_levels, capital_usdt)
            VALUES (%s, %s, %s, %s, %s, %s, %s)
        """, (bot_name, symbol, int(paper_trading), grid_lower, grid_upper, grid_levels, capital_usdt))
        conn.commit()
        cursor.close()


def save_state(bot_name, usdt_balance, asset_balance, realized_pnl, last_price, positions, grid_lower=None, grid_upper=None):
    with get_connection() as conn:
        cursor = conn.cursor()
        cursor.execute("""
            INSERT INTO bot_state (bot_name, usdt_balance, xrp_btc_balance, realized_pnl, last_price, positions, grid_lower, grid_upper)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE
                usdt_balance=VALUES(usdt_balance),
                xrp_btc_balance=VALUES(xrp_btc_balance),
                realized_pnl=VALUES(realized_pnl),
                last_price=VALUES(last_price),
                positions=VALUES(positions),
                grid_lower=VALUES(grid_lower),
                grid_upper=VALUES(grid_upper),
                updated_at=CURRENT_TIMESTAMP
        """, (bot_name, usdt_balance, asset_balance, realized_pnl, last_price,
              json.dumps(positions), grid_lower, grid_upper))
        conn.commit()
        cursor.close()


def load_state(bot_name):
    with get_connection() as conn:
        cursor = conn.cursor(dictionary=True)
        cursor.execute("SELECT * FROM bot_state WHERE bot_name = %s", (bot_name,))
        row = cursor.fetchone()
        cursor.close()
    if row and row["positions"]:
        row["positions"] = json.loads(row["positions"])
        converted = {}
        for k, v in row["positions"].items():
            if isinstance(v, dict):
                converted[int(k)] = {"qty": float(v["qty"]), "price": float(v["price"])}
            else:
                converted[int(k)] = float(v)  # formato viejo, se migra en bot.py
        row["positions"] = converted
    return row


def insert_trade(bot_name, action, level_idx, price, qty, usdt_amount, profit=None):
    with get_connection() as conn:
        cursor = conn.cursor()
        cursor.execute("""
            INSERT INTO trades (bot_name, action, level_idx, price, qty, usdt_amount, profit)
            VALUES (%s, %s, %s, %s, %s, %s, %s)
        """, (bot_name, action, level_idx, price, qty, usdt_amount, profit))
        conn.commit()
        cursor.close()


def insert_price(bot_name, price):
    with get_connection() as conn:
        cursor = conn.cursor()
        cursor.execute("INSERT INTO prices (bot_name, price) VALUES (%s, %s)", (bot_name, price))
        conn.commit()
        cursor.close()


def get_summary(bot_name):
    with get_connection() as conn:
        cursor = conn.cursor(dictionary=True)
        cursor.execute("""
            SELECT
                COUNT(*) as total_trades,
                SUM(CASE WHEN action='BUY'  THEN 1 ELSE 0 END) as buys,
                SUM(CASE WHEN action='SELL' THEN 1 ELSE 0 END) as sells,
                COALESCE(SUM(CASE WHEN action='SELL' THEN profit ELSE 0 END), 0) as total_profit
            FROM trades WHERE bot_name = %s
        """, (bot_name,))
        row = cursor.fetchone()
        cursor.close()
    return row
