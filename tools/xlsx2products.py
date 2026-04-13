#!/usr/bin/env python3
"""
xlsx2products.py — Конвертер xlsx-шаблона в products.js для СплитХаб.

Использование:
  python tools/xlsx2products.py splithub_master_template_clean.xlsx

Результат:
  products.js в текущей директории (перезаписывается)
"""
import pandas as pd
import json
import re
import sys
import os

BRAND_CODE_MAP = {
    'ELYSIUM': 'uel',
    'ELYSIUM NERO': 'uel',
    'ECLIPSE': 'uec',
    'TURBOCOOL': 'xt',
    'XG-JP': 'xj',
    'CALLISTO': 'ec',
    'ASTRID': 'ea',
    'ICE': 'dai',
    'RK-SCDG': 'dan',
    'DAIJIN': 'fun',
    'DUAL': 'lg',
    'APUS': 'alfa',
}

def get_brand_code(series):
    s = str(series).strip()
    s_clean = re.sub(r'\s*(inverter|INVERTOR|inv|on/?off)\s*$', '', s, flags=re.IGNORECASE).strip()
    if s_clean in BRAND_CODE_MAP:
        return BRAND_CODE_MAP[s_clean]
    for key, code in BRAND_CODE_MAP.items():
        if key.upper() in s_clean.upper():
            return code
    return 'wr'

def clean_series(series):
    s = str(series).strip()
    return re.sub(r'\s*(inverter|INVERTOR|inv)\s*$', '', s, flags=re.IGNORECASE).strip()

def convert(xlsx_path, output_path='products.js'):
    df = pd.read_excel(xlsx_path, 'PRODUCTS')
    df = df[df['active'] == 1.0].copy()

    if df.empty:
        print('No active products found!')
        sys.exit(1)

    products = []
    for _, r in df.iterrows():
        btu_raw = r['btu']
        btu_str = str(int(btu_raw)).zfill(2) if pd.notna(btu_raw) and str(btu_raw) != '-' else '-'

        freon = str(r['freon']) if pd.notna(r['freon']) else 'R32'
        if freon in ('R33', 'R34', 'R35', 'R36'):
            freon = 'R32'

        benefits = []
        mb = str(r['modal_benefits']) if pd.notna(r['modal_benefits']) else ''
        if mb and mb != 'nan':
            benefits = [b.strip() for b in mb.split('|') if b.strip()]

        group = str(r['catalog_group']).strip() if pd.notna(r['catalog_group']) else 'inv'
        desc = str(r['description_short']).strip() if pd.notna(r['description_short']) else ''
        desc = re.sub(r'\s+', ' ', desc)

        products.append({
            'id': str(int(r['id'])),
            'sku': str(r['sku']).strip(),
            'brandCode': get_brand_code(r['series']),
            'brand': str(r['brand_name']).strip(),
            'series': clean_series(r['series']),
            'model': str(r['model']).strip(),
            'group': group,
            'btu': btu_str,
            'area': int(r['area_m2']) if pd.notna(r['area_m2']) else 0,
            'price': int(r['price']),
            'stock': str(r['stock_status']).strip() if pd.notna(r['stock_status']) else 'in_stock',
            'stockLabel': str(r['stock_label']).strip() if pd.notna(r['stock_label']) else '',
            'descShort': desc,
            'cardBenef': str(r['card_benefits']).strip() if pd.notna(r['card_benefits']) else '',
            'benefits': benefits,
            'compressor': str(r['compressor']).strip() if pd.notna(r['compressor']) else '',
            'freon': freon,
            'photo': str(r['photo']).strip() if pd.notna(r['photo']) else ''
        })

    js = 'const PRODUCTS = ' + json.dumps(products, ensure_ascii=False, indent=2) + ';\n'

    with open(output_path, 'w', encoding='utf-8') as f:
        f.write(js)

    groups = {}
    for p in products:
        g = p['group']
        groups[g] = groups.get(g, 0) + 1

    print(f'Готово: {len(products)} товаров -> {output_path}')
    print(f'Группы: {groups}')
    print(f'Бренды: {len(set(p["brand"] for p in products))}')
    freons = set(p['freon'] for p in products)
    if freons - {'R32', '-'}:
        print(f'ВНИМАНИЕ: нестандартные фреоны: {freons - {"R32", "-"}}')

if __name__ == '__main__':
    if len(sys.argv) < 2:
        print(f'Использование: python {sys.argv[0]} <path_to_xlsx> [output.js]')
        sys.exit(1)
    xlsx = sys.argv[1]
    out = sys.argv[2] if len(sys.argv) > 2 else 'products.js'
    convert(xlsx, out)
