# Plan 003: Pin and checksum-verify the binaries build.sh downloads

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 0346777..HEAD -- build.sh .github/workflows/release.yml`
> If either file changed since this plan was written, compare the "Current
> state" excerpts against the live code before proceeding; on a mismatch,
> treat it as a STOP condition.
>
> **This plan requires network access** to fetch the two files once and record
> their hashes. If you have no network access, STOP and report — do not invent
> or guess hash values.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: security
- **Planned at**: commit `0346777`, 2026-07-26

## Why this matters

`build.sh` downloads two third-party files and packages them into the `.txz`
that every user installs: the `lsiutil` binary, which runs **as root** on the
user's server, and the Chart.js bundle, which runs in the administrator's
browser. Neither download is checked against a known hash, and the `lsiutil`
URL points at a **mutable `master` branch** rather than a pinned revision.

The release workflow (`.github/workflows/release.yml`) runs `build.sh` in CI on
every tag push. So the exact bytes that ship to users are whatever those two
upstream URLs happen to serve at that moment. If the upstream repository is
compromised, or force-pushed, or simply changes the file, a different
root-executed binary is packaged and published — and nothing in the pipeline
would notice.

The MD5 in `hbaviewer.plg` does not help: it is computed *from the archive
after it was built*, so it pins the artifact you produced, not the source you
produced it from. It protects users against a corrupted download of your
release; it protects no one against a changed upstream.

After this plan, a changed upstream file fails the build loudly instead of
shipping silently.

## Current state

Files involved:

- `build.sh` — the packaging script. Downloads at lines 27–44, ELF sanity check
  at 46–54.
- `.github/workflows/release.yml` — invokes `bash build.sh "$GITHUB_REF_NAME"`
  at the "Build hbaviewer.txz" step. No change needed here, but read it so you
  understand that this script runs unattended in CI.

The download block exactly as it exists today, `build.sh:16-44`:

```bash
# Linux x86_64 binary only — single file from the repo, not the whole archive
LSIUTIL_URL="https://github.com/thomaslovell/LSIUtil/raw/master/Binaries/LSIutil_1.70_release_binaries/linux/lsiutil.x86_64"
BINARY_DEST="source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.x86_64"
# Chart.js UMD build (Performance tab) — MIT, fetched like the lsiutil binary.
CHARTJS_VER="4.4.6"
CHARTJS_URL="https://cdn.jsdelivr.net/npm/chart.js@${CHARTJS_VER}/dist/chart.umd.min.js"
CHARTJS_DEST="source/usr/local/emhttp/plugins/hbaviewer/chart.umd.min.js"
OUTPUT="releases/hbaviewer.txz"

echo "==> Unraid HBAviewer build  (version: $VERSION)"

# Download lsiutil Linux binary if not already present
if [ ! -f "$BINARY_DEST" ]; then
    echo "--> Downloading lsiutil 1.70 (Linux x86_64)..."
    curl -fL "$LSIUTIL_URL" -o "$BINARY_DEST"
    chmod +x "$BINARY_DEST"
    echo "    Saved to: $BINARY_DEST"
else
    echo "--> lsiutil binary already present, skipping download"
fi

# Download Chart.js (Performance tab) if not already present
if [ ! -f "$CHARTJS_DEST" ]; then
    echo "--> Downloading Chart.js $CHARTJS_VER (UMD)..."
    curl -fL "$CHARTJS_URL" -o "$CHARTJS_DEST"
    echo "    Saved to: $CHARTJS_DEST"
else
    echo "--> Chart.js already present, skipping download"
fi
```

And the existing sanity check, `build.sh:46-54`:

```bash
# Sanity-check: ensure it's a Linux ELF binary (not a Windows PE)
FILE_TYPE=$(file "$BINARY_DEST" 2>/dev/null)
if echo "$FILE_TYPE" | grep -qi "ELF"; then
    echo "    Confirmed: Linux ELF binary"
elif echo "$FILE_TYPE" | grep -qi "PE\|MZ"; then
    echo "ERROR: Downloaded file appears to be a Windows binary. Aborting."
    rm -f "$BINARY_DEST"
    exit 1
fi
```

Three things to notice about the current state, because they shape the fix:

1. Both downloads are **skipped if the file already exists**. So verification
   must run on *both* branches — a poisoned or truncated file left in a working
   tree from a previous run must also be caught, not trusted because it is
   already there.
2. The ELF check is best-effort and silently passes when `file` is not
   installed: `FILE_TYPE` is empty, both `grep` branches are false, and the
   script continues. A checksum makes this check redundant as a security
   control, but leave it in place — it produces a clearer error for the common
   honest mistake of grabbing the wrong architecture.
3. `set -e` is active (`build.sh:12`), so an uncaught non-zero exit aborts the
   script. The verification function below exits explicitly anyway, so its
   behaviour does not depend on that.

**Repo conventions that apply here:**

- `build.sh` uses `echo "==> ..."` for phase headers, `echo "--> ..."` for
  actions, and four-space-indented `echo "    ..."` for details. Match it.
- Errors are printed as `echo "ERROR: ...` followed by `exit 1` — see
  `build.sh:51-53`.
- Shell in this repo is bash with a `#!/bin/bash` shebang and comments
  explaining *why*, not *what*. See
  `source/usr/local/emhttp/plugins/hbaviewer/scripts/lib.sh` for the house style.
- CI lints every `.sh` under `source` and `tests` with `bash -n`
  (`.github/workflows/php.yml`), but **not** `build.sh` at the repo root. Lint
  it manually as instructed below.

## Commands you will need

| Purpose            | Command                                          | Expected on success                    |
|--------------------|--------------------------------------------------|----------------------------------------|
| Lint build.sh      | `bash -n build.sh`                               | exit 0, no output                      |
| Compute a hash     | `sha256sum <file>`                               | 64 hex chars + filename                |
| Full test suite    | `bash tests/run.sh`                              | `--- all pass ---`, exit 0             |
| Dry-run the build  | `bash build.sh 0000.00.00`                       | exit 0, ends with `Done: releases/hbaviewer.txz` |

The build writes to `releases/` and downloads into `source/`. Both are already
git-ignored for these artifacts — confirm with `git status --porcelain` after a
build run that no unexpected file is newly tracked.

## Scope

**In scope**:

- `build.sh`

**Out of scope** (do NOT touch, even though they look related):

- `.github/workflows/release.yml` — it already gates the release on the test
  suite and calls `build.sh`. Once `build.sh` fails on a hash mismatch, the
  release fails with it. No workflow change is needed and adding one widens the
  blast radius of this plan.
- `hbaviewer.plg` — the `md5` entity is patched automatically by the release
  workflow and describes the built archive. It is a different control from the
  one this plan adds; leave it alone.
- Vendoring either file into the repository. That is a real alternative to
  pinning, but it is a much bigger decision (repo size, licence handling,
  update workflow) and is explicitly not what this plan does.
- Any change to what the plugin does at runtime. This plan touches the build
  only.

## Git workflow

- Branch: `advisor/003-pin-and-verify-build-downloads`
- One commit. Message style matches this repo's history — short imperative
  subject, no conventional-commit prefix. Suggested:
  `Pin and checksum-verify the lsiutil and Chart.js downloads`
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Resolve the lsiutil URL to an immutable revision

The current URL references `master`, which can change under you. Find the
commit SHA that `master` currently points at in the upstream repository and
build a permalink from it.

```bash
git ls-remote https://github.com/thomaslovell/LSIUtil.git HEAD
```

That prints `<full-40-char-sha>	HEAD`. Take the SHA and construct:

```
https://github.com/thomaslovell/LSIUtil/raw/<full-sha>/Binaries/LSIutil_1.70_release_binaries/linux/lsiutil.x86_64
```

Confirm the permalink actually serves the file before you commit to it:

```bash
curl -fsIL "<the permalink you built>" | head -1
```

**Verify**: prints an HTTP 200 status line.

If the upstream default branch is not `master` (some repositories have been
renamed to `main`), `git ls-remote ... HEAD` still resolves correctly — use
whatever SHA it returns. If the path 404s, STOP and report; the upstream layout
has changed and this plan's assumption is broken.

### Step 2: Record the two hashes

Remove any previously downloaded copies so you hash exactly what a clean build
would fetch, then download and hash both files.

```bash
rm -f source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.x86_64 \
      source/usr/local/emhttp/plugins/hbaviewer/chart.umd.min.js

curl -fL "<the pinned lsiutil permalink from Step 1>" \
  -o /tmp/lsiutil.x86_64
curl -fL "https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js" \
  -o /tmp/chart.umd.min.js

sha256sum /tmp/lsiutil.x86_64 /tmp/chart.umd.min.js
```

Write both 64-character hashes down. You will paste them into `build.sh` in
Step 3.

Sanity-check the lsiutil download before trusting its hash:

```bash
file /tmp/lsiutil.x86_64
```

**Verify**: the output contains `ELF` and `x86-64`. If it does not — if you got
an HTML error page, a Git LFS pointer, or a Windows PE — STOP and report. You
would otherwise be pinning the hash of the wrong thing, which is worse than not
pinning at all.

### Step 3: Add the hashes and the verification function to build.sh

Add the two hash constants next to the URLs they belong to, replacing
`build.sh:16-22`:

```bash
# Linux x86_64 binary only — single file from the repo, not the whole archive.
# Pinned to an immutable commit permalink, not a branch: this binary is packaged
# into the .txz and runs as root on every user's server, and release.yml builds
# unattended in CI, so "whatever master serves right now" is not an acceptable
# input. Bump the SHA and the checksum together, deliberately.
LSIUTIL_URL="<the pinned permalink from Step 1>"
LSIUTIL_SHA256="<the lsiutil hash from Step 2>"
BINARY_DEST="source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.x86_64"
# Chart.js UMD build (Performance tab) — MIT, fetched like the lsiutil binary.
# Version-pinned already; the checksum pins the bytes behind that version too.
CHARTJS_VER="4.4.6"
CHARTJS_URL="https://cdn.jsdelivr.net/npm/chart.js@${CHARTJS_VER}/dist/chart.umd.min.js"
CHARTJS_SHA256="<the Chart.js hash from Step 2>"
CHARTJS_DEST="source/usr/local/emhttp/plugins/hbaviewer/chart.umd.min.js"
OUTPUT="releases/hbaviewer.txz"
```

Then add the verification helper immediately after that block, before the
`echo "==> Unraid HBAviewer build ..."` line:

```bash
# Fail the build on any byte that isn't what we pinned. Runs on the cached file
# too, not just a fresh download — a stale or tampered file sitting in the work
# tree must not get a free pass just because it already exists.
verify_sha256() {   # $1 = file, $2 = expected hash
    local got
    got=$(sha256sum "$1" | awk '{print $1}')
    if [ "$got" != "$2" ]; then
        echo "ERROR: checksum mismatch for $1"
        echo "    expected: $2"
        echo "    got:      $got"
        echo "    Refusing to package an unverified file. If this change is"
        echo "    intentional, review the new file and update the pinned hash."
        exit 1
    fi
    echo "    Checksum OK"
}
```

**Verify**: `bash -n build.sh` → exit 0

### Step 4: Call the verifier on both paths of both downloads

Rewrite the two download blocks so verification happens unconditionally —
after a fresh download *and* after deciding to reuse an existing file.

```bash
# Download lsiutil Linux binary if not already present
if [ ! -f "$BINARY_DEST" ]; then
    echo "--> Downloading lsiutil 1.70 (Linux x86_64)..."
    curl -fL "$LSIUTIL_URL" -o "$BINARY_DEST"
    chmod +x "$BINARY_DEST"
    echo "    Saved to: $BINARY_DEST"
else
    echo "--> lsiutil binary already present, skipping download"
fi
verify_sha256 "$BINARY_DEST" "$LSIUTIL_SHA256"

# Download Chart.js (Performance tab) if not already present
if [ ! -f "$CHARTJS_DEST" ]; then
    echo "--> Downloading Chart.js $CHARTJS_VER (UMD)..."
    curl -fL "$CHARTJS_URL" -o "$CHARTJS_DEST"
    echo "    Saved to: $CHARTJS_DEST"
else
    echo "--> Chart.js already present, skipping download"
fi
verify_sha256 "$CHARTJS_DEST" "$CHARTJS_SHA256"
```

Leave the existing ELF sanity check at `build.sh:46-54` exactly as it is. It is
now redundant as a security control but still gives a friendlier message for
the honest mistake of a wrong-architecture file.

**Verify**: `bash -n build.sh` → exit 0

**Verify**: `grep -c 'verify_sha256 "' build.sh` → prints `2` (the two call
sites; the function definition uses a different form and is not counted)

### Step 5: Prove the check fires in both directions

A verification you have not seen fail is not a verification. Test both outcomes.

**Positive** — a clean build passes:

```bash
rm -f source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.x86_64 \
      source/usr/local/emhttp/plugins/hbaviewer/chart.umd.min.js
bash build.sh 0000.00.00
```

**Verify**: exit code 0, output includes `Checksum OK` twice, and the final line
is `Done: releases/hbaviewer.txz`.

**Negative** — a tampered file fails:

```bash
printf 'tampered' >> source/usr/local/emhttp/plugins/hbaviewer/chart.umd.min.js
bash build.sh 0000.00.00; echo "exit=$?"
```

**Verify**: prints `ERROR: checksum mismatch` and `exit=1`.

Then restore a good copy so you do not leave a poisoned file behind:

```bash
rm -f source/usr/local/emhttp/plugins/hbaviewer/chart.umd.min.js
bash build.sh 0000.00.00
```

**Verify**: exit code 0 again.

Finally, clean up the throwaway archive from the dry runs:

```bash
rm -f releases/hbaviewer.txz
git status --porcelain
```

**Verify**: `git status --porcelain` shows only `build.sh` as modified (plus
`plans/README.md`). If it shows the downloaded binaries or the archive as
untracked-and-not-ignored, STOP — `.gitignore` does not cover them and you need
to report that rather than committing a binary.

## Test plan

No new automated tests. `build.sh` is a packaging script that runs at release
time, is not covered by `tests/run.sh`, and its correctness here is proven by
the two-direction manual check in Step 5 — which is the real test and must be
recorded in your report.

The existing suite must continue to pass unchanged:
`bash tests/run.sh` → `--- all pass ---`.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `grep -c 'LSIUTIL_SHA256=' build.sh` prints `1`, and the value is 64 hex characters
- [ ] `grep -c 'CHARTJS_SHA256=' build.sh` prints `1`, and the value is 64 hex characters
- [ ] `grep -c 'verify_sha256 "' build.sh` prints `2`
- [ ] `grep -c '/raw/master/' build.sh` prints `0` — the mutable branch reference is gone
- [ ] `bash -n build.sh` exits 0
- [ ] A clean `bash build.sh 0000.00.00` exits 0 and prints `Checksum OK` twice
- [ ] A tampered file makes `bash build.sh 0000.00.00` print `ERROR: checksum mismatch` and exit 1
- [ ] `bash tests/run.sh` exits 0 and prints `--- all pass ---`
- [ ] `git status --porcelain` shows exactly one modified source file: `build.sh` (plus `plans/README.md`)
- [ ] `plans/README.md` status row for 003 updated

## STOP conditions

Stop and report back (do not improvise) if:

- **You have no network access.** Do not guess, fabricate, or leave placeholder
  hashes. A wrong pinned hash breaks every future release; an absent one is
  merely the status quo.
- The `file` output for the downloaded lsiutil binary does not contain `ELF` and
  `x86-64`. You would be pinning the wrong artifact.
- The pinned permalink from Step 1 returns anything other than HTTP 200, or the
  upstream repository path has changed.
- The upstream file's hash differs from what the currently committed
  `source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.x86_64` hashes to, if
  that file is present in your working tree. That would mean upstream has
  *already* changed since the last build — which is exactly the event this plan
  exists to detect, and a human needs to decide which version is correct before
  you pin either one. Report both hashes.
- `bash build.sh` fails for a reason unrelated to checksums (missing `curl`,
  missing `tar`, missing `xz`). Report the environment problem; do not work
  around it by disabling the check you just added.

## Maintenance notes

- **Bumping either dependency is now a two-line change on purpose**: the URL (or
  version) and its hash, in the same commit. If someone updates one without the
  other, the build fails loudly at the next release. That is the intended
  ergonomics — the friction *is* the control.
- **The `.txz` MD5 in `hbaviewer.plg` is a separate, still-necessary control.**
  It protects users against a corrupted download of your release. It does not
  overlap with what this plan adds and must not be removed.
- **`build.sh` is not linted by CI.** `.github/workflows/php.yml` lints only
  `source` and `tests`. If you want that gap closed, it is a one-line change to
  the lint step — deliberately left out of this plan to keep the diff to a
  single file, but worth doing.
- **What a reviewer should scrutinise**: that `verify_sha256` is called on the
  *cached* branch as well as the fresh-download branch (the whole point — a file
  already sitting in the tree is exactly the one nobody checks), and that the
  lsiutil URL contains a 40-character SHA rather than a branch name.
- **The ELF check is now belt-and-braces.** If anyone proposes deleting it,
  that is defensible — but it costs nothing and gives a clearer message for the
  wrong-architecture mistake, which is more common than an attack.
