import asyncio
import shutil
import sqlite3
import sys
from pathlib import Path
from unittest.mock import AsyncMock

import pytest

pytest.importorskip("aiogram")
from aiogram import Bot, Dispatcher, F, Router
from aiogram.types import Update
from PIL import Image

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "tools"))
from wreath_release import bot as intake, services
from test_product_release import local_site


def test_hotel_routing_and_release(tmp_path, monkeypatch, local_site):
    store = intake.Drafts(tmp_path / "drafts")
    dp = Dispatcher()
    intake.install(dp, store, {7})
    hotel = Router()
    hotel_events = []

    async def existing(message):
        hotel_events.append((message.chat.id, message.text or "photo"))

    hotel.message.register(existing, F.text | F.photo)
    dp.include_router(hotel)
    bot = Bot("12345:local-test")
    outgoing = AsyncMock()
    monkeypatch.setattr(bot.session, "make_request", outgoing)
    picture = next((ROOT / "assets/img/products").glob("venok_*.png"))

    async def download(_photo, destination):
        with Image.open(picture) as image:
            image.convert("RGB").save(destination)
    monkeypatch.setattr(bot, "download", download)
    queue = tmp_path / "queue.db"
    with sqlite3.connect(queue) as db:
        db.execute("CREATE TABLE jobs(chat_id, input_filename, mode, specs, request_key UNIQUE, result_sent, status DEFAULT 'pending',output_filename)")
    for key, value in {"WREATH_PHOTO_DB": queue, "WREATH_PHOTO_INPUT": tmp_path, "WREATH_PHOTO_OUTPUT": tmp_path,
                       "RITUAL_RELEASE_URL": "http://localhost/api/product-release.php"}.items():
        monkeypatch.setenv(key, str(value))
    backups = []
    monkeypatch.setattr(services, "drive_client", lambda: None)
    monkeypatch.setattr(services, "allocate_drive_ids", lambda service: ["source", "catalog"])
    monkeypatch.setattr(services, "archive_photo", lambda _, fid, path, *args: backups.append((fid, path.stat().st_size)))
    monkeypatch.setattr(services, "site_call", lambda action, body: local_site(action, body)[1])

    async def event(user, text=None, callback=None, photo=False):
        actor = {"id": user, "is_bot": False, "first_name": "Тест"}
        msg = {"from": actor, "message_id": 1, "date": 100, "chat": {"id": user, "type": "private"}}
        if text:
            msg["text"] = text
        if photo:
            msg["photo"] = [{"file_id": "img", "file_unique_id": "img", "width": 200, "height": 200}]
        if callback:
            update = {"callback_query": {"id": "1", "from": actor, "message": msg, "chat_instance": "1", "data": callback}}
        else:
            update = {"message": msg}
        await dp.feed_update(bot, Update.model_validate({"update_id": 1, **update}, context={"bot": bot}))

    async def run():
        await event(99, "/venok")
        await event(7, "/start")
        await event(7, photo=True)
        assert store.active(99) is None and store.active(7) is None
        await event(7, "/venok")
        await event(7, photo=True)
        d = store.active(7)
        assert len(d["names"]) == 5
        key = "w:" + d["id"] + ":"
        await event(99, callback=key + "1")
        assert store.active(7)["stage"] == "name"
        await event(7, callback=key + "1")
        await event(7, "120 × 65")
        await event(7, callback=key + "later")
        await event(7, callback=key + "new")
        d = store.active(7)
        await intake.advance(bot, store, d)
        await intake.advance(bot, store, d)
        with sqlite3.connect(queue) as db:
            [job] = db.execute("SELECT mode,specs,result_sent FROM jobs").fetchall()
            assert job[0] == "wreath" and "120 × 65" in job[1] and job[2] == 1
            shutil.copyfile(picture, tmp_path / "ready.png")
            db.execute("UPDATE jobs SET status='done',output_filename='ready.png'")
        await intake.advance(bot, store, d)
        assert store.active(7)["stage"] == "preview" and not backups
        await event(7, callback=key + "approve")
        d = store.active(7)
        await intake.advance(bot, store, d)
        await intake.advance(bot, store, d)
        assert [b[0] for b in backups] == ["source", "catalog"]
        assert local_site("next", role="1c")[1]["job"]["price_rub"] is None
        receipt = dict(release_id=d["id"], onec_id=d["id"], article="ВН-ТЕСТ", barcode="5901234123457", barcode_type="ean13")
        local_site("wait_price", receipt, "1c")
        await intake.advance(bot, store, d)
        assert d["stage"] == "waiting_price"
        local_site("confirm", {**receipt, "wholesale_price_rub": 2600}, "1c")
        await intake.advance(bot, store, d)
        assert d["stage"] == "published"
        assert (7, "/start") in hotel_events and (7, "photo") in hotel_events
        assert outgoing.await_count > 7
        await bot.session.close()

    asyncio.run(run())
