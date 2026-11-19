import base64
import io
import json
import os
import secrets
import shutil
import subprocess
from pathlib import Path
from uuid import uuid4

import pytest
from PIL import Image

ROOT = Path(__file__).resolve().parents[1]
PHP_REQUEST = r"""
class TestRequestBody {
    public $context;
    private $offset = 0;

    public function stream_open($path, $mode, $options, &$openedPath) {
        return $path === 'php://input';
    }
    public function stream_read($count) {
        $chunk = substr($GLOBALS['requestBody'], $this->offset, $count);
        $this->offset += strlen($chunk);
        return $chunk;
    }
    public function stream_eof() { return $this->offset >= strlen($GLOBALS['requestBody']); }
    public function stream_stat() { return []; }
}
$requestBody = stream_get_contents(STDIN);
stream_wrapper_unregister('php');
stream_wrapper_register('php', TestRequestBody::class);
ob_start();
register_shutdown_function(static function () {
    $body = ob_get_clean();
    echo json_encode(['status' => http_response_code(), 'body' => json_decode($body, true)]);
});
if ($argv[1] === 'catalog') {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET['action'] = 'products_overrides_public';
    require 'api/admin.php';
}
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = $argv[2];
$_GET['action'] = $argv[1];
require 'api/product-release.php';
"""


@pytest.fixture
def local_site(tmp_path):
    php = shutil.which("php")
    if php is None:
        pytest.skip("PHP CLI is required")
    for name in ("api", "db", "lib"):
        (tmp_path / name).mkdir()
    for name in ("api/product-release.php", "api/admin.php", "db/init.php", "lib/catalog.php", "lib/product_release.php", "products.js"):
        shutil.copy2(ROOT / name, tmp_path / name)
    keys = {name: secrets.token_hex(32) for name in ("RITUAL_RELEASE_PRODUCER_KEY", "RITUAL_RELEASE_1C_KEY")}

    def call(action, body=None, role="producer"):
        auth = ""
        if role is not None:
            auth = "Bearer " + keys["RITUAL_RELEASE_1C_KEY" if role == "1c" else "RITUAL_RELEASE_PRODUCER_KEY"]
        response = subprocess.run(
            [php, "-d", "display_errors=stderr", "-r", PHP_REQUEST, action, auth],
            env={**os.environ, **keys}, cwd=tmp_path, input=json.dumps(body or {}).encode(),
            capture_output=True, timeout=10,
        )
        assert response.returncode == 0, response.stderr.decode(errors="replace")
        reply = json.loads(response.stdout)
        assert reply["body"] is not None, response.stderr.decode(errors="replace")
        return reply["status"], reply["body"]

    return call


def draft(**changes):
    with io.BytesIO() as image:
        Image.new("RGB", (64, 48), "white").save(image, "PNG")
        photo = base64.b64encode(image.getvalue()).decode()
    defaults = dict(
        release_id=str(uuid4()), name="Венок «Тихий сад»", height_cm=120, width_cm=65, shape="oval",
        price_rub=3200, photo_base64=photo,
    )
    return {**defaults, **changes}


def confirmation(release):
    return {
        "release_id": release["release_id"], "onec_id": release.get("onec_id") or str(uuid4()),
        "article": "ВН-42", "barcode": "5901234123457", "barcode_type": "ean13",
        "wholesale_price_rub": release.get("price_rub", 3200),
    }


def test_publish_once_after_confirmed_nomenclature(local_site):
    release = draft()
    receipt = confirmation(release)
    assert local_site("enqueue", release, None)[0] == 401
    assert local_site("enqueue", release)[1]["state"] == "waiting_1c"
    assert local_site("enqueue", {**release, "price_rub": 1})[0] == 409
    assert local_site("confirm", receipt)[0] == 401
    assert local_site("confirm", {**receipt, "barcode": "5901234123450"}, "1c")[0] == 422
    assert local_site("catalog")[1]["custom_products"] == []
    assert local_site("next", role="1c")[1]["job"]["photo_base64"] == release["photo_base64"]

    status, result = local_site("confirm", receipt, "1c")
    assert status == 200 and result["state"] == "published"
    assert local_site("confirm", receipt, "1c")[1] == result
    assert local_site("confirm", {**receipt, "article": "ВН-43"}, "1c")[0] == 409
    assert local_site("enqueue", release)[1] == result
    assert local_site("status", {"release_id": release["release_id"]})[1] == result
    assert local_site("next", role="1c")[1]["job"] is None
    assert result["label"]["barcode"] == receipt["barcode"]
    assert result["catalog_path"] == "/?p=" + result["product_id"]
    [product] = local_site("catalog")[1]["custom_products"]
    assert product["dimensions"] == "120 × 65 см"
    assert product["price"] == 3200
    assert product["stock"] == "on_request"
    assert product["stockLabel"] == "Под заказ"


def test_link_existing_product_and_reuse_its_site_id(local_site):
    release = draft(onec_id=str(uuid4()), site_sku="venok-avrora", label="regular")
    receipt = confirmation(release)
    assert local_site("enqueue", release)[0] == 200
    assert local_site("confirm", {**receipt, "onec_id": str(uuid4())}, "1c")[0] == 409
    assert local_site("confirm", receipt, "1c")[1]["product_id"] == "2001"
    catalog = local_site("catalog")[1]
    assert catalog["custom_products"] == []
    override = catalog["overrides"]["venok-avrora"]
    assert override["badge"] == "regular"
    assert override["stock_override"] is None
    assert override["price_override"] == 3200
    repeat = draft(onec_id=release["onec_id"], label="regular", price_rub=3500)
    assert local_site("enqueue", repeat)[0] == 200
    assert local_site("confirm", confirmation(repeat), "1c")[1]["product_id"] == "2001"
    assert local_site("catalog")[1]["overrides"]["venok-avrora"]["price_override"] == 3500


def test_te200_label_export_from_published_result(local_site, tmp_path):
    powershell = shutil.which("powershell.exe")
    core = Path(r"C:\LabelForge_TSC_Studio_PRO_TSC_TE200_1C\LabelForge.Core.cs")
    if powershell is None or not core.is_file():
        pytest.skip("LabelForge on Windows is required")
    release = draft(label_copies=2)
    assert local_site("enqueue", release)[0] == 200
    status, result = local_site("confirm", confirmation(release), "1c")
    assert status == 200
    job_path, export_path = tmp_path / "job.json", tmp_path / "wreath.prn"
    job_path.write_text(json.dumps(result, ensure_ascii=False), encoding="utf-8")
    process = subprocess.run(
        [powershell, "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "RemoteSigned", "-File",
         str(ROOT / "tools/print-wreath-label.ps1"),
         "-JobPath", str(job_path), "-ExportPath", str(export_path)],
        env={**os.environ, "TEMP": str(tmp_path), "TMP": str(tmp_path)}, capture_output=True, timeout=30,
    )
    assert process.returncode == 0, process.stderr.decode(errors="replace")
    raw = export_path.read_bytes()
    assert raw.startswith(b"SIZE 70 mm,120 mm\r\nGAP 3 mm,0 mm")
    assert raw.count(b"BITMAP ") == 3
    assert b'"EAN13",152,1,0,3,3,"590123412345"\r\n' in raw
    assert raw.endswith(b"PRINT 2,1\r\n")


def test_ordered_queue_and_manual_wholesale_price(local_site):
    first = draft(price_rub=1500)
    second = draft()
    second.pop("price_rub")
    for item in (first, second):
        assert local_site("enqueue", item)[0] == 200
    receipt = confirmation(first)
    receipt["onec_id"] = first["release_id"]
    receipt.pop("wholesale_price_rub")
    assert local_site("confirm", receipt, "1c")[0] == 422
    assert local_site("wait_price", receipt, "producer")[0] == 401
    assert local_site("wait_price", receipt, "1c")[1]["state"] == "waiting_price"
    assert local_site("status", {"release_id": first["release_id"]})[1]["state"] == "waiting_price"
    assert local_site("next", {"after_id": first["release_id"]}, "1c")[1]["job"]["release_id"] == second["release_id"]
    assert local_site("catalog")[1]["custom_products"] == []
    receipt["wholesale_price_rub"] = 2600
    published = local_site("confirm", receipt, "1c")[1]
    assert published["wholesale_price_rub"] == 2600
    assert local_site("confirm", receipt, "1c")[1] == published
    assert local_site("catalog")[1]["custom_products"][0]["price"] == 2600
