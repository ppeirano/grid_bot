"""
Grid Bot v3 - Stop-loss global + Rango dinámico
"""

import os
import time
import logging
import requests
from datetime import datetime, timedelta
from config import (
    PAPER_TRADING, CHECK_INTERVAL_SECONDS,
    STOP_LOSS_PCT, GRID_RECENTER_THRESHOLD, GRID_RECENTER_INTERVAL
)
import database as db
import telegram_notify as tg


def get_logger(bot_name):
    logger = logging.getLogger(bot_name)
    if not logger.handlers:
        logger.setLevel(logging.INFO)
        fmt = logging.Formatter(f"%(asctime)s [{bot_name}] [%(levelname)s] %(message)s")
        fh = logging.FileHandler(f"{bot_name.lower()}_bot.log", encoding="utf-8")
        fh.setFormatter(fmt)
        logger.addHandler(fh)
        import sys
        sh = logging.StreamHandler(stream=open(1, "w", encoding="utf-8", closefd=False))
        sh.setFormatter(fmt)
        logger.addHandler(sh)
    return logger


def get_price(symbol: str) -> float:
    url = f"https://api.binance.com/api/v3/ticker/price?symbol={symbol}"
    r = requests.get(url, timeout=5)
    r.raise_for_status()
    return float(r.json()["price"])


def build_grid(lower: float, upper: float, levels: int) -> list:
    step = (upper - lower) / levels
    return [round(lower + i * step, 8) for i in range(levels + 1)]


class GridBot:
    def __init__(self, bot_name: str, cfg: dict):
        self.bot_name     = bot_name
        self.symbol       = cfg["symbol"]
        self.capital_usdt = cfg["capital_usdt"]
        self.log = get_logger(bot_name)
        self.stopped = False
        tg.init(
            os.environ.get("TELEGRAM_TOKEN", ""),
            os.environ.get("TELEGRAM_CHAT_ID", "")
        )
        self.last_recenter_check = datetime.now()

        # Registrar config inicial en BD (solo si no existe)
        db.upsert_bot(
            bot_name, self.symbol, PAPER_TRADING,
            cfg["grid_lower"], cfg["grid_upper"],
            cfg["grid_levels"], self.capital_usdt
        )

        # Leer config desde BD (puede haber sido actualizada desde el dashboard)
        db_cfg = db.load_bot_config(bot_name)
        self.grid_levels       = db_cfg["grid_levels"]
        self.capital_per_level = self.capital_usdt / self.grid_levels
        cfg_lower = db_cfg["grid_lower"]
        cfg_upper = db_cfg["grid_upper"]

        if db_cfg["grid_levels"] != cfg["grid_levels"] or            db_cfg["grid_lower"]  != cfg["grid_lower"]  or            db_cfg["grid_upper"]  != cfg["grid_upper"]:
            self.log.info(
                f"Parametros actualizados desde BD: "
                f"${cfg_lower}-${cfg_upper} ({self.grid_levels} niveles)"
            )

        # Recuperar o inicializar estado
        saved = db.load_state(bot_name)
        if saved:
            self.usdt_balance  = float(saved["usdt_balance"])
            self.asset_balance = float(saved["xrp_btc_balance"])
            self.realized_pnl  = float(saved["realized_pnl"])
            self.last_price    = float(saved["last_price"]) if saved["last_price"] else None
            self.positions     = saved["positions"]
            # Siempre usar config de BD como rango base (puede haber sido actualizado desde dashboard)
            self.grid_lower    = cfg_lower
            self.grid_upper    = cfg_upper
            self.log.info(f"Estado recuperado | USDT: ${self.usdt_balance:.2f} | P&L: ${self.realized_pnl:.4f}")
        else:
            self.usdt_balance  = self.capital_usdt
            self.asset_balance = 0.0
            self.realized_pnl  = 0.0
            self.last_price    = None
            self.positions     = {}
            self.grid_lower    = cfg_lower
            self.grid_upper    = cfg_upper

        self.grid = build_grid(self.grid_lower, self.grid_upper, self.grid_levels)
        # Migrar formato viejo de posiciones {idx: qty} -> {idx: {"qty": qty, "price": price}}
        for k, v in list(self.positions.items()):
            if not isinstance(v, dict):
                self.positions[k] = {"qty": float(v), "price": self.grid[k] if k < len(self.grid) else 0}
        self._log_grid_info()

        # Al arrancar: recentrar siempre + comprar en nivel actual
        try:
            current_price = get_price(self.symbol)
            rng = self.grid_upper - self.grid_lower

            # Liquidar posiciones abiertas si las hay
            if self.positions:
                self.log.info(f"Liquidando {len(self.positions)} posiciones previas al recentrar...")
                for level_idx in list(self.positions.keys()):
                    self.sell(level_idx, current_price, forced=True)

            # Recentrar grid alrededor del precio actual
            half = rng / 2
            self.grid_lower = round(current_price - half, 8)
            self.grid_upper = round(current_price + half, 8)
            self.grid = build_grid(self.grid_lower, self.grid_upper, self.grid_levels)
            self.last_price = current_price
            self._save()
            self._log_grid_info()
            self.log.info(f"Grid recentrado en ${current_price:.4f}")

            # Comprar en el nivel actual (primera posicion)
            level = self.get_level(current_price)
            if level >= 0:
                self.buy(level, current_price)
                self.log.info(f"Posicion inicial abierta en nivel {level} | ${current_price:.4f}")

        except Exception as e:
            self.log.warning(f"No se pudo inicializar al arrancar: {e}")

    def _log_grid_info(self):
        step = self.grid[1] - self.grid[0]
        self.log.info(
            f"Grid: ${self.grid_lower} - ${self.grid_upper} | "
            f"Niveles: {self.grid_levels} | Step: ${step:.6f} | "
            f"Capital/nivel: ${self.capital_per_level:.2f}"
        )

    def _save(self):
        db.save_state(
            self.bot_name, self.usdt_balance, self.asset_balance,
            self.realized_pnl, self.last_price, self.positions,
            self.grid_lower, self.grid_upper
        )

    def get_level(self, price: float) -> int:
        for i in range(len(self.grid) - 1):
            if self.grid[i] <= price < self.grid[i + 1]:
                return i
        return -1

    def total_value(self, price: float) -> float:
        return self.usdt_balance + self.asset_balance * price

    def print_status(self, price: float):
        total = self.total_value(price)
        pnl   = total - self.capital_usdt
        pnl_pct = pnl / self.capital_usdt * 100
        summary = db.get_summary(self.bot_name)
        sl_pct  = -STOP_LOSS_PCT
        self.log.info(
            f"Precio: ${price:.4f} | USDT: ${self.usdt_balance:.2f} | "
            f"Asset: {self.asset_balance:.6f} | Total: ${total:.2f} | "
            f"P&L: ${pnl:.2f} ({pnl_pct:.2f}%) [SL: {sl_pct:.0f}%] | "
            f"Trades: {summary['total_trades']} | "
            f"Realizado: ${float(summary['total_profit'] or 0):.4f}"
        )

    # ---- STOP-LOSS ----
    def check_stop_loss(self, price: float) -> bool:
        total   = self.total_value(price)
        loss_pct = (total - self.capital_usdt) / self.capital_usdt * 100
        if loss_pct <= -STOP_LOSS_PCT:
            msg = f"[STOP-LOSS] Perdida del {loss_pct:.2f}% supera el limite de -{STOP_LOSS_PCT}%. Bot detenido. Valor total: ${total:.2f}"
            self.log.warning(msg)
            tg.send(f"🚨 <b>[{self.bot_name}] STOP-LOSS ACTIVADO</b>\nPerdida: {loss_pct:.2f}%\nValor total: ${total:.2f}")
            self.stopped = True
            self._save()
            return True
        return False

    # ---- RANGO DINAMICO ----
    def check_recenter(self, price: float) -> bool:
        """Retorna True si se recentró el grid (el tick debe abortar)."""
        now = datetime.now()
        if (now - self.last_recenter_check).total_seconds() < GRID_RECENTER_INTERVAL * 60:
            return False
        self.last_recenter_check = now

        rng   = self.grid_upper - self.grid_lower
        margin = rng * GRID_RECENTER_THRESHOLD

        near_bottom = price < self.grid_lower + margin
        near_top    = price > self.grid_upper - margin
        out_of_range = price < self.grid_lower or price > self.grid_upper

        if near_bottom or near_top or out_of_range:
            # Liquidar posiciones abiertas al precio actual
            if self.positions:
                self.log.info(f"[RECENTER] Liquidando {len(self.positions)} posiciones antes de recentrar...")
                for level_idx in list(self.positions.keys()):
                    self.sell(level_idx, price, forced=True)

            # Nuevo rango centrado en el precio actual
            half = rng / 2
            new_lower = round(price - half, 8)
            new_upper = round(price + half, 8)
            self.log.info(
                f"[RECENTER] Precio ${price:.4f} cerca del borde. "
                f"Nuevo rango: ${new_lower} - ${new_upper}"
            )
            tg.send(f"📐 <b>[{self.bot_name}] GRID RECENTRADO</b>\nPrecio: ${price:.4f}\nNuevo rango: ${new_lower} - ${new_upper}", silent=True)
            self.grid_lower = new_lower
            self.grid_upper = new_upper
            self.grid = build_grid(new_lower, new_upper, self.grid_levels)
            self.last_price = None   # reinicia inicialización del grid
            self._save()
            self._log_grid_info()
            return True
        return False

    # ---- OPERACIONES ----
    def buy(self, level_idx: int, price: float):
        cost = self.capital_per_level
        if self.usdt_balance < cost:
            self.log.warning(f"Sin USDT para comprar en nivel {level_idx}")
            return
        qty = cost / price
        self.usdt_balance  -= cost
        self.asset_balance += qty
        existing = self.positions.get(level_idx)
        if existing:
            old_qty = existing["qty"]
            new_qty = old_qty + qty
            avg_price = (old_qty * existing["price"] + qty * price) / new_qty
            self.positions[level_idx] = {"qty": new_qty, "price": avg_price}
        else:
            self.positions[level_idx] = {"qty": qty, "price": price}
        db.insert_trade(self.bot_name, "BUY", level_idx, price, qty, cost)
        self._save()
        self.log.info(f"[COMPRA] Nivel {level_idx} | ${price:.4f} | {qty:.6f} | Costo: ${cost:.2f}")
        tg.send(
            f"🛒 <b>[{self.bot_name}] COMPRA</b>\n"
            f"Nivel {level_idx} | Precio: ${price:.4f}\n"
            f"Cantidad: {qty:.6f} | Costo: ${cost:.2f}\n"
            f"USDT restante: ${self.usdt_balance:.2f}",
            silent=True
        )

    def sell(self, level_idx: int, price: float, forced: bool = False):
        if level_idx not in self.positions or self.positions[level_idx]["qty"] <= 0:
            return
        pos       = self.positions[level_idx]
        qty       = pos["qty"]
        buy_price = pos["price"]
        revenue   = qty * price
        profit    = revenue - (qty * buy_price)
        self.usdt_balance  += revenue
        self.asset_balance = max(0, self.asset_balance - qty)
        self.realized_pnl  += profit
        del self.positions[level_idx]
        tag = "VENTA-FORZADA" if forced else "VENTA"
        db.insert_trade(self.bot_name, "SELL", level_idx, price, qty, revenue, profit)
        self._save()
        self.log.info(f"[{tag}] Nivel {level_idx} | ${price:.4f} | Compra: ${buy_price:.4f} | {qty:.6f} | Ganancia: ${profit:.4f}")
        if forced:
            tg.send(
                f"⚡ <b>[{self.bot_name}] VENTA FORZADA</b>\n"
                f"Nivel {level_idx} | Precio: ${price:.4f}\n"
                f"Compra fue: ${buy_price:.4f}\n"
                f"Cantidad: {qty:.6f} | Revenue: ${revenue:.2f}\n"
                f"P&L: {'+' if profit >= 0 else ''}${profit:.4f}",
                silent=True
            )
        elif profit > 0:
            tg.send(
                f"💰 <b>[{self.bot_name}] VENTA</b>\n"
                f"Nivel {level_idx} | Precio: ${price:.4f}\n"
                f"Compra fue: ${buy_price:.4f}\n"
                f"Cantidad: {qty:.6f} | Revenue: ${revenue:.2f}\n"
                f"Ganancia: +${profit:.4f}",
                silent=True
            )

    # ---- TICK PRINCIPAL ----
    def tick(self, price: float):
        if self.stopped:
            return

        db.insert_price(self.bot_name, price)

        # 1. Chequeo stop-loss
        if self.check_stop_loss(price):
            return

        # 2. Chequeo recentrado — si recentró, abortar este tick
        if self.check_recenter(price):
            return

        # 3. Lógica grid normal
        if price < self.grid_lower or price > self.grid_upper:
            self.log.warning(f"Precio ${price:.4f} fuera del rango. Sin accion.")
            self.last_price = price
            self._save()
            return

        level = self.get_level(price)

        if self.last_price is None:
            self.log.info("Inicializando grid... esperando movimiento de precio para operar.")
            self.last_price = price
            self._save()
            return

        last_level = self.get_level(self.last_price)

        if level < last_level:
            for i in range(level, last_level):
                if i not in self.positions:
                    self.buy(i, self.grid[i])
        elif level > last_level:
            for i in range(last_level, level):
                if i in self.positions:
                    self.sell(i, self.grid[i + 1])

        self.last_price = price
        self._save()


# ---- LOOP ----
def run_bot(bot_name: str, cfg: dict):
    bot = GridBot(bot_name, cfg)
    if bot.stopped:
        bot.log.warning("Bot detenido por stop-loss previo. Reinicia manualmente si querés continuar.")
        return

    bot.log.info("Bot corriendo... Ctrl+C para detener.")
    cycle = 0
    consecutive_errors = 0
    MAX_ERRORS    = 10          # errores consecutivos antes de esperar mas
    BACKOFF_BASE  = 10          # segundos base de espera
    BACKOFF_MAX   = 300         # maximo 5 minutos entre reintentos

    try:
        while True:
            if bot.stopped:
                bot.log.warning("Stop-loss activado. Bot detenido.")
                break
            cycle += 1
            try:
                price = get_price(bot.symbol)
                if consecutive_errors > 0:
                    bot.log.info(f"Conexion restaurada despues de {consecutive_errors} errores.")
                consecutive_errors = 0
                bot.tick(price)
                if cycle % 6 == 0:
                    bot.print_status(price)
                time.sleep(CHECK_INTERVAL_SECONDS)

            except requests.RequestException as e:
                consecutive_errors += 1
                # Backoff exponencial: 10s, 20s, 40s... hasta 300s
                wait = min(BACKOFF_BASE * (2 ** (consecutive_errors - 1)), BACKOFF_MAX)
                if consecutive_errors <= MAX_ERRORS:
                    bot.log.warning(f"Error de red ({consecutive_errors}): {e}. Reintentando en {wait}s...")
                elif consecutive_errors == MAX_ERRORS + 1:
                    bot.log.error(f"Red caida. Esperando hasta {BACKOFF_MAX}s entre reintentos. Continuando silenciosamente...")
                    tg.send(f"⚠️ <b>[{bot.bot_name}] RED CAIDA</b>\n{consecutive_errors} errores consecutivos. Reintentando cada {BACKOFF_MAX}s.")
                time.sleep(wait)

    except KeyboardInterrupt:
        bot.log.info("Bot detenido por usuario.")
        try:
            price = get_price(bot.symbol)
            bot.print_status(price)
        except requests.RequestException:
            bot.log.warning("No se pudo obtener precio final (sin red).")
