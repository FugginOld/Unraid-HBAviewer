#!/usr/bin/env python3
"""
Index a mirrored HBA firmware tree into manifest.json for HBAviewer.

Usage:
    ./build-firmware-manifest.py ~/hba-firmware-repo

Output shape (manifest.json at the repo root):

    {
      "generated": "2026-08-08T00:00:00Z",
      "source": "images.45drives.com/Firmware",
      "boards": {
        "SAS9305-24i": {
          "chip": "SAS3224",
          "generation": "sas3",
          "images": [
            {
              "path": "Firmware/LSI9305/24i/SAS9305_24i_IT_P.bin",
              "mode": "IT",
              "version": "16.00.12.00",
              "version_source": "strings",
              "bytes": 1048576,
              "sha256": "...",
              "mtime": "2022-10-21T16:39:00Z"
            }
          ]
        }
      }
    }

IMPORTANT: version detection is best-effort. Vendor .bin files do not
consistently carry a plain-text version, so anything marked
"version_source": "unknown" needs a manual entry in overrides.json:

    { "Firmware/LSI9305/24i/SAS9305_24i_IT_P.bin": "16.00.12.00" }

Confirm at least one file against a live card (`sas3flash -list`) before
trusting the index to drive any "update available" logic.
"""

import hashlib
import json
import re
import sys
from datetime import datetime, timezone
from pathlib import Path

# Directory-name -> board metadata. Keys are matched against path parts.
BOARD_MAP = {
    ("LSI9305", "24i"): ("SAS9305-24i", "SAS3224", "sas3"),
    ("LSI9305", "16i"): ("SAS9305-16i", "SAS3216", "sas3"),
    ("LSI3008",): ("SAS9300-8i", "SAS3008", "sas3"),
    ("LSI9201",): ("SAS9201-16i", "SAS2116", "sas2"),
    ("LSI9361",): ("MegaRAID-9361", "SAS3108", "sas3-raid"),
    ("LSIP411W-32P",): ("SAS9405W-32P", "SAS3416", "sas3.5"),
}

FW_EXTS = {".bin", ".rom", ".fw"}
VERSION_RE = re.compile(rb"\b((?:[0-9]{1,2}\.){3}[0-9]{2})\b")


def sha256_of(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as fh:
        for chunk in iter(lambda: fh.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def guess_board(rel: Path):
    parts = set(rel.parts)
    best = None
    for keys, meta in BOARD_MAP.items():
        if all(k in parts for k in keys):
            if best is None or len(keys) > best[0]:
                best = (len(keys), meta)
    return best[1] if best else None


def guess_mode(name: str) -> str:
    upper = name.upper()
    if "_IT" in upper or "-IT" in upper:
        return "IT"
    if "_IR" in upper or "-IR" in upper:
        return "IR"
    if upper.endswith(".ROM"):
        return "BIOS"
    return "unknown"


def guess_version(path: Path):
    """Scan for a dotted quad like 16.00.12.00. Best-effort only."""
    try:
        blob = path.read_bytes()
    except OSError:
        return None, "unreadable"
    hits = [m.group(1).decode() for m in VERSION_RE.finditer(blob)]
    if not hits:
        return None, "unknown"
    # Prefer the most common hit; firmware images repeat the real version.
    ranked = sorted(set(hits), key=lambda v: (-hits.count(v), v))
    return ranked[0], "strings"


def main() -> int:
    if len(sys.argv) != 2:
        print(__doc__)
        return 1

    root = Path(sys.argv[1]).expanduser().resolve()
    if not root.is_dir():
        print(f"not a directory: {root}", file=sys.stderr)
        return 1

    overrides = {}
    ov_path = root / "overrides.json"
    if ov_path.exists():
        overrides = json.loads(ov_path.read_text())

    boards: dict[str, dict] = {}
    unresolved: list[str] = []

    for path in sorted(root.rglob("*")):
        if not path.is_file() or path.suffix.lower() not in FW_EXTS:
            continue
        rel = path.relative_to(root)
        meta = guess_board(rel)
        if meta is None:
            unresolved.append(str(rel))
            continue

        board, chip, generation = meta
        rel_str = str(rel)

        if rel_str in overrides:
            version, vsrc = overrides[rel_str], "override"
        else:
            version, vsrc = guess_version(path)

        entry = {
            "path": rel_str,
            "mode": guess_mode(path.name),
            "version": version,
            "version_source": vsrc,
            "bytes": path.stat().st_size,
            "sha256": sha256_of(path),
            "mtime": datetime.fromtimestamp(
                path.stat().st_mtime, tz=timezone.utc
            ).isoformat().replace("+00:00", "Z"),
            "archived": "archive" in rel.parts,
        }

        b = boards.setdefault(
            board, {"chip": chip, "generation": generation, "images": []}
        )
        b["images"].append(entry)

    # Sort images newest-first by mtime so images[0] is the candidate "latest".
    for b in boards.values():
        b["images"].sort(key=lambda e: e["mtime"], reverse=True)

    manifest = {
        "generated": datetime.now(timezone.utc)
        .isoformat(timespec="seconds")
        .replace("+00:00", "Z"),
        "source": "images.45drives.com/Firmware",
        "boards": boards,
    }

    out = root / "manifest.json"
    out.write_text(json.dumps(manifest, indent=2) + "\n")

    total = sum(len(b["images"]) for b in boards.values())
    unknown = sum(
        1
        for b in boards.values()
        for i in b["images"]
        if i["version_source"] in ("unknown", "unreadable")
    )
    print(f"Wrote {out}")
    print(f"  boards: {len(boards)}   images: {total}")
    if unknown:
        print(f"  {unknown} image(s) with no detected version -> add to overrides.json")
    if unresolved:
        print(f"  {len(unresolved)} file(s) not mapped to a board:")
        for u in unresolved[:10]:
            print(f"    {u}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
