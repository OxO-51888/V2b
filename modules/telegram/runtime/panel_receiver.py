"""Panel-owned shared receiver. Only this process may consume the shared bot token."""
import fcntl
import json
import os
from pathlib import Path
import bot
from panel_admin import PanelAdmin, handler_for


def integrated_handler(service, legacy):
    api = handler_for(service)
    prefix = '/panel-modules/' + service.site

    class Handler(legacy):
        def handle_module(self):
            if not self.path.startswith(prefix + '/'):
                return False
            original = self.path
            self.path = original[len(prefix):]
            try:
                api.handle_request(self)
            finally:
                self.path = original
            return True

        reply = api.reply

        def do_GET(self):
            if not self.handle_module():
                super().do_GET()

        def do_POST(self):
            if not self.handle_module():
                super().do_POST()

    return Handler


def main():
    data = Path(os.environ['TG_DATA_DIR']).resolve(strict=True)
    lock = (data / 'panel-receiver.lock').open('a')
    try:
        fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
    except Exception:
        lock.close()
        raise
    bot.BASE_DIR = data
    bot.STATE_FILE = data / 'state.json'
    bot.APP_DB_FILE = data / 'bot_admin.sqlite3'
    if os.environ.get('TG_PANEL_LOCAL') == 'true':
        os.environ['WEB_ADMIN_ENABLE'] = 'false'
        os.environ['BOT_ADMIN_DB'] = str(bot.APP_DB_FILE)
        os.environ['PANEL_EMAIL_BINDING_SITES_FILE'] = str(data / 'panel-sites.json')
    else:
        config = json.loads(Path(os.environ['TG_PANEL_ADMIN_CONFIG']).read_text(encoding='utf-8'))
        service = PanelAdmin(config)
        bot.AdminHandler = integrated_handler(service, bot.AdminHandler)
    try:
        if os.environ.get('TG_PANEL_LOCAL') == 'true':
            from panel_supervisor import run
            run(data)
        else:
            bot.main()
    finally:
        lock.close()


if __name__ == '__main__':
    main()
