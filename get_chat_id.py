# get_chat_id.py
import requests

TOKEN = input("Pegá tu token aquí: ").strip()
r = requests.get(f"https://api.telegram.org/bot{TOKEN}/getUpdates")
data = r.json()

if not data["ok"]:
    print(f"Error: {data['description']}")
else:
    updates = data.get("result", [])
    if not updates:
        print("Sin mensajes. Mandá un mensaje al bot desde Telegram y volvé a correr.")
    else:
        for u in updates:
            chat = u["message"]["chat"]
            print(f"Chat ID: {chat['id']} | Nombre: {chat.get('first_name','')}")