#!/usr/bin/env python3
"""
Validator for known-firmware.json schema 2.

Enforces the invariants that the schema-1 markdown had to state in prose and
therefore could not enforce. Run in CI; non-zero exit blocks the commit.

    ./validate-known-firmware.py source/usr/local/emhttp/plugins/hbaviewer/data/known-firmware.json
"""

import json
import re
import sys
from fnmatch import fnmatch

STATES = {"confirmed", "observed-floor", "weak", "unconfirmed", "not-applicable"}
BASES = {"observed", "documented", "inferred", "assumed", "none"}
VERSION_FIELDS = ("firmware", "nvdata", "bios", "uefi_bsd")
VERSION_RE = re.compile(r"^\d{2}\.\d{2}\.\d{2}\.\d{2}$")

errors: list[str] = []
warnings: list[str] = []


def err(msg: str) -> None:
    errors.append(msg)


def warn(msg: str) -> None:
    warnings.append(msg)


def check_envelope(where: str, name: str, env: dict, allow_inherit: bool) -> None:
    if not isinstance(env, dict):
        err(f"{where}.{name}: not an envelope object")
        return

    state = env.get("state")
    basis = env.get("basis")
    value = env.get("value")
    inherit = env.get("inherit")

    if state not in STATES:
        err(f"{where}.{name}: bad state {state!r}")
    if basis not in BASES:
        err(f"{where}.{name}: bad basis {basis!r}")

    # The core rule schema 1 could not express: null has two meanings and they
    # must be told apart.
    if state in ("unconfirmed", "not-applicable") and value is not None:
        err(f"{where}.{name}: state {state} requires value null, got {value!r}")
    if state == "unconfirmed" and basis != "none":
        err(f"{where}.{name}: unconfirmed must have basis 'none'")
    if state != "unconfirmed" and basis == "none":
        err(f"{where}.{name}: basis 'none' is only valid for unconfirmed")

    if value is None and state not in ("unconfirmed", "not-applicable") and not inherit:
        err(f"{where}.{name}: state {state} with null value and no inherit")
    if inherit and not allow_inherit:
        err(f"{where}.{name}: inherit is not allowed on a branch record")
    if inherit and inherit != "branch":
        err(f"{where}.{name}: unknown inherit target {inherit!r}")

    if value is not None and not VERSION_RE.match(str(value)):
        warn(f"{where}.{name}: value {value!r} is not dotted-quad")

    # Provenance: anything that is not terminal-and-verified needs a trail.
    if state in ("observed-floor", "weak") and not env.get("source") and not env.get("note"):
        warn(f"{where}.{name}: {state} with no source and no note")
    if state in ("confirmed", "observed-floor", "weak") and not env.get("verified"):
        warn(f"{where}.{name}: no verified date")


def main(path: str) -> int:
    data = json.load(open(path))

    if data.get("schema") != 2:
        err(f"schema is {data.get('schema')}, expected 2")

    branches = data.get("branches", {})
    boards = data.get("boards", [])

    for bname, br in branches.items():
        for f in VERSION_FIELDS:
            if f in br:
                check_envelope(f"branch {bname}", f, br[f], allow_inherit=False)
        if "terminal" not in br:
            err(f"branch {bname}: missing terminal")

    seen = set()
    for b in boards:
        name = b.get("board", "<unnamed>")
        if name in seen:
            err(f"duplicate board {name}")
        seen.add(name)

        branch = b.get("branch")
        if branch not in branches:
            err(f"{name}: unknown branch {branch!r}")
            continue
        br = branches[branch]

        for f in VERSION_FIELDS:
            if f not in b:
                err(f"{name}: missing field {f}")
                continue
            check_envelope(name, f, b[f], allow_inherit=True)

            env = b[f]
            if env.get("inherit") == "branch":
                parent = br.get(f, {})
                # A board may not claim more certainty than its branch supports.
                order = ["unconfirmed", "weak", "observed-floor", "confirmed"]
                if env.get("state") in order and parent.get("state") in order:
                    if order.index(env["state"]) > order.index(parent["state"]):
                        err(
                            f"{name}.{f}: state {env['state']} exceeds branch "
                            f"{branch} state {parent['state']}"
                        )
                if parent.get("state") == "not-applicable" and env.get("state") != "not-applicable":
                    err(f"{name}.{f}: inherits from a not-applicable branch field")

        # Only a terminal branch makes 'confirmed' meaningful.
        if b["firmware"].get("state") == "confirmed" and not br.get("terminal"):
            err(f"{name}: firmware confirmed on non-terminal branch {branch}")

        # NVDATA is board-specific by definition and can never be inherited.
        if b["nvdata"].get("inherit"):
            err(f"{name}: nvdata inherits from branch; NVDATA is board-specific")

        flags = b.get("flags", [])
        images = b.get("images", [])
        if "rom-profiles" in flags and len(images) < 2:
            err(f"{name}: rom-profiles flag requires at least two image entries")
        if "rom-profiles" not in flags and len(images) > 1:
            warn(f"{name}: multiple images without a rom-profiles flag")
        if "dual-ioc" in flags and len(b.get("chips", [])) < 2:
            err(f"{name}: dual-ioc flag but fewer than two chips listed")

        for img in images:
            if not img.get("filename") or not img.get("url"):
                warn(f"{name}: image profile {img.get('profile')} has no file mapping")
            elif not img.get("sha256"):
                warn(f"{name}: image profile {img.get('profile')} has no checksum")

        # Every chip must resolve to a tool, or be explicitly refused/unsupported.
        refused_chips = {r["chip"] for r in data.get("refuse", {}).get("by_chip", [])}
        for chip in b.get("chips", []):
            if chip in refused_chips:
                err(f"{name}: chip {chip} is on the refuse list but the board is indexed")
                continue
            matched = any(
                fnmatch(chip, pat)
                for entry in data.get("tool_map", [])
                for pat in entry["match"]
            )
            if not matched:
                err(f"{name}: chip {chip} matches no tool_map pattern")

    # Cross-check the multipath count the prose used to assert by hand.
    mp = [b["board"] for b in boards if "multipath" in b.get("flags", [])]
    print(f"boards: {len(boards)}  multipath: {len(mp)}")

    # Refuse list must be reachable: a refused chip that no pattern matches is
    # dead config, and one that IS indexed is a contradiction.
    for r in data.get("refuse", {}).get("by_board", []):
        if r.get("status") == "needs-identity" and not r.get("subdevice"):
            warn(
                f"refuse.by_board {r['board']}: shares silicon with an indexed HBA "
                f"and has no subdevice; chip-prefix refusal cannot catch it"
            )

    for w in warnings:
        print(f"WARN  {w}")
    for e in errors:
        print(f"ERROR {e}")

    print(f"\n{len(errors)} error(s), {len(warnings)} warning(s)")
    return 1 if errors else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1] if len(sys.argv) > 1 else "known-firmware.json"))
