# test_telegram.py
import os
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

env = load_env(Path(__file__).parent / 'credentials.env')
token   = env.get('TELEGRAM_TOKEN', '')
chat_id = env.get('TELEGRAM_CHAT_ID', '')

print(f"Token:   {token[:20]}...")
print(f"Chat ID: {chat_id}")

import requests
r = requests.post(
    f"https://api.telegram.org/bot{token}/sendMessage",
    json={"chat_id": chat_id, "text": "Test desde Grid Bot ✅"}
)
print(f"Status: {r.status_code}")
print(r.json())