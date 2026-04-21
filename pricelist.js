/**
 * pricelist.js — Генератор прайса СплитХаб
 * Форматы: PDF (print) / Excel (SheetJS)
 * Группировка: Бренд → Серия
 */

/* ── Группировка товаров ── */
function _groupProducts() {
  const grouped = {};
  PRODUCTS.forEach(p => {
    const brand  = p.brand  || 'Без бренда';
    const series = p.series || 'Без серии';
    if (!grouped[brand])         grouped[brand] = {};
    if (!grouped[brand][series]) grouped[brand][series] = [];
    grouped[brand][series].push(p);
  });
  return grouped;
}

/* ── Модальное окно: выбор формата ── */
function openPricelistModal() {
  document.body.insertAdjacentHTML('beforeend', `
<div id="priceModal" onclick="if(event.target===this)closePriceModal()"
  style="position:fixed;inset:0;background:rgba(10,14,26,0.55);backdrop-filter:blur(8px);
         display:flex;align-items:center;justify-content:center;z-index:9999;">
  <div style="background:rgba(255,255,255,0.97);border-radius:20px;padding:28px 24px;
              max-width:360px;width:90%;box-shadow:0 24px 64px rgba(0,0,0,0.25);">
    <div style="font-family:'Unbounded',sans-serif;font-size:1rem;font-weight:700;margin-bottom:6px;">
      📥 Загрузить прайс
    </div>
    <p style="font-size:0.8rem;color:#6b7280;margin-bottom:20px;">
      Выберите формат файла:
    </p>
    <button onclick="downloadPriceExcel()"
      style="width:100%;padding:14px;margin-bottom:10px;
             background:linear-gradient(135deg,#1D6F42,#2E9E5F);
             color:#fff;border:none;border-radius:12px;
             font-weight:700;cursor:pointer;font-size:0.88rem;
             box-shadow:0 4px 14px rgba(29,111,66,0.35);
             display:flex;align-items:center;justify-content:center;gap:8px;">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
      Скачать Excel (.xlsx)
    </button>
    <button onclick="downloadPricePDF()"
      style="width:100%;padding:14px;margin-bottom:18px;
             background:linear-gradient(135deg,#c0392b,#e74c3c);
             color:#fff;border:none;border-radius:12px;
             font-weight:700;cursor:pointer;font-size:0.88rem;
             box-shadow:0 4px 14px rgba(192,57,43,0.35);
             display:flex;align-items:center;justify-content:center;gap:8px;">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
      Скачать PDF
    </button>
    <button onclick="closePriceModal()"
      style="width:100%;padding:11px;background:#F3F4F6;color:#374151;
             border:none;border-radius:12px;cursor:pointer;font-size:0.84rem;">
      Отмена
    </button>
  </div>
</div>`);
}

function closePriceModal() {
  const m = document.getElementById('priceModal');
  if (m) m.remove();
}

/* ── PDF ── */
function downloadPricePDF() {
  const w = window.open('', '', 'width=960,height=1200');
  w.document.write(_buildPriceHTML());
  w.document.close();
  setTimeout(() => { w.print(); }, 300);
  closePriceModal();
}

function _buildPriceHTML() {
  const grouped = _groupProducts();
  const date    = new Date().toLocaleDateString('ru-RU');
  let rowNum = 1;
  let body = '';

  Object.keys(grouped).sort().forEach(brand => {
    body += `<div class="brand-group">
      <div class="brand-title">${brand}</div>`;

    Object.keys(grouped[brand]).sort().forEach(series => {
      const items = grouped[brand][series];
      if (!items.length) return;
      const info = (items[0].benefits || []).slice(0, 3).join(' · ');

      body += `<div class="series-title"><strong>${series}</strong>${info ? ' — ' + info : ''}</div>
      <table><thead><tr>
        <th class="c-num">№</th><th class="c-id">ID</th>
        <th>Модель</th><th class="c-desc">Описание</th>
        <th class="c-price">Цена</th><th class="c-photo">Фото</th><th>Наличие</th>
      </tr></thead><tbody>`;

      items.forEach(p => {
        const fmt  = new Intl.NumberFormat('ru-RU').format(p.price);
        const sc   = p.stock === 'in_stock' ? 'ok' : p.stock.startsWith('days') ? 'warn' : 'no';
        const photo = p.photo ? `<img src="assets/img/products/${p.photo}" />` : '—';
        body += `<tr>
          <td class="c-num">${rowNum}</td>
          <td class="c-id">${p.id}</td>
          <td>${p.model}</td>
          <td class="c-desc">${p.descShort || ''}</td>
          <td class="c-price">${fmt} ₽</td>
          <td class="c-photo">${photo}</td>
          <td class="s-${sc}">${p.stockLabel}</td>
        </tr>`;
        rowNum++;
      });

      body += `</tbody></table>`;
    });
    body += `</div>`;
  });

  return `<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8">
<title>Прайс СплитХаб</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Arial,sans-serif;background:#fff;padding:20px;font-size:12px}
.wrap{max-width:960px;margin:0 auto}
.hdr{text-align:center;margin-bottom:28px}
.hdr h1{font-size:20px;margin-bottom:4px}
.hdr p{color:#888;font-size:12px}
.brand-group{margin-bottom:32px;page-break-inside:avoid}
.brand-title{background:#F59E0B;color:#fff;font-weight:700;font-size:14px;padding:10px 14px;border-radius:6px;margin-bottom:4px}
.series-title{background:#f5f5f5;border-left:3px solid #F59E0B;padding:6px 12px;margin-bottom:8px;font-size:11px;color:#555}
table{width:100%;border-collapse:collapse;margin-bottom:16px}
th{background:#f0f0f0;padding:8px 6px;text-align:left;font-size:11px;border-bottom:2px solid #ddd}
td{padding:8px 6px;border-bottom:1px solid #eee;vertical-align:middle}
.c-num{width:28px;text-align:center}
.c-id{width:60px;font-family:monospace;font-size:10px;color:#888}
.c-desc{font-size:10px;color:#666;max-width:160px}
.c-price{text-align:right;font-weight:700;color:#D97706;white-space:nowrap}
.c-photo{width:56px;text-align:center}
.c-photo img{width:50px;height:50px;object-fit:contain}
.s-ok{color:#10B981;font-weight:700}
.s-warn{color:#F59E0B;font-weight:700}
.s-no{color:#EF4444;font-weight:700}
@media print{body{padding:0}.brand-group{page-break-inside:avoid}}
</style></head>
<body><div class="wrap">
<div class="hdr">
  <h1>Прайс-лист СплитХаб</h1>
  <p>Оптовые кондиционеры для монтажников и B2B · Симферополь</p>
  <p style="margin-top:6px;color:#bbb">Дата: ${date} · Товаров: ${PRODUCTS.length}</p>
</div>
${body}
</div></body></html>`;
}

/* ── Excel (SheetJS) ── */
function downloadPriceExcel() {
  if (typeof XLSX === 'undefined') {
    alert('Библиотека Excel не загружена. Попробуйте обновить страницу.');
    return;
  }

  const grouped = _groupProducts();
  const wb = XLSX.utils.book_new();
  const rows = [];

  // Заголовок
  rows.push(['Прайс-лист СплитХаб', '', '', '', '', '']);
  rows.push([`Дата: ${new Date().toLocaleDateString('ru-RU')} · Товаров: ${PRODUCTS.length}`, '', '', '', '', '']);
  rows.push([]);

  // Шапка таблицы
  rows.push(['№', 'ID', 'Модель', 'Описание', 'Цена, ₽', 'Наличие']);

  let rowNum = 1;

  Object.keys(grouped).sort().forEach(brand => {
    // Заголовок бренда
    rows.push([brand, '', '', '', '', '']);

    Object.keys(grouped[brand]).sort().forEach(series => {
      const items = grouped[brand][series];
      if (!items.length) return;
      const info = (items[0].benefits || []).slice(0, 3).join(' · ');

      // Заголовок серии
      rows.push([`  ${series}${info ? ' — ' + info : ''}`, '', '', '', '', '']);

      items.forEach(p => {
        rows.push([
          rowNum,
          p.id,
          p.model,
          p.descShort || '',
          p.price,
          p.stockLabel,
        ]);
        rowNum++;
      });
    });

    rows.push([]); // пустая строка между брендами
  });

  const ws = XLSX.utils.aoa_to_sheet(rows);

  // Ширина столбцов
  ws['!cols'] = [
    { wch: 5  },  // №
    { wch: 8  },  // ID
    { wch: 40 },  // Модель
    { wch: 28 },  // Описание
    { wch: 12 },  // Цена
    { wch: 18 },  // Наличие
  ];

  XLSX.utils.book_append_sheet(wb, ws, 'Прайс');
  XLSX.writeFile(wb, `splithub-price-${new Date().toLocaleDateString('ru-RU').replace(/\./g, '-')}.xlsx`);

  closePriceModal();
}
