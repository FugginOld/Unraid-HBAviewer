# Plan 031: Show the thermal indicator the Health gauge already counts

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 6e0e2cd..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php source/usr/local/emhttp/plugins/hbaviewer/health.php`
> Expected output: **nothing**. Every excerpt below is quoted from `6e0e2cd`
> (`dev` tip, 2026-08-01). Any difference is a STOP condition.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW — display only, no state or arithmetic changes
- **Depends on**: 030 (DONE, merged) — this fixes a defect that plan introduced
- **Category**: bug
- **Planned at**: `6e0e2cd`, 2026-08-01
- **Requested by**: maintainer, from a hardware screenshot of the Health tab

## The defect

On real hardware the Health tab reads **"4 / 5 indicators ok"** above a list of
**four rows, all green**. Nothing on screen accounts for the fifth.

The count is not wrong. `health_indicators()` returns five indicators and the
gauge counts all five; on the reporting box `thermal` was the non-ok one
(c0 `elevated` at 72 °C, c1 `warning` at 77 °C). But `thermal` is **never
rendered as a row**, so the number appears to contradict the list directly
beneath it.

Before plan 030 there was no count, so the missing row was invisible. Adding
the gauge exposed a gap that had been there all along.

## Current state

### `health.php:216-220` — five indicators are computed and returned

```php
        'thermal'        => $thermal,
        'link_integrity' => $link_integrity,
        'topology'       => $topology,
        'host_link'      => $host_link,
        'controller'     => $controller,
```

### `ajax_info.php:569` — only four are rendered

```php
        foreach (['link_integrity' => 'Link Integrity', 'topology' => 'Topology', 'host_link' => 'Host Link', 'controller' => 'Controller'] as $key => $label) {
```

`thermal` is absent. This is the whole bug.

### `health.php:142-148` — thermal already has the right shape

```php
        $thermal = [
            'state'  => $thermalMap[$band],
            'reason' => ($temp !== '' && $temp !== null) ? "{$temp}°C ({$band})" : ucfirst($band),
            'value'  => ($temp !== '' && $temp !== null) ? "{$temp}°C" : '—',
        ];
    } else {
        $thermal = ['state' => 'unknown', 'reason' => 'No temperature reading', 'value' => '—'];
    }
```

It carries `state`, `reason` and `value` exactly like the other four, so the
existing row renderer needs no special case — it will render `72°C` in the
value column and colour the dot from `state`.

### `hbaviewer.php:322` — the header already promises five

```html
<span style="font-size:12px;color:var(--text);">Thermal, link integrity, topology, host link, and read health —
```

Two things to note. It names **thermal first**, so the page already tells the
user to expect it. And it calls the fifth one **"read health"** while the row
is labelled **"Controller"** — a separate naming inconsistency, fixed here
because it is the same sentence and the same screen.

## Scope

**In scope**:

- Add `'thermal' => 'Thermal'` as the **first** entry in `ajax_info.php`'s
  indicator-row list, so row order matches the header sentence and the
  `health_indicators()` return order.
- Reconcile the header wording with the row labels: either the header says
  "controller" or the row says "Read Health". **Pick the row label to match the
  header** — `controller` is the array key, but "read health" is what the
  indicator actually reports, and the user-facing sentence is the one that was
  written deliberately.
- One render test asserting five rows appear and that the count in the gauge
  equals the number of `ok` rows shown.

**Out of scope** — do not touch any of these:

- `health.php`. The indicator logic, the states, the rollup and the gauge count
  are all correct. **This is a rendering fix only.**
- The gauge itself (`lsi_gauge_svg`, the `N / total` count, `health_rollup`).
- Removing `thermal` from the count to make it read `4 / 4`. That would silently
  drop temperature from the health rollup — a change in meaning, not a display
  fix, and it would undo part of plan 020.
- The band meter or the temperature pill. Thermal appearing in three places
  (pill, meter, row) is accepted redundancy: the pill is a summary, the meter is
  a scale, the row is the per-indicator detail that makes the count reconcile.

## Steps

### Step 1: add the row

```php
foreach (['thermal' => 'Thermal', 'link_integrity' => 'Link Integrity', 'topology' => 'Topology', 'host_link' => 'Host Link', 'controller' => 'Read Health'] as $key => $label) {
```

**Verify**: render a fixture where `thermal` is `warning` and the other four are
`ok`. Expect five rows, the thermal dot not green, and the gauge reading
`4 / 5`.

### Step 2: confirm the header now matches

Read `hbaviewer.php:322` and confirm the five names in the sentence correspond
one-to-one, in order, with the five rendered rows. Change nothing else in that
line.

## Test plan

Follow the repo's CLI harness — **not PHPUnit**. See `tests/ajax_render_test.php`
for the existing render-test convention and `tests/run_php.sh` for registration.

- Five rows render, in the header's order.
- A fixture with `thermal` unknown (no temperature — many SAS2008/9211 cards
  have no sensor) still renders the row, with `—` as its value, and is **not**
  counted as ok.
- The gauge's numerator equals the count of `ok` rows actually displayed. This
  is the assertion that would have caught the original defect.
- `bash tests/run.sh` → `--- all pass ---`.
- **No golden may move.** If one does, STOP — this is additive rendering inside
  an existing block.

## Done criteria

- [ ] Five indicator rows render on the Health tab
- [ ] `grep -c "'thermal' => 'Thermal'" ajax_info.php` → `1`
- [ ] Gauge numerator equals the number of green rows shown, asserted by a test
- [ ] Header sentence and row labels agree, in order
- [ ] `bash tests/run.sh` → `--- all pass ---`, `git diff -- tests/expected/` empty
- [ ] `php -l` clean on every touched file
- [ ] `health.php` unchanged (`git diff --name-only` must not list it)

## STOP conditions

- The drift check prints anything.
- Any golden changes.
- `health.php` appears in the diff.
- The fix is implemented by reducing the gauge's denominator instead of adding
  the row.

## Maintenance notes

- **The real lesson is that the row list was a hardcoded literal that had to be
  kept in step with `health_indicators()`'s return, and drifted.** If a sixth
  indicator is ever added, this same defect recurs. Deriving the labels from the
  returned keys — with a key→label map that fails loudly on an unknown key —
  would make that impossible; worth doing if a sixth indicator is ever proposed,
  but not worth the churn today for five.
- **A summary count and the list it summarises must be rendered from the same
  source.** That is what failed here, and the test in Step 1's verify is the
  cheapest guard against it happening again.
