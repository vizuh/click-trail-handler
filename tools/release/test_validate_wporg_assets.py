from __future__ import annotations

import importlib.util
import struct
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

MODULE_PATH = Path(__file__).with_name("validate-wporg-assets.py")
SPEC = importlib.util.spec_from_file_location("validate_wporg_assets", MODULE_PATH)
assert SPEC and SPEC.loader
validator = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(validator)


def write_png_header(path: Path, dimensions: tuple[int, int]) -> None:
    path.write_bytes(
        validator.PNG_SIGNATURE
        + struct.pack(">I", 13)
        + b"IHDR"
        + struct.pack(">II", *dimensions)
    )


class ValidateWporgAssetsTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temp_dir = tempfile.TemporaryDirectory()
        self.checkout = Path(self.temp_dir.name)
        assets = self.checkout / "assets"
        trunk = self.checkout / "trunk"
        assets.mkdir()
        trunk.mkdir()
        for name, (dimensions, _size_limit) in validator.EXPECTED_IMAGES.items():
            write_png_header(assets / name, dimensions)
        write_png_header(assets / "screenshot-1.png", (1000, 900))
        write_png_header(assets / "screenshot-2.png", (1000, 900))
        (trunk / "readme.txt").write_text(
            "== Screenshots ==\n\n1. First view.\n2. Second view.\n\n== Changelog ==\n",
            encoding="utf-8",
        )

    def tearDown(self) -> None:
        self.temp_dir.cleanup()

    @staticmethod
    def valid_svn_output(_checkout: Path, *args: str) -> str:
        if args[:3] == ("info", "--show-item", "url"):
            return validator.PLUGIN_SVN_URL
        if args[0] == "propget":
            return "image/png"
        raise AssertionError(args)

    def test_valid_checkout_passes(self) -> None:
        with patch.object(validator, "svn_output", self.valid_svn_output):
            self.assertEqual([], validator.validate_checkout(self.checkout))

    def test_wrong_dimension_and_alias_fail(self) -> None:
        write_png_header(self.checkout / "assets" / "icon-256x256.png", (2048, 2048))
        write_png_header(self.checkout / "assets" / "banner_1544x500.png", (1544, 500))
        with patch.object(validator, "svn_output", self.valid_svn_output):
            errors = validator.validate_checkout(self.checkout)
        self.assertTrue(any("expected 256x256, found 2048x2048" in error for error in errors))
        self.assertTrue(any("banner_1544x500.png" in error for error in errors))

    def test_listing_assets_outside_shared_directory_fail(self) -> None:
        trunk_asset = self.checkout / "trunk" / "assets" / "banner-772x250.png"
        tag_asset = self.checkout / "tags" / "1.9.0" / "assets" / "icon-128x128.png"
        trunk_asset.parent.mkdir()
        tag_asset.parent.mkdir(parents=True)
        write_png_header(trunk_asset, (772, 250))
        write_png_header(tag_asset, (128, 128))
        with patch.object(validator, "svn_output", self.valid_svn_output):
            errors = validator.validate_checkout(self.checkout)
        self.assertTrue(any("trunk/assets/banner-772x250.png" in error for error in errors))
        self.assertTrue(any("tags/1.9.0/assets/icon-128x128.png" in error for error in errors))

    def test_screenshot_caption_mismatch_fails(self) -> None:
        (self.checkout / "assets" / "screenshot-2.png").unlink()
        with patch.object(validator, "svn_output", self.valid_svn_output):
            errors = validator.validate_checkout(self.checkout)
        self.assertTrue(any("screenshot assets do not match readme captions" in error for error in errors))

    def test_oversized_screenshot_fails(self) -> None:
        screenshot = self.checkout / "assets" / "screenshot-1.png"
        with screenshot.open("r+b") as image:
            image.truncate(validator.SCREENSHOT_SIZE_LIMIT + 1)
        with patch.object(validator, "svn_output", self.valid_svn_output):
            errors = validator.validate_checkout(self.checkout)
        self.assertTrue(any("screenshot-1.png" in error and "exceeds" in error for error in errors))

    def test_wrong_mime_type_fails(self) -> None:
        def wrong_mime(checkout: Path, *args: str) -> str:
            if args[0] == "propget" and args[-1].endswith("icon-128x128.png"):
                return "application/octet-stream"
            return self.valid_svn_output(checkout, *args)

        with patch.object(validator, "svn_output", wrong_mime):
            errors = validator.validate_checkout(self.checkout)
        self.assertTrue(any("svn:mime-type must be image/png" in error for error in errors))


if __name__ == "__main__":
    unittest.main()
