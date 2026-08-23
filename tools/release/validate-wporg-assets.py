#!/usr/bin/env python3
"""Validate the shared WordPress.org directory assets before an SVN release."""

from __future__ import annotations

import argparse
import re
import struct
import subprocess
import sys
from pathlib import Path

PLUGIN_SVN_URL = "https://plugins.svn.wordpress.org/click-trail-handler"
PNG_SIGNATURE = b"\x89PNG\r\n\x1a\n"
EXPECTED_IMAGES = {
    "banner-772x250.png": ((772, 250), 4_000_000),
    "banner-1544x500.png": ((1544, 500), 4_000_000),
    "icon-128x128.png": ((128, 128), 1_000_000),
    "icon-256x256.png": ((256, 256), 1_000_000),
}
INVALID_ALIASES = {
    "banner_772x250.png",
    "icon_128x128.png",
    "icon_256x256.png",
}


def png_dimensions(path: Path) -> tuple[int, int]:
    with path.open("rb") as image:
        header = image.read(24)
    if len(header) != 24 or header[:8] != PNG_SIGNATURE or header[12:16] != b"IHDR":
        raise ValueError("not a valid PNG header")
    return struct.unpack(">II", header[16:24])


def svn_output(checkout: Path, *args: str) -> str:
    result = subprocess.run(
        ["svn", *args],
        cwd=checkout,
        check=False,
        capture_output=True,
        text=True,
    )
    if result.returncode != 0:
        detail = result.stderr.strip() or result.stdout.strip()
        raise RuntimeError(f"svn {' '.join(args)} failed: {detail}")
    return result.stdout.strip()


def screenshot_numbers(readme: Path) -> set[int]:
    text = readme.read_text(encoding="utf-8")
    match = re.search(r"^== Screenshots ==\s*$([\s\S]*?)(?=^== |\Z)", text, re.MULTILINE)
    if not match:
        raise ValueError("trunk/readme.txt has no Screenshots section")
    return {
        int(number)
        for number in re.findall(r"^\s*(\d+)\.\s+", match.group(1), re.MULTILINE)
    }


def validate_checkout(checkout: Path) -> list[str]:
    checkout = checkout.resolve()
    errors: list[str] = []
    assets = checkout / "assets"

    try:
        url = svn_output(checkout, "info", "--show-item", "url", str(checkout))
        if url.rstrip("/") != PLUGIN_SVN_URL:
            errors.append(f"wrong SVN checkout: {url or '(empty URL)'}")
    except RuntimeError as exc:
        return [str(exc)]

    if not assets.is_dir():
        return [f"missing top-level assets directory: {assets}"]

    for alias in sorted(INVALID_ALIASES):
        if (assets / alias).exists():
            errors.append(f"invalid underscore alias exists: assets/{alias}")

    for name, (expected_dimensions, size_limit) in EXPECTED_IMAGES.items():
        path = assets / name
        if not path.is_file():
            errors.append(f"missing required asset: assets/{name}")
            continue
        try:
            dimensions = png_dimensions(path)
        except (OSError, ValueError) as exc:
            errors.append(f"assets/{name}: {exc}")
            continue
        if dimensions != expected_dimensions:
            errors.append(
                f"assets/{name}: expected {expected_dimensions[0]}x{expected_dimensions[1]}, "
                f"found {dimensions[0]}x{dimensions[1]}"
            )
        if path.stat().st_size > size_limit:
            errors.append(
                f"assets/{name}: {path.stat().st_size} bytes exceeds {size_limit}-byte limit"
            )

    readme = checkout / "trunk" / "readme.txt"
    if not readme.is_file():
        errors.append("missing trunk/readme.txt")
    else:
        try:
            caption_numbers = screenshot_numbers(readme)
            asset_numbers = {
                int(match.group(1))
                for path in assets.glob("screenshot-*.png")
                if (match := re.fullmatch(r"screenshot-(\d+)\.png", path.name))
            }
            if asset_numbers != caption_numbers:
                errors.append(
                    "screenshot assets do not match readme captions: "
                    f"assets={sorted(asset_numbers)}, captions={sorted(caption_numbers)}"
                )
        except (OSError, UnicodeError, ValueError) as exc:
            errors.append(str(exc))

    for path in sorted(assets.glob("*.png")):
        try:
            mime_type = svn_output(checkout, "propget", "svn:mime-type", str(path))
        except RuntimeError as exc:
            errors.append(str(exc))
            continue
        if mime_type != "image/png":
            errors.append(
                f"assets/{path.name}: svn:mime-type must be image/png, found {mime_type or '(unset)'}"
            )

    return errors


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("checkout", type=Path, help="WordPress.org SVN checkout root")
    args = parser.parse_args()

    errors = validate_checkout(args.checkout)
    if errors:
        for error in errors:
            print(f"ERROR: {error}", file=sys.stderr)
        return 1

    print("WordPress.org assets valid: shared icons, banners, screenshots, and MIME types")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
