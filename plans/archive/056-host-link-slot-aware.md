# 056 — Host Link judges against the slot, not the card's maximum

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**:
> `git diff --stat c3be441..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/health.php source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_health.sh source/usr/local/emhttp/plugins/hbaviewer/config.php tests/health_test.php`
> Expected output: **nothing**. Every excerpt below is quoted from `c3be441`
> (`dev` tip, 2026-08-06). Any difference is a STOP condition.
>
> **Worktree note**: a fresh worktree may be cut from `main`, not `dev`, and
> `git switch dev` FAILS inside a worktree because `dev` is checked out in the
> main tree. Run `git log --oneline -1`, then use the command in "Git workflow"
> below, which lands on the right base either way.

## Status

Not started. Closes **#13** and the Host Link half of the **#14** follow-up
comment ([comment 5207141796](https://github.com/FugginOld/Unraid-HBAviewer/issues/14#issuecomment-5207141796)).

## The reports, and what they have in common

**#13** — a Dell HBA355i / SAS3816, capable of Gen4 x8, which the Dell server
runs at Gen4 x4. Permanent Host Link warning, nothing to fix:

> "It is not an actual HBA problem and there isn't really anything for me to
> fix. Because HBAviewer compares the current link against the maximum
> capability of the card, I end up with a permanent Host Link warning."

They asked for a setting: disable the check, ignore width, ignore speed, or
"preferably let me set the expected link myself".

**#14 comment** — a card capable of PCIe 3.0 x8 in a chipset-limited x4 slot.
The opposite failure of the same bug, because that reporter's `max_width`
happens to read x4:

> "Host link says 'PCIe slot running at its full x4 width and 8.0 GT/s'.
> Technically this is inaccurate for my card. This model is capable of PCI
> Express 3.0 x8 but in my case the slot on the motherboard is limited to x4."

One is a false warning, the other is a false all-clear with confident wording.
Both come from the same mistake: **the check compares the card against itself.**
A card in a slot narrower than the card is the normal, correct, unfixable state
of a great many OEM servers, and it is neither a fault nor "full width".

## The current check — `health.php:269-286`

```php
    // ── host_link: current PCIe width/speed vs this slot's capability ──────────
    $link = $newest['link'] ?? [];
    $w  = (int) ($link['width'] ?? 0);  $mw = (int) ($link['max_width'] ?? 0);
    $s  = health_rate_number((string) ($link['speed'] ?? ''));
    $ms = health_rate_number((string) ($link['max_speed'] ?? ''));
    $widthDown = $mw > 0 && $w > 0 && $w < $mw;
    $speedDown = $ms !== null && $s !== null && $s < $ms;
    if ($widthDown || $speedDown) {
        $host_link = [
            'state'  => 'warning',
            'reason' => sprintf('PCIe link downtrained: x%d %s of x%d %s capable', $w, $link['speed'] ?? '', $mw, $link['max_speed'] ?? ''),
            'value'  => "x{$w} / x{$mw}",
        ];
    } else {
        $host_link = ['state' => 'ok', 'reason' => $w > 0
            ? "PCIe slot running at its full x{$w} width" . (($link['speed'] ?? '') !== '' ? " and {$link['speed']}" : '')
            : 'No PCIe downtraining reported', 'value' => $w > 0 ? "x{$w}" : '—'];
    }
```

The comment on line 269 already says "vs **this slot's** capability" — that is
what it was meant to do. `max_width` and `max_speed` are the **device's**, so
it never did.

## Where the data comes from — `scripts/get_hba_health.sh:108-116`

```bash
    pci=$(printf '%s\n' "$out" | grep -m1 -E '^PCI Address[[:space:]]*=' | sed 's/^[^=]*=[[:space:]]*//; s/[[:space:]]*$//')
    if [ -n "$pci" ]; then
        IFS=: read -r dom bus dev fn <<< "$pci"
        dir="${SYS_PCI_ROOT:-/sys/bus/pci/devices}/$(printf '%04x:%s:%s.%d' "0x${dom:-0}" "$bus" "$dev" "0x${fn:-0}")"
        val_out=$(cat "$dir/current_link_width" 2>/dev/null); width="${val_out:-0}"
        val_out=$(cat "$dir/max_link_width"     2>/dev/null); maxwidth="${val_out:-0}"
        speed=$(cat "$dir/current_link_speed" 2>/dev/null | sed -E 's/[[:space:]]*PCIe[[:space:]]*$//')
        maxspeed=$(cat "$dir/max_link_speed"  2>/dev/null | sed -E 's/[[:space:]]*PCIe[[:space:]]*$//')
    fi
```

**The slot's own capability is one directory up.** In sysfs the parent of a PCI
device is its upstream bridge — the slot — and that bridge publishes its own
`max_link_width` / `max_link_speed`. So the ceiling can be *measured*, not
configured, on any box whose bridge reports it.

Verify on the reporting hardware before building anything:

```bash
d=/sys/bus/pci/devices/0000:c1:00.0                     # the HBA
for f in current_link_width max_link_width current_link_speed max_link_speed; do
  printf '%-22s card=%-10s slot=%s\n' "$f" "$(cat $d/$f 2>/dev/null)" "$(cat $d/../$f 2>/dev/null)"
done
```

Expect on #13's box: card `max_link_width=8`, slot `max_link_width=4`.

## The fix, in three parts

### Part 1 (primary): measure the slot ceiling

Judge against `min(card max, slot max)` instead of the card's max. This alone
resolves both reports with **no configuration at all**, which is the outcome
worth aiming for — a setting that exists because the software could not work
something out is a worse answer than working it out.

### Part 2 (escape hatch): let the expected link be pinned

#13 explicitly asked to set it manually, and the bridge does not always report
(virtualised, some risers, some BIOSes). Two config keys, `0` meaning "auto —
use the measured slot ceiling":

```php
'PCIE_EXPECT_WIDTH' => [0, 0, 32],   // 0 = auto (the slot's own maximum)
'PCIE_EXPECT_GEN'   => [0, 0, 6],    // 0 = auto
```

This fits `LSI_SCHEMA`'s `[default, min, max]` int-only shape (`config.php:42-45`
clamps and casts to int), so no new config machinery is needed.

**Precedence**: explicit setting → measured slot ceiling → card maximum.
Below the expected link, still warn — #13 was clear that a genuine drop to x2 or
Gen3 must still be caught. This is not a mute button, and it must not be built
as one.

### Part 3: fix the wording (#14's actual request)

"PCIe slot running at its **full** x4 width" must stop claiming full when the
card can do more. State the two facts separately and let them stand:

- Card and slot agree → `Running at x8 8.0 GT/s — the full width of both card and slot`
- Slot is the limit → `Running at x4 8.0 GT/s — this slot's maximum (card supports x8)`
- Genuinely downtrained → keep the existing warning wording, which is correct
- Pinned by the user → say so: `Running at x4 8.0 GT/s — matches the expected link you set`

The third case must remain visibly a warning. Do not soften it while rewording
the other three.

## Test coverage does not exist yet — do this first

```bash
grep -c host_link tests/health_test.php     # expect 0
```

**`host_link` has no assertions at all.** `tests/health_test.php:22` builds a
fixture with a healthy link but nothing checks the indicator. So the behaviour
about to change is unpinned, and a regression here is invisible.

**Step 1 is therefore characterization tests against the CURRENT behaviour** —
written and green before any logic changes. Otherwise there is no way to show
this plan only changed what it meant to.

## Scope

**In**: `health.php` (the `host_link` block), `scripts/get_hba_health.sh` (read
the bridge), `config.php` (two schema keys), `settings.php` (two fields),
`tests/health_test.php`, `tests/health_sh_test.sh`, `hbaviewer.plg` changelog,
`HOWTO.md`.

**Out**:

- **The other four indicators.** Thermal, link integrity, topology and read
  health are untouched.
- **`health_rollup()` and the gauge.** Host Link keeps contributing exactly as
  it does; only its own verdict changes.
- **Per-controller settings.** The two keys are global. `LSI_SCHEMA` is a flat
  `KEY => int` map, and per-controller keys (`PCIE_EXPECT_WIDTH_C0`, …) would
  need a schema shape that does not exist yet. The measured slot ceiling is
  already per-controller and handles the mixed-slot case without any of that —
  which is the argument for doing Part 1 properly rather than reaching for
  config. Revisit only if someone reports two cards needing *different manual*
  pins.
- **A "disable Host Link entirely" toggle.** #13 listed it as one option among
  several and preferred setting the expected link. An indicator that can be
  switched off is one nobody reads; the expected-link setting covers the real
  need. Do not add it without a second request.
- **PHY-level link data** (`link_integrity`). Different indicator, different
  wire.

## Git workflow

```bash
git log --oneline -1                              # expect c3be441 or a descendant
git switch -c advisor/056-host-link-slot c3be441
```

One commit per step, message ending in `(plan 056)`.

## Steps

### Step 1: Pin the current behaviour

Add assertions to `tests/health_test.php` for today's four paths: matched
link → `ok`; narrower width → `warning`; slower speed → `warning`; missing link
data → the `No PCIe downtraining reported` branch. Assert `state`, `reason` and
`value`. Commit green, with no production change in the commit.

### Step 2: Collect the slot ceiling

In `get_hba_health.sh`, alongside the existing four reads, add the bridge's:

```bash
val_out=$(cat "$dir/../max_link_width" 2>/dev/null); slotwidth="${val_out:-0}"
slotspeed=$(cat "$dir/../max_link_speed" 2>/dev/null | sed -E 's/[[:space:]]*PCIe[[:space:]]*$//')
```

Emit them in the `"link"` object as `slot_width` and `slot_speed`. Extend
`tests/health_sh_test.sh`'s fake sysfs tree with a parent directory so this is
covered without hardware.

**Old samples in the ring have no `slot_*` keys.** The ring persists across
upgrades, so `health_indicators()` must treat them as absent and fall back to
the card maximum. Do not migrate the ring; degrade.

### Step 3: Judge against the ceiling

Rework `health.php:269-286`. Effective ceiling per dimension:

1. `PCIE_EXPECT_WIDTH` / `PCIE_EXPECT_GEN` if non-zero
2. else `slot_width` / `slot_speed` if present and > 0
3. else `max_width` / `max_speed` (today's behaviour, and the fallback for old
   samples)

Then `min(ceiling, card max)` — a slot wider than the card must not make an x8
card look downtrained.

`health_indicators()` currently takes `(array $ring, array $rates, int $now)`.
It needs the config; **inject it as a fourth parameter with a default**, do not
call `lsi_config_read()` inside it. The function is pure over its inputs and
`tests/health_test.php` depends on that.

### Step 4: Reword

Apply Part 3's four cases. Update the characterization assertions from Step 1 —
the warning path's wording is unchanged, so those assertions should still pass
untouched; if they do not, the warning path changed and that is a STOP.

### Step 5: Settings UI

Two fields in `settings.php` following the existing pattern, labelled so `0`
reads as automatic — e.g. "Expected PCIe width (0 = detect from the slot)".
Both belong beside the other advanced options, not at the top.

### Step 6: Changelog and docs

`hbaviewer.plg` entry crediting both reporters, and a short HOWTO note on what
Host Link now compares against and when to pin it.

## Test plan

```bash
bash tests/run_php.sh      # expect 14/14 "all pass"
bash tests/run.sh          # shell suite, including the extended health_sh test
```

Cases that must be covered by assertions, not by eye:

| Card | Slot | Setting | Expected |
|------|------|---------|----------|
| x8 Gen3 | x8 Gen3 | auto | `ok`, "full width of both card and slot" |
| x8 Gen3 | x4 Gen3 | auto | `ok`, names the slot as the limit, mentions x8 |
| x8 Gen3 | x4 Gen3 | width=4 | `ok`, "matches the expected link you set" |
| x8 Gen3 | x8 Gen3, running x4 | auto | **`warning`** — a real downtrain |
| x8 Gen4 | x4 Gen4, running x2 | width=4 | **`warning`** — below the pin |
| x8 Gen3 | absent (`slot_*` missing) | auto | today's behaviour exactly |

The fourth and fifth rows are the ones that matter. A change that turns every
Host Link green has failed, however happy the issue reporters are.

**On hardware**: #13's box (SAS3816 in a Gen4 x4 slot) should read `ok` with no
configuration. Ask the reporter to confirm before closing — the maintainer's own
box cannot reproduce a slot-limited card.

## STOP conditions

- The drift check prints anything.
- `grep -c host_link tests/health_test.php` is non-zero at the start — someone
  else added coverage and Step 1 needs rebasing onto it rather than duplicating.
- The bridge probe on real hardware shows the parent has no `max_link_width`, or
  reports the same value as the card on a known slot-limited box. Then Part 1 is
  not viable on that platform, and the plan reduces to Parts 2 and 3 — say so
  rather than shipping detection that silently does nothing.
- Any of the four downtrain-detection cases stops warning.
- `health_indicators()` ends up calling `lsi_config_read()` internally.

## Risks

**Highest: turning a real fault green.** The entire point of the indicator is
catching a card that has downtrained. Both reporters want fewer warnings, and
the easy way to give them that is to stop checking. The table above exists to
make that failure visible.

**Second: the ring buffer.** It survives upgrades and old entries have no
`slot_*`. Handled by the fallback in Step 3, and worth an explicit test.

**Third: `..` in sysfs.** The parent of a PCI device directory is the upstream
bridge in every layout seen so far, but this is worth confirming on the
reporting hardware (the probe above) before it becomes the primary code path.
