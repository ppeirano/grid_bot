"""
reset_db.py — Limpia la base de datos para empezar de cero
Uso: py reset_db.py
     py reset_db.py --bot XRP    (solo un bot)
     py reset_db.py --all        (todos los bots, confirma antes)
"""

import argparse
import sys
from pathlib import Path

def load_env(path):
    env = {}
    for line in open(path):
        line = line.strip()
        if not line or line.startswith('#') or '=' not in line:
            continue
        k, _, v = line.partition('=')
        env[k.strip()] = v.strip()
    return env

def main():
    parser = argparse.ArgumentParser(description="Limpia la BD del grid bot")
    parser.add_argument("--bot", help="Nombre del bot a limpiar (XRP, BTC, etc.)")
    parser.add_argument("--all", action="store_true", help="Limpiar todos los bots")
    args = parser.parse_args()

    if not args.bot and not args.all:
        parser.print_help()
        print("\nEjemplos:")
        print("  py reset_db.py --bot BTC")
        print("  py reset_db.py --bot XRP")
        print("  py reset_db.py --all")
        sys.exit(0)

    env = load_env(Path(__file__).parent / 'credentials.env')

    try:
        import mysql.connector
        conn = mysql.connector.connect(
            host=env['DB_HOST'], port=int(env.get('DB_PORT', 3306)),
            user=env['DB_USER'], password=env['DB_PASSWORD'],
            database=env['DB_NAME']
        )
        cursor = conn.cursor()

        if args.all:
            cursor.execute("SELECT bot_name FROM bots")
            bots = [r[0] for r in cursor.fetchall()]
            if not bots:
                print("No hay bots registrados.")
                return
            print(f"Bots a limpiar: {', '.join(bots)}")
            confirm = input("Esto borra TODOS los trades, precios y estado. Escribí CONFIRMAR: ")
            if confirm != "CONFIRMAR":
                print("Cancelado.")
                return
            bot_list = bots
        else:
            bot_list = [args.bot.upper()]
            confirm = input(f"Limpiar {args.bot.upper()}? Borra trades, precios y estado. (s/n): ")
            if confirm.lower() != 's':
                print("Cancelado.")
                return

        for bot in bot_list:
            print(f"\nLimpiando {bot}...")

            cursor.execute("DELETE FROM trades WHERE bot_name = %s", (bot,))
            print(f"  Trades eliminados: {cursor.rowcount}")

            cursor.execute("DELETE FROM prices WHERE bot_name = %s", (bot,))
            print(f"  Precios eliminados: {cursor.rowcount}")

            cursor.execute("DELETE FROM bot_state WHERE bot_name = %s", (bot,))
            print(f"  Estado eliminado: {cursor.rowcount}")

            # Resetear capital a valor original de config
            cursor.execute("SELECT capital_usdt FROM bots WHERE bot_name = %s", (bot,))
            row = cursor.fetchone()
            if row:
                print(f"  Capital restaurado: ${row[0]}")

            conn.commit()
            print(f"  {bot} limpio OK")

        # Limpiar archivos de log
        import os
        for bot in bot_list:
            log_file = Path(__file__).parent / f"{bot.lower()}_bot.log"
            if log_file.exists():
                open(log_file, "w").close()
                print(f"  Log limpiado: {log_file.name}")

        print("\nListo. Podés arrancar los bots de cero.")
        cursor.close()
        conn.close()

    except Exception as e:
        print(f"Error: {e}")
        sys.exit(1)

if __name__ == "__main__":
    main()
