from database import init_db
from bot import run_bot
from config import BOT_CONFIGS

if __name__ == "__main__":
    init_db()
    run_bot("BTC", BOT_CONFIGS["BTC"])
