"""The private Telegram bot for preparing and approving a wreath release."""
import asyncio
import colorsys
import json
import logging
import os
import re
import shutil
import sqlite3
import time
import uuid
from pathlib import Path
from urllib.parse import urljoin

from PIL import Image
from aiogram import F, Router
from aiogram.filters import Command, StateFilter
from aiogram.types import FSInputFile, InlineKeyboardButton, InlineKeyboardMarkup

from . import services

log = logging.getLogger(__name__)


class Drafts:
    def __init__(self, directory):
        self.directory = Path(directory).resolve()
        self.directory.mkdir(parents=True, exist_ok=True)
        self.db = sqlite3.connect(self.directory / "releases.sqlite")
        self.db.execute("CREATE TABLE IF NOT EXISTS drafts(id TEXT PRIMARY KEY, chat INTEGER, stage TEXT, data TEXT)")
        self.db.execute("""CREATE UNIQUE INDEX IF NOT EXISTS active_draft ON drafts(chat)
            WHERE stage NOT IN ('submitted','waiting_price','published','cancelled')""")

    def rows(self, condition, args=()):
        return [json.loads(row[0]) for row in self.db.execute("SELECT data FROM drafts WHERE " + condition, args)]

    def active(self, chat):
        rows = self.rows("chat=? AND stage NOT IN ('submitted','waiting_price','published','cancelled')", (chat,))
        return rows[0] if rows else None

    def save(self, d, stage):
        record = dict(d, stage=stage)
        with self.db:
            if "stage" not in d:
                self.db.execute("INSERT INTO drafts VALUES (?,?,?,?)",
                                (d["id"], d["chat"], stage, json.dumps(record)))
            else:
                changed = self.db.execute("UPDATE drafts SET stage=?, data=? WHERE id=? AND stage=?",
                                          (stage, json.dumps(record), d["id"], d["stage"]))
                if not changed.rowcount:
                    return False
        d.update(record)
        return True

    def files(self, d):
        return self.directory / d["id"]


def names_for(photo):
    palettes = {
        "светлый": ["Светлая память", "Белая лилия", "Тихий рассвет", "Свет зари", "Нежность"],
        "тёплый": ["Янтарный свет", "Вечерняя заря", "Тихая осень", "Золотой луч", "Тепло памяти"],
        "красный": ["Багряная заря", "Сердце розы", "Вечный огонь", "Рубин", "Алая память"],
        "холодный": ["Вечерний свет", "Сиреневый сад", "Лунный свет", "Тихая даль", "Сумерки"],
    }
    with Image.open(photo) as image:
        image.thumbnail((96, 96))
        votes = dict.fromkeys(palettes, 0)
        rgb = image.convert("RGB")
        for r, g, b in (rgb.getpixel((x, y)) for y in range(rgb.height) for x in range(rgb.width)):
            h, s, v = colorsys.rgb_to_hsv(r / 255, g / 255, b / 255)
            if v < .4 or (.17 < h < .45 and s > .18):
                continue
            if s < .2:
                votes["светлый"] += .25
            elif h < .05 or h > .91:
                votes["красный"] += 1
            elif h < .17:
                votes["тёплый"] += 1
            else:
                votes["холодный"] += 1
    return palettes[max(votes, key=votes.get)]


def keyboard(d, actions):
    return InlineKeyboardMarkup(inline_keyboard=[[InlineKeyboardButton(text=label, callback_data="w:" + d["id"] + ":" + action)]
                                for label, action in actions])


async def start(message, state, wreath_store):
    if await state.get_state() is not None:
        await message.answer("Сначала завершите текущую гостиничную заявку или используйте её команду /cancel.")
        return
    d = wreath_store.active(message.chat.id)
    if d is None:
        d = {"id": str(uuid.uuid4()), "chat": message.chat.id}
        wreath_store.save(d, "original")
    if d["stage"] == "original":
        await message.answer("Пришлите фотографию венка. /venok_cancel — отменить, /venok_status — проверить выпуск.")
    elif d["stage"] == "preview":
        await preview(message.bot, wreath_store, d)
    elif d["stage"] == "name":
        await ask_name(message, d)
    elif d["stage"] == "label":
        await choose_label(message, wreath_store, d)
    else:
        await message.answer(STAGES[d["stage"]])


async def receive_photo(message, wreath_store):
    d = wreath_store.active(message.chat.id)
    folder = wreath_store.files(d)
    folder.mkdir(exist_ok=True)
    await message.bot.download(message.photo[-1], destination=folder / "original.jpg")
    d["names"] = names_for(folder / "original.jpg")
    wreath_store.save(d, "name")
    await ask_name(message, d)


async def ask_name(message, d):
    await message.answer("Выберите название или напишите своё.",
        reply_markup=keyboard(d, [(n, str(i)) for i, n in enumerate(d["names"])]))


async def set_name(message, store, d, name):
    if not 1 <= len(name) <= 80 or re.search(r"[<>\x00-\x1f]", name):
        await message.answer("Введите название длиной до 80 знаков.")
        return
    d["name"] = name
    store.save(d, "size")
    await message.answer("Укажите высоту и ширину в сантиметрах, например 120 × 65.")


async def receive_text(msg, wreath_store):
    store = wreath_store
    d = store.active(msg.chat.id)
    value = msg.text.strip()
    if d["stage"] == "name":
        await set_name(msg, store, d, value)
    elif d["stage"] == "size":
        match = re.fullmatch(r"(\d{1,3})\s*[×xх*]\s*(\d{1,3})(?:\s*см)?", value, re.I)
        if not match or any(not 1 <= int(n) <= 500 for n in match.groups()):
            await msg.answer("Введите два размера от 1 до 500 см, например 120 × 65.")
            return
        d["height_cm"], d["width_cm"] = map(int, match.groups())
        store.save(d, "price")
        await msg.answer("Введите предполагаемый опт в рублях или укажите его позже в УТ.",
                              reply_markup=keyboard(d, [("Укажу в 1С", "later")]))
    elif d["stage"] == "price":
        amount = value.replace(" ", "")
        if not re.fullmatch(r"[0-9]{1,8}", amount) or not 1 <= int(amount) <= 10000000:
            await msg.answer("Введите положительную цену в целых рублях.")
            return
        d["price_rub"] = int(amount)
        await choose_label(msg, store, d)
    else:
        await msg.answer("Для текущего шага используйте кнопки или /venok_status.")


async def choose_label(msg, store, d):
    store.save(d, "label")
    await msg.answer("Как показать товар на сайте?", reply_markup=keyboard(d, [
        ("Новинка", "new"), ("Регулярный ассортимент", "regular")]))


async def click(q, wreath_store):
    store = wreath_store
    d = store.active(q.message.chat.id)
    _, item, action = q.data.split(":", 2)
    if not d or d["id"] != item:
        await q.answer("Эта кнопка уже неактуальна.")
        return
    await q.answer()
    if d["stage"] == "name" and action in ("0","1","2","3","4"):
        await set_name(q.message, store, d, d["names"][int(action)])
    elif d["stage"] == "price" and action == "later":
        await choose_label(q.message, store, d)
    elif d["stage"] == "label" and action in ("new", "regular"):
        d["label"] = action
        store.save(d, "photo")
        await q.message.answer("Заявка на обработку принята. Покажу готовую фотографию для подтверждения.")
    elif d["stage"] == "preview" and action == "approve":
        store.save(d, "archive")
        await q.message.answer("Фото подтверждено. Сохраняю оригинал и результат в фотобанк.")


async def link(message, command, wreath_store):
    store = wreath_store
    d = store.active(message.chat.id)
    if not d or d["stage"] not in ("name","size","price","label","photo","preview"):
        return
    args = (command.args or "").split()
    if not 1 <= len(args) <= 2:
        await message.answer("Для существующей позиции: /venok_link UUID_номенклатуры [артикул_сайта]")
        return
    try:
        onec = uuid.UUID(args[0])
        if not onec.int:
            raise ValueError()
    except ValueError:
        await message.answer("Нужен UUID ссылки из 1С.")
        return
    sku = args[1] if len(args) > 1 else None
    if sku and not re.fullmatch(r"[\w.-]{1,80}", sku):
        await message.answer("Проверьте артикул сайта.")
        return
    d.update(onec_id=str(onec), site_sku=sku)
    store.save(d, d["stage"])
    await message.answer("Привязка к существующей позиции сохранена.")


async def cancel(message, wreath_store):
    store = wreath_store
    d = store.active(message.chat.id)
    if d and d["stage"] not in ("archive", "enqueue"):
        store.save(d, "cancelled")
        await message.answer("Черновик отменён. Новый венок — /venok.")
    else:
        await message.answer("Черновика для отмены нет.")


STAGES = dict(original="ожидает фото", name="выбор названия", size="ввод высоты и ширины", price="ввод ориентира цены или кнопка «Укажу в 1С»", label="выбор метки",
              photo="у фотоагента", preview="ожидает подтверждения фото", archive="сохранение в фотобанк",
              enqueue="передача в очередь УТ", submitted="в очереди УТ", waiting_price="в УТ, ожидает цены «Опт»",
              published="опубликован", failed="ошибка обработки фото, доступна /venok_cancel")


async def status(message, wreath_store):
    rows = wreath_store.rows("chat=? AND stage != 'cancelled' ORDER BY rowid DESC LIMIT 5", (message.chat.id,))
    await message.answer("\n".join(f'{d.get("name", "Новый венок")} — {d.get("notice") or STAGES[d["stage"]]}' for d in rows)
                         or "Заявок пока нет. Начните с /venok.")


async def preview(bot, store, d):
    await bot.send_photo(d["chat"], FSInputFile(store.files(d) / "catalog.jpg"),
                         caption=f'{d["name"]}, {d["height_cm"]} × {d["width_cm"]} см',
                         reply_markup=keyboard(d, [("Подтвердить фото", "approve")]))


async def advance(bot, store, d):
    folder = store.files(d)
    original, ready = folder / "original.jpg", folder / "catalog.jpg"
    stage = d["stage"]
    if stage == "photo":
        result = await asyncio.to_thread(services.photo_result, d, original)
        if result is None:
            return
        if result.suffix.lower() != ".png":
            raise ValueError("Ожидался результат фотоагента в PNG.")
        await asyncio.to_thread(shutil.copyfile, result, folder / "result.png")
        await asyncio.to_thread(services.catalog_photo, folder / "result.png", ready)
        if not store.save(d, "preview"):
            return
        try:
            await preview(bot, store, d)
        except Exception:
            store.save(d, "photo")
            raise
    elif stage == "archive":
        drive = await asyncio.to_thread(services.drive_client)
        if "drive_ids" not in d:
            d["drive_ids"] = await asyncio.to_thread(services.allocate_drive_ids, drive)
            store.save(d, "archive")
        for file_id, path, mime in zip(d["drive_ids"], [original, folder / "result.png"], ["image/jpeg", "image/png"]):
            await asyncio.to_thread(services.archive_photo, drive, file_id, path, d["id"] + "-" + path.name, mime)
        d.pop("notice", None)
        d.pop("retry_at", None)
        store.save(d, "enqueue")
    elif stage == "enqueue":
        await asyncio.to_thread(services.submit_release, d, ready)
        store.save(d, "submitted")
    else:
        result = await asyncio.to_thread(services.site_call, "status", {"release_id": d["id"]})
        if result["state"] == "waiting_price" and stage != "waiting_price":
            await bot.send_message(d["chat"], "Карточка в УТ готова. Установите цену «Опт» для публикации.")
            store.save(d, "waiting_price")
        elif result["state"] == "published":
            url = urljoin(os.environ["RITUAL_RELEASE_URL"], result["catalog_path"])
            await bot.send_message(d["chat"], f'Опубликовано: {url}\nОпт: {result["wholesale_price_rub"]} ₽. '
                                   f'Штрихкод: {result["label"]["barcode"]}')
            store.save(d, "published")


async def work(bot, store):
    while True:
        for d in store.rows("stage IN ('photo','archive','enqueue','submitted','waiting_price')"):
            if d.get("retry_at", 0) > time.time():
                continue
            try:
                await advance(bot, store, d)
            except ValueError as error:
                if d["stage"] == "photo":
                    if store.save(d, "failed"):
                        await bot.send_message(d["chat"], str(error))
                else:
                    log.warning("Заявка %s: %s", d["id"], type(error).__name__)
            except Exception as error:
                log.warning("Повтор заявки %s на шаге %s: %s", d["id"], d["stage"], type(error).__name__)
                if d["stage"] == "archive":
                    first = not d.get("notice")
                    d["notice"] = "ожидает сохранения в Google Диск. Оба файла сохранены на сервере."
                    d["retry_at"] = time.time() + 300
                    store.save(d, "archive")
                    if first:
                        try:
                            await bot.send_message(d["chat"], d["notice"])
                        except Exception as notify_error:
                            log.warning("Не доставлен статус %s: %s", d["id"], type(notify_error).__name__)
        await asyncio.sleep(10)


def install(dispatcher, store, staff):
    """Mount before the hotel routers on their existing Dispatcher."""
    router = Router(name="wreath_release")
    router.message.filter(F.chat.type == "private", F.from_user.id.in_(staff))
    router.callback_query.filter(F.message.chat.type == "private", F.from_user.id.in_(staff))
    dispatcher["wreath_store"] = store

    def at(*stages):
        async def in_stage(message):
            draft = store.active(message.chat.id)
            return draft is not None and draft["stage"] in stages
        return in_stage

    for name, handler in (("venok", start), ("venok_status", status), ("venok_link", link), ("venok_cancel", cancel)):
        router.message.register(handler, Command(name))
    router.message.register(receive_photo, StateFilter(None), F.photo, at("original"))
    router.message.register(receive_text, StateFilter(None), F.text, ~F.text.startswith("/"), at("name","size","price"))
    router.callback_query.register(click, F.data.startswith("w:"))
    dispatcher.include_router(router)
    task = None

    async def opened(bot):
        nonlocal task
        task = asyncio.create_task(work(bot, store))

    async def stopped():
        if task:
            task.cancel()
            try:
                await task
            except asyncio.CancelledError:
                pass

    router.startup.register(opened)
    router.shutdown.register(stopped)
