#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
本地 PDF 封面提取脚本（适配 scripts/ 子目录）
- 自动定位仓库根目录（脚本上级目录）
- 支持 --retry 参数：只重试 failed_covers.txt 里的失败项
- 增量跳过已存在的封面
- 临时 PDF 下载后自动删除

依赖: pip install PyMuPDF requests tqdm
"""

import argparse
import json
import os
import re
import sys
import tempfile
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor, as_completed

import requests
from tqdm import tqdm

# ==================== 路径配置 ====================
SCRIPT_DIR = Path(__file__).resolve().parent
BASE_DIR = SCRIPT_DIR.parent

LIST_JSON_PATH = BASE_DIR / "list.json"
COVERS_DIR = BASE_DIR / "covers"
FAILED_LOG = BASE_DIR / "failed_covers.txt"

MAX_WORKERS = 4
DPI = 150
MAX_EDGE = 1200
JPEG_QUALITY = 85
# ================================================

_ILLEGAL_CHARS = re.compile(r'[<>:\"/\\|?*\x00-\x1f]')


def sanitize(name: str) -> str:
    return _ILLEGAL_CHARS.sub('_', name).strip(' .')


def get_cover_path(file_path: str) -> Path:
    parts = [sanitize(p) for p in file_path.split('/')]
    parts = [p for p in parts if p]
    if not parts:
        parts = ["unknown"]
    rel = "/".join(parts)
    p = COVERS_DIR / rel
    return p.with_suffix('.jpg')


def download_file(item: dict, dest: Path) -> bool:
    try:
        headers = {
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"
        }
        with open(dest, 'wb') as f:
            if item.get('is_chunked'):
                chunks = sorted(item['chunks'], key=lambda x: x['index'])
                for chunk in chunks:
                    r = requests.get(chunk['url'], stream=True, headers=headers, timeout=120)
                    r.raise_for_status()
                    for data in r.iter_content(chunk_size=65536):
                        if data:
                            f.write(data)
            else:
                r = requests.get(item['url'], stream=True, headers=headers, timeout=120)
                r.raise_for_status()
                for data in r.iter_content(chunk_size=65536):
                    if data:
                        f.write(data)
        return True
    except Exception as e:
        print(f"\n  [下载失败] {item['path']}: {e}")
        return False


def extract_cover(pdf_path: Path, cover_path: Path) -> bool:
    try:
        import fitz
        doc = fitz.open(str(pdf_path))
        if doc.page_count == 0:
            doc.close()
            return False

        page = doc.load_page(0)
        rect = page.rect
        max_dim = max(rect.width, rect.height)
        zoom = min(MAX_EDGE / max_dim, DPI / 72.0)
        mat = fitz.Matrix(zoom, zoom)
        pix = page.get_pixmap(matrix=mat)

        if pix.n > 4:
            pix = fitz.Pixmap(fitz.csRGB, pix)

        cover_path.parent.mkdir(parents=True, exist_ok=True)
        pix.save(str(cover_path), jpg_quality=JPEG_QUALITY)
        doc.close()
        return True
    except Exception as e:
        print(f"\n  [提取失败] {pdf_path.name}: {e}")
        return False


def process_one(item: dict) -> tuple:
    path = item.get('path', '')
    if not path.lower().endswith('.pdf'):
        return (path, None)

    cover_path = get_cover_path(path)

    # 增量跳过（即使是重试模式，如果封面已存在也跳过）
    if cover_path.exists():
        rel = str(cover_path.relative_to(BASE_DIR)).replace("\\", "/")
        return (path, rel)

    fd, tmp_str = tempfile.mkstemp(suffix='.pdf', prefix='cover_tmp_')
    os.close(fd)
    tmp_path = Path(tmp_str)

    try:
        ok = download_file(item, tmp_path)
        if not ok or tmp_path.stat().st_size < 1024:
            return (path, None)

        ok = extract_cover(tmp_path, cover_path)
        if ok:
            rel = str(cover_path.relative_to(BASE_DIR)).replace("\\", "/")
            return (path, rel)
        return (path, None)
    finally:
        if tmp_path.exists():
            tmp_path.unlink()


def main():
    parser = argparse.ArgumentParser(description="PDF 封面提取工具")
    parser.add_argument("--retry", action="store_true", help="只重试 failed_covers.txt 中记录的文件")
    args = parser.parse_args()

    if not LIST_JSON_PATH.exists():
        print(f"错误：找不到 {LIST_JSON_PATH}")
        sys.exit(1)

    with open(LIST_JSON_PATH, 'r', encoding='utf-8') as f:
        files = json.load(f)

    if not isinstance(files, list):
        print("错误：list.json 格式不正确")
        sys.exit(1)

    # 建立 path -> item 映射，方便查找
    files_map = {item['path']: item for item in files if 'path' in item}

    # 决定处理哪些文件
    if args.retry:
        if not FAILED_LOG.exists():
            print("错误：找不到 failed_covers.txt，无法重试。")
            sys.exit(1)

        with open(FAILED_LOG, 'r', encoding='utf-8') as f:
            retry_paths = [line.strip() for line in f if line.strip()]

        pdf_items = []
        for p in retry_paths:
            item = files_map.get(p)
            if item and p.lower().endswith('.pdf'):
                pdf_items.append(item)
            else:
                print(f"  [跳过] {p}（不在 list.json 中或不是 PDF）")

        if not pdf_items:
            print("没有需要重试的 PDF。")
            sys.exit(0)

        print(f"失败重试模式：共 {len(pdf_items)} 个文件待处理\n")
    else:
        pdf_items = [it for it in files if it.get('path', '').lower().endswith('.pdf')]
        print(f"全量模式：共 {len(pdf_items)} 个 PDF\n")

    COVERS_DIR.mkdir(exist_ok=True)
    success = 0
    failed_paths = []

    with ThreadPoolExecutor(max_workers=MAX_WORKERS) as ex:
        future_map = {ex.submit(process_one, item): item for item in pdf_items}

        for future in tqdm(as_completed(future_map), total=len(pdf_items), desc="提取进度"):
            item = future_map[future]
            try:
                path, cover_rel = future.result()
                if cover_rel:
                    success += 1
                else:
                    failed_paths.append(path)
            except Exception as exc:
                failed_paths.append(item.get('path', 'unknown'))
                print(f"\n  [异常] {item.get('path', '')}: {exc}")

    print(f"\n✅ 完成：成功 {success} / 本次 {len(pdf_items)}")
    if failed_paths:
        print(f"❌ 仍然失败 {len(failed_paths)} 个：")
        for p in failed_paths[:20]:
            print(f"   - {p}")
        with open(FAILED_LOG, "w", encoding='utf-8') as f:
            f.write("\n".join(failed_paths))
    else:
        # 全部成功，删除失败记录
        if FAILED_LOG.exists():
            FAILED_LOG.unlink()
            print("🗑️ 已清除 failed_covers.txt")

    print(f"\n封面目录: {COVERS_DIR}")
    print("下一步：在 GitHub Desktop 里 commit covers/ 并推送。")


if __name__ == '__main__':
    main()