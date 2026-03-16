"""
Notificaciones Telegram - Grid Bot
"""
import requests
import logging

_token = None
_chat_id = None
_enabled = False

def init(token: str, chat_id: str):
    global _token, _chat_id, _enabled
    _token   = token.strip()
    _chat_id = chat_id.strip()
    _enabled = bool(_token and _chat_id)

def send(message: str, silent: bool = False):
    """Envía mensaje. silent=True para notificaciones sin sonido."""
    if not _enabled:
        return
    try:
        url = f"https://api.telegram.org/bot{_token}/sendMessage"
        requests.post(url, json={
            "chat_id": _chat_id,
            "text": message,
            "parse_mode": "HTML",
            "disable_notification": silent
        }, timeout=5)
    except Exception as e:
        logging.getLogger("telegram").warning(f"No se pudo enviar notificacion: {e}")
