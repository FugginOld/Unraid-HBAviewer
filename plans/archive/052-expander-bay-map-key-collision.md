# 052 — An expander-attached drive's PHY number is not unique, and the bay map keys on it

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**:
> `git diff --stat bf99918..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/bay_map.php source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php source/usr/local/emhttp/plugins/hbaviewer/scripts/get_attached_drives.sh source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/drives_join.sh tests/bay_map_test.php tests/drives_sysfs_test.sh`
> Expected output: **nothing**. Every excerpt below is quoted from `bf99918`
> (`dev` tip, 2026-08-05). Any difference is a STOP condition.
>
> **Worktree note**: if you were dispatched into a fresh git worktree, it may
> have branched from `main`, not from `dev` — a trap that has already cost two
> plans (049, 050). Run `git log --oneline -1` before anything else. See "Git
> workflow" below for the one command that lands you on the right base whether
> it did or not; do that first, then re-run the drift check.

## Status

Not started. Raised by the 2026-08-05 pre-merge review of `dev` (plans 047-051)
and recorded in `plans/README.md` under "Open defects from the 2026-08-05
branch review". P2 — silent, and it destroys hand-curated data, but it needs an
expander to reproduce and the maintainer's box has none.

## Why this matters

`bay_map.php` is the one thing this plugin persists that **cannot be
regenerated from hardware**: somebody walked to the rack and placed 24 drives
on a grid. Every other store is a cache. Plan 047's own maintenance note says a
key-shape change needs a migration, not a silent reshape, "or every existing
user's bay map orphans on upgrade".

Today, on a box with an expander, two different drives can produce the **same
key**. Both resolve the same `$map[$key]`, both land in the same cell, and
`luBayPaint()`'s `at[row + ':' + col] = p` keeps whichever came last:

- one drive **disappears from the grid and from the tray** — it is in neither
  list, which reads as a detection bug, not a key bug;
- assigning "the" drive at that key silently moves the other one too;
- the same ambiguity mislabels the PHY Health top-offenders rows (see Defect B).

`bay_map_key()`'s own docblock says a null key must never be replaced with "a
key that would collide with a real one". This is that collision, arriving from
the other direction.

## Provenance — what is measured and what is inferred

**Measured, in this repo:**

- The SAS transport names a device by its topology position, and the name has a
  variable number of colon-separated parts. Plan 049 established this for PHYs
  from the reporter's hardware (issue #12, Dell HBA355i): `phy-<host>:<n>` is
  the HBA's own PHY, `phy-<host>:<n>:<m>` is a PHY on an expander behind it.
  That reporter's box had 29 own PHYs and 76 expander PHYs.
- The direct-attach end-device form, from the 9207-8i in issue #14 (plan 051):
  `/sys/class/sas_device/end_device-0:0/` with `sas_address` and
  `phy_identifier` in it, resolving to `host0/port-0:0/end_device-0:0/…`.
- `phy_identifier` on an expander-attached end device is the **expander's** PHY
  number, not an HBA PHY number. This is what the SAS transport class stores
  and it is why the collision exists at all.

**Inferred, not yet measured here:** that an expander-attached end device is
named `end_device-<host>:<n>:<m>`, by symmetry with the `phy-` form 049
measured on the same hardware. Step 1 confirms it, and **the fix is written so
that being wrong about it costs nothing**: the disambiguation only engages on a
name with a third component, so a box that never produces one behaves exactly
as it does today, byte for byte.

## Current state

### Where the number comes from — `get_attached_drives.sh:43-61`

```bash
    # ── Stage 2: SAS address + PHY from sysfs ────────────────────────────────────
    if [ -d "$SYS_SAS_DEVICE" ]; then
        for ed in "$SYS_SAS_DEVICE"/end_device-*/; do
            [ -e "$ed" ] || continue
            sas=$(sed 's/0x//' "${ed}sas_address" 2>/dev/null | tr '[:lower:]' '[:upper:]' | tr -d ' \n')
            phy=$(tr -d ' \n' < "${ed}phy_identifier" 2>/dev/null)
            [ -n "$sas" ] || continue
            blk_dir=$(find "$(readlink -f "${ed}device")" -maxdepth 12 -type d -name 'block' 2>/dev/null | head -1)
            blk=$(ls "$blk_dir" 2>/dev/null | head -1)
            [ -n "$blk" ] || continue
            printf "/dev/%s %s %s\n" "$blk" "$sas" "${phy:-0}"
        done
    fi > "$TMPSAS"
```

The loop reads `phy_identifier` and throws the *name* away. The name is the
only thing that says which device that number belongs to. **This is 049's
defect class in a second location** — a sysfs name whose component count
carries the topology, consumed as if it were flat — but a different mechanism
in a different file, which is why it is its own plan (see "Relationship to
049", below).

### Where it becomes a drive field — `parse/drives_join.sh:12-26`

```awk
{
    sasmap[$1]=$2; phymap[$1]=$3
}
END {
    for (i=1; i<=n; i++) {
        ...
        phy=(dev in phymap) ? phymap[dev]+0 : tgt
        printf "{\"bus\":%d,\"target\":%d,\"sas_address\":\"%s\",\"phy\":%d,\"os_name\":\"%s\"}",
            bus, tgt, sas, phy, dev
    }
```

### Defect A — the bay-map key — `bay_map.php:85-101`

```php
function bay_map_key(int $ctl, array $drive): ?string {
    if (isset($drive['phy']) && $drive['phy'] !== '')   return "c$ctl:h" . (int) $drive['phy'];
    if (isset($drive['port']) && $drive['port'] !== '') return "c$ctl:p" . (int) $drive['port'];
    return null;
}

function bay_map_key_valid(string $key): bool {
    return (bool) preg_match('/^c\d{1,3}:[ph]\d{1,4}$/', $key);
}
```

Expander A phy 8 and expander B phy 8 both yield `c0:h8`. **And it does not
take two expanders**: one expander plus any direct-attached SAS drive collides
just as well, because the expander numbers its PHYs from 0 and so does the HBA.
A 24-bay backplane on ports 0-3 with two direct drives on ports 4-5 is enough.

### Defect B — the top-offenders drive label — `ajax_info.php:516-525`

```php
    // lsiutil: drives carry `phy` directly — a straight index match.
    if (isset($drives[0]['phy'])) {
        foreach ($drives as $d) {
            if (isset($d['phy']) && (string) $d['phy'] === (string) ($phy['phy'] ?? '')) return $d;
        }
        return null;
    }
```

Same ambiguity, second consumer. After 049, the `$phy` rows are the
controller's **own** PHYs only — so matching them against an expander-attached
drive's expander-relative `phy` names the wrong drive. The function's own
docblock is explicit that this is the outcome it exists to prevent: *"naming
the wrong one is worse than naming none"*. The storcli branch below it is
unaffected — it joins on the SAS address and already returns null on an
ambiguous prefix.

Note the same code is the reason a `phy` field alone can never be made
sufficient: it is *correct* for the controller's own drives and *wrong* for
everything behind an expander, with nothing in the value to tell them apart.

## Relationship to the plans around it

- **049 (duplicate PHY index)** — same defect *class*, different mechanism and
  different files. 049 is `${idx##*:}` collapsing `phy-H:N:M` in the `sas_phy`
  glob inside the three health collectors. This is `end_device-H:N:M` in the
  drives composer. 049 is **parked on a hardware confirmation** and its STOP
  conditions forbid widening it. **Do not touch `get_phy_health.sh`,
  `parse/storcli_phy.sh`, or any of 049's collectors in this plan.**
- **047 (graphical bay map)** — owns `bay_map_key()` and the migration rule this
  plan is bound by. 047 reasoned about key collisions only *across backends*
  (port 3 vs PHY 3), and its reference hardware is explicitly direct-attach
  with no expander, which is how the case got missed.
- **051 (SAS transport sysfs depth)** — last touched Stage 2, and created
  `tests/drives_sysfs_test.sh`, which is where this plan's fixture goes.
- **048 (activity-light locate)** — the bay cell's Locate button keys off the
  SCSI address, not the bay key, so it is unaffected. Do not change it.

## Scope

**In**: `get_attached_drives.sh` Stage 2, `parse/drives_join.sh`,
`bay_map.php` (`bay_map_key`, `bay_map_key_valid`), `ajax_info.php`
(`phy_drive` lsiutil branch only), `scripts/bundle_support.sh` (Step 1's one
`dump_attrs` line and its Section 3 description line — nothing else in that
file), `tests/drives_sysfs_test.sh`, `tests/bay_map_test.php`,
`tests/expected/drives_join.json`.

**Out**:

- **Everything 049 owns.** See above.
- **The storcli backend's `c<ctl>:p<port>` key.** `Connected Port Number` is the
  HBA port the drive is wired to, so on an expander backplane *every* drive
  behind that expander plausibly reports the same port — a worse collision than
  this one. **It is not measured, and this repo does not guess at hardware.**
  Recorded as an open question below, for the same box 049 is waiting on. Do
  not invent a storcli fix from the shape of this one.
- **Renaming what the UI displays.** Two tray cards reading "PHY 8" is
  confusing, but they will at least both be *present* and separately
  assignable after this. Cosmetic follow-up, not this plan.
- **Any migration of existing `bay_map.json` files.** The design below does not
  need one — see Step 3.

## Git workflow

Branch from `dev` (`bf99918`), not `main`.

**In a git worktree, `git switch dev` will fail** — `dev` is checked out in the
main tree and git refuses a second checkout of the same branch. Create the
branch at the right commit instead, which works either way:

```bash
git log --oneline -1                              # must show bf99918 or a descendant
git switch -c advisor/052-expander-bay-map-key bf99918
```

If `git log` shows something that is not `bf99918` or a descendant, the second
command still puts you on the correct base — but say so in your report, because
it means the worktree was cut from the wrong branch.

One commit per step, message ending in `(issue #12 follow-up)`.

## Steps

### Step 1: Confirm the end_device naming — evidence, not a code change

**The support bundle already captures most of this.**
`bundle_support.sh:390` runs `ls -l /sys/class/sas_device/`, which lists the
`end_device-*` names — so a bundle from any expander box answers whether the
three-part form exists. The ask is therefore "attach a support bundle", not a
hand-typed command, and it goes to @TheIlluminate92 alongside 049's outstanding
ring check — **one comment on issue #12, not two**.

What the bundle does *not* carry is the expander's own attributes, which is
what Step 2 reads. Add one line next to the existing dumps in
`bundle_support.sh` (the `end_device` dump at line 398 is the model to copy):

```bash
# The expander's own address — what plan 052 keys an expander-attached drive's
# bay assignment on. Its end_device siblings are dumped above; the expander
# itself was never captured, so no bundle could confirm the address is even
# readable without asking.
dump_attrs 03-sysfs/sas_expander.txt /sys/class/sas_device/expander-*
```

`dump_attrs` already writes "(no matching sysfs directories)" when the glob
misses, so this is inert on a direct-attach box. Update Section 3's one-line
description near `bundle_support.sh:453` to mention it.

**Proceed with Steps 2-6 regardless of the answer** — the fix is a no-op on any
box that produces no three-part name, which is every box measured so far. What
the evidence gates is the *claim*, not the code: see the STOP condition on
releasing this as a confirmed expander fix.

### Step 2: Carry the expander's identity out of sysfs

In Stage 2 of `drv_lsiutil`, derive the expander from the end_device's own
name and read its SAS address. Emit it as a fourth column, empty for
direct-attached:

```bash
        for ed in "$SYS_SAS_DEVICE"/end_device-*/; do
            [ -e "$ed" ] || continue
            sas=$(sed 's/0x//' "${ed}sas_address" 2>/dev/null | tr '[:lower:]' '[:upper:]' | tr -d ' \n')
            phy=$(tr -d ' \n' < "${ed}phy_identifier" 2>/dev/null)
            [ -n "$sas" ] || continue
            # end_device-H:N   -> attached to the HBA itself; phy_identifier is
            #                     an HBA PHY index and is unique per controller.
            # end_device-H:N:M -> attached to expander N; phy_identifier is the
            #                     EXPANDER's PHY number and collides with both
            #                     the HBA's own numbering and every other
            #                     expander's. Same naming rule plan 049 measured
            #                     for phy-H:N vs phy-H:N:M.
            # The expander's SAS address, not its index N, is what identifies it:
            # N is discovery order and can move across a reboot, and the one
            # store keyed on this is the one that cannot be rebuilt from hardware.
            name=$(basename "$ed"); name=${name#end_device-}
            exp=""
            case "$name" in
                *:*:*) exp=$(sed 's/0x//' "$SYS_SAS_DEVICE/expander-${name%:*}/sas_address" 2>/dev/null \
                             | tr '[:lower:]' '[:upper:]' | tr -d ' \n') ;;
            esac
            blk_dir=$(find "$(readlink -f "${ed}device")" -maxdepth 12 -type d -name 'block' 2>/dev/null | head -1)
            blk=$(ls "$blk_dir" 2>/dev/null | head -1)
            [ -n "$blk" ] || continue
            printf "/dev/%s %s %s %s\n" "$blk" "$sas" "${phy:-0}" "${exp:-.}"
        done
```

`.` rather than an empty field: `drives_join.sh` splits on whitespace and an
empty fourth column would silently shift into `$4` being unset — the same
field-desync hazard `drives_sysfs_test.sh` already guards for the SES-shaped
end device. Translate `.` back to empty in the join.

If the expander directory is unreadable, `exp` stays empty and the drive keys
exactly as it does today: degrade to the current behaviour, never to a
half-formed key.

### Step 3: Carry it through the join and into the key

`parse/drives_join.sh` — read the new column and emit it:

```awk
{
    sasmap[$1]=$2; phymap[$1]=$3; expmap[$1]=($4 == "." ? "" : $4)
}
```

```awk
        exp=(dev in expmap) ? expmap[dev] : ""
        printf "{\"bus\":%d,\"target\":%d,\"sas_address\":\"%s\",\"phy\":%d,\"expander\":\"%s\",\"os_name\":\"%s\"}",
            bus, tgt, sas, phy, exp, dev
```

`bay_map.php` — the disambiguator goes **in front of** the existing `h`
segment, and only when there is one:

```php
function bay_map_key(int $ctl, array $drive): ?string {
    if (isset($drive['phy']) && $drive['phy'] !== '') {
        // An expander-attached drive's PHY number is the expander's, so it is
        // unique only within that expander. Direct-attached drives keep the
        // 047 key shape byte-for-byte -- that is deliberate, and it is why this
        // needs no migration: on every box without an expander (which is every
        // box that can have a working map today) not one stored key changes.
        $exp = (string) ($drive['expander'] ?? '');
        return "c$ctl:" . ($exp !== '' ? "x$exp" : '') . 'h' . (int) $drive['phy'];
    }
    if (isset($drive['port']) && $drive['port'] !== '') return "c$ctl:p" . (int) $drive['port'];
    return null;
}

function bay_map_key_valid(string $key): bool {
    return (bool) preg_match('/^c\d{1,3}:(x[0-9A-F]{1,16})?[ph]\d{1,4}$/', $key);
}
```

The regex stays a whitelist, and `x` alone is still not a valid key — the
existing `'c0:x1'` rejection case in `bay_map_test.php:93` must keep passing.

### Step 4: Stop `phy_drive()` matching across the boundary (Defect B)

`ajax_info.php`, lsiutil branch only:

```php
    if (isset($drives[0]['phy'])) {
        foreach ($drives as $d) {
            // A drive behind an expander numbers its PHY in the expander's
            // namespace; these rows are the controller's own PHYs (plan 049).
            // Matching the two names the wrong bay, which this function's whole
            // contract says is worse than naming none.
            if (($d['expander'] ?? '') !== '') continue;
            if (isset($d['phy']) && (string) $d['phy'] === (string) ($phy['phy'] ?? '')) return $d;
        }
        return null;
    }
```

### Step 5: Tests

**`tests/drives_sysfs_test.sh`** — add Fixture C: one direct-attached drive and
two drives on two different expanders, all three reporting `phy_identifier 8`.
Build it the way the file already builds Fixture A (`mkdir -p` under `$ROOT`,
`printf` into the attribute files), plus the two expander dirs:

```bash
mkdir -p "$SAS/expander-0:1" "$SAS/expander-0:2"
printf '0x500304801aaaaa1f' > "$SAS/expander-0:1/sas_address"
printf '0x500304801bbbbb2f' > "$SAS/expander-0:2/sas_address"
```

Assert: three drives out, all with `"phy":8`, and three **distinct**
`"expander"` values (`""`, `500304801AAAAA1F`, `500304801BBBBB2F`).

**`tests/bay_map_test.php`** — in section 4:

- three drives, same ctl, same `phy`, different `expander` → three distinct keys;
- `bay_map_key(0, ['phy' => 8])` still `=== 'c0:h8'` (the no-migration promise,
  as an assertion rather than a comment);
- `bay_map_key_valid('c0:x500304801AAAAA1Fh8')` accepted;
- `'c0:x500304801aaaaa1fh8'` (lower case) rejected — one canonical spelling,
  since `bay_map_read()` matches keys byte-for-byte.

**Golden**: `tests/expected/drives_join.json` gains the field. Regenerate with
`UPDATE=1 bash tests/run.sh` and **read the diff** — the only change may be the
new `"expander":""` on each drive.

### Step 6: Mutation-test it

Revert Step 3's `bay_map.php` change alone, re-run `php tests/bay_map_test.php`,
confirm the three-distinct-keys assertion fails, restore. A test that passes
against the unfixed code proves nothing (plan 051, Step 5).

## Test plan

```bash
bash tests/drives_sysfs_test.sh     # drives_sysfs: all pass
php  tests/bay_map_test.php         # bay_map: all pass
bash tests/run.sh                   # whole suite, including the golden join
php  tests/run_php.sh
```

## Done criteria

- [ ] Two drives on two different expanders, both `phy_identifier 8`, produce
      two different bay-map keys and both appear in the tray
- [ ] A direct-attached drive's key is **unchanged** — `c0:h8`, byte for byte
- [ ] `bay_map_key_valid()` accepts the new shape and still rejects `c0:x1`
- [ ] `phy_drive()` returns null rather than an expander-attached drive
- [ ] Both new tests fail against the unfixed code (Step 6)
- [ ] Whole suite green
- [ ] `plans/README.md` status row updated, and the "Open defects" bullet for
      this finding replaced with a pointer to this plan

## STOP conditions

- The drift check reports any change, or `git log --oneline -1` does not show
  `bf99918` or a descendant.
- **Any edit reaches `get_phy_health.sh`, `parse/storcli_phy.sh`,
  `get_hba_health.sh`, `get_hba_info.sh` or `get_metrics.sh`.** Those are 049's,
  it is parked on a hardware confirmation, and widening its blast radius
  invalidates that confirmation. This has now been misread as one bug twice;
  it is two.
- Any change to an existing key produced for a direct-attached drive. That is a
  migration, and this plan is explicitly the version that does not need one.
- The storcli `p<port>` branch is touched.
- A fixture is invented that asserts hardware behaviour this plan lists as
  inferred. Test the *code path*; do not certify the *hardware shape*.
- **The changelog or release notes claim this fixes expander bay maps before
  Step 1's evidence is in.** Merging to `dev` on fixture proof is fine — the
  no-expander path is provably unchanged. Telling users it fixes their box is
  not, until a bundle shows the naming. Word it as "keys expander-attached
  drives separately" and hold the stronger claim.

## Maintenance notes

- **The payload gains a field.** `"expander"` appears on every lsiutil-backend
  drive, empty for direct-attached. Support bundles from before this change
  will not have it; every consumer must read it as `?? ''`.
- **Why the expander's SAS address and not its index.** The index (`N` in
  `end_device-H:N:M`) is discovery order and can renumber across a reboot. The
  address is in the expander's silicon. For a cache, the index would be fine
  and cheaper; for the one store that a person built by hand and cannot
  rebuild, it is not.
- **This is the third appearance of the same sysfs shape** — `phy-H:N:M` (049),
  the `port-H:B` layer 051's globs did not expect, and now `end_device-H:N:M`.
  Before adding any new sysfs reader, ask what the name's component count means.

## Open question for the reporter's hardware (not a code change)

Does the **storcli** backend collide the same way? `Connected Port Number` is
the HBA port, so several drives behind one expander may all report the same
value, which would make `c<ctl>:p<port>` ambiguous for far more boxes than this
plan's case. Needs one `storcli /c0/eall/sall show all | grep 'Connected Port
Number'` from a box with an expander running the storcli backend. Route it with
049's outstanding ring check; do not act on it before it is measured.
