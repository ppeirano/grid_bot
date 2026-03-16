"""
Script para subir los logs del bot a GitHub.
Copia los .log como .txt (evita .gitignore) y hace push.

Uso:
    python upload_logs.py
"""

import subprocess
import shutil
from pathlib import Path
from datetime import datetime

REPO_DIR = Path(__file__).parent
LOG_FILES = {
    "btc_bot.log": "logs_btc.txt",
    "xrp_bot.log": "logs_xrp.txt",
}


def run(cmd):
    result = subprocess.run(cmd, cwd=REPO_DIR, capture_output=True, text=True)
    if result.returncode != 0:
        print(f"  Error: {result.stderr.strip()}")
    return result.returncode == 0


def main():
    copied = []
    for log_name, txt_name in LOG_FILES.items():
        src = REPO_DIR / log_name
        dst = REPO_DIR / txt_name
        if src.exists():
            shutil.copy2(src, dst)
            copied.append(txt_name)
            print(f"  Copiado: {log_name} -> {txt_name}")
        else:
            print(f"  No encontrado: {log_name} (saltando)")

    if not copied:
        print("No hay logs para subir.")
        return

    now = datetime.now().strftime("%Y-%m-%d %H:%M")

    for f in copied:
        run(["git", "add", f])

    if run(["git", "commit", "-m", f"Update logs {now}"]):
        if run(["git", "push"]):
            print(f"\nLogs subidos OK ({now})")
        else:
            print("\nCommit creado pero fallo el push. Intenta 'git push' manual.")
    else:
        print("\nSin cambios en los logs.")


if __name__ == "__main__":
    main()
