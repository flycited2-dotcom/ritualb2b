"""Adapters for the office photo worker, the private Drive folder and RitualB2B."""
import base64
import hashlib
import json
import os
import sqlite3
import uuid
from pathlib import Path

import requests
from PIL import Image


def site_call(action, body):
    response = requests.post(os.environ["RITUAL_RELEASE_URL"], params={"action": action},
                             headers={"Authorization": "Bearer " + os.environ["RITUAL_RELEASE_PRODUCER_KEY"]},
                             json=body, timeout=(8, 45), allow_redirects=False)
    response.raise_for_status()
    result = response.json()
    if result.get("ok") is not True:
        raise ValueError("Сайт не подтвердил заявку")
    return result


def photo_result(draft, original):
    """Use the installed worker queue on its host. Only the release bot delivers this job."""
    queue = Path(os.environ["WREATH_PHOTO_DB"]).resolve()
    inputs = Path(os.environ["WREATH_PHOTO_INPUT"])
    outputs = Path(os.environ["WREATH_PHOTO_OUTPUT"])
    key = "wreathrelease-" + draft["id"]
    name = key + ".jpg"
    with sqlite3.connect(queue.as_uri() + "?mode=rw", uri=True, timeout=10) as db:
        db.row_factory = sqlite3.Row
        db.execute("BEGIN IMMEDIATE")
        row = db.execute("SELECT status, output_filename FROM jobs WHERE request_key=?", (key,)).fetchone()
        if row is None:
            # Commit the complete input before making the job visible to a worker.
            temporary = inputs / (name + ".tmp")
            temporary.write_bytes(original.read_bytes())
            temporary.replace(inputs / name)
            specs = f'Название: {draft["name"]}\nРазмер: {draft["height_cm"]} × {draft["width_cm"]} см. Без цены.'
            db.execute("""INSERT INTO jobs (chat_id, input_filename, mode, specs, request_key, result_sent)
                          VALUES (?, ?, 'wreath', ?, ?, 1)""", (draft["chat"], name, specs, key))
            return None
    if row["status"] in ("failed", "cancelled"):
        raise ValueError("Фотоагент завершил задание с ошибкой. Можно отменить заявку и повторить.")
    if row["status"] != "done":
        return None
    filename = row["output_filename"]
    if not filename or Path(filename).name != filename:
        raise ValueError("Фотоагент не вернул имя готовой фотографии")
    return outputs / filename


def catalog_photo(source, destination):
    """Keep the worker's full result in the archive; fit a copy into the catalog API."""
    with Image.open(source) as image:
        image.thumbnail((3200, 3200))
        image = image.convert("RGB")
        for quality in (92, 85, 75):
            image.save(destination, "JPEG", quality=quality, optimize=True)
            if destination.stat().st_size <= 6 * 1024 ** 2:
                return
    raise ValueError("Готовая фотография превышает лимит каталога")


def drive_client():
    from google.oauth2.credentials import Credentials
    from google.auth.transport.requests import AuthorizedSession
    credentials = Credentials.from_authorized_user_file(os.environ["WREATH_DRIVE_TOKEN_FILE"])
    return AuthorizedSession(credentials)


def allocate_drive_ids(service):
    response = service.get("https://www.googleapis.com/drive/v3/files/generateIds",
                           params={"count": 2, "space": "drive"}, timeout=20)
    response.raise_for_status()
    return response.json()["ids"]


def archive_photo(service, file_id, path, name, mime):
    folder = os.environ["WREATH_DRIVE_FOLDER"]
    params = {"fields": "id,parents,md5Checksum,trashed", "supportsAllDrives": "true"}
    data = path.read_bytes()
    digest = hashlib.md5(data).hexdigest()
    boundary = uuid.uuid4().hex
    marker = ("--" + boundary).encode()
    metadata = json.dumps({"id": file_id, "parents": [folder], "name": name}).encode()
    body = b"\r\n".join([marker, b"Content-Type: application/json; charset=UTF-8", b"", metadata,
                          marker, ("Content-Type: " + mime).encode(), b"", data, marker + b"--", b""])
    response = service.post("https://www.googleapis.com/upload/drive/v3/files",
                            params={"uploadType": "multipart", **params},
                            headers={"Content-Type": "multipart/related; boundary=" + boundary}, data=body, timeout=75)
    if response.status_code == 409:
        response = service.get("https://www.googleapis.com/drive/v3/files/" + file_id, params=params, timeout=20)
    response.raise_for_status()
    record = response.json()
    if record.get("trashed") or folder not in record.get("parents", []) or record.get("md5Checksum") != digest:
        raise ValueError("Фотобанк не подтвердил сохранение фотографии")


def submit_release(draft, image):
    fields = ("name", "height_cm", "width_cm", "label", "onec_id", "site_sku", "price_rub")
    request = {k: draft[k] for k in fields if draft.get(k) is not None}
    request.update(release_id=draft["id"], photo_base64=base64.b64encode(image.read_bytes()).decode("ascii"))
    return site_call("enqueue", request)
