# Plan 015: Show the temperature pill only while the tile is collapsed

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**:
> `git diff --stat 4858fb0..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/dashboard.php`
> If it changed since this plan was written, compare the "Current state" excerpts
> against the live code before proceeding; on a mismatch, treat it as a STOP
> condition.

## Status

- **Priority**: P3
- **Effort**: S
- **Risk**: LOW
- **Depends on**: 014 (merged to `dev` as `76d8666`)
- **Category**: direction (user-requested UI change)
- **Planned at**: commit `4858fb0`, 2026-07-29
- **Branch from**: `dev`

## Why this matters

The maintainer's request, verbatim:

> Can you make the temperature pill only appear when minimized?

The reasoning is redundancy. When a tile is expanded, the temperature is already
the largest thing on it — the circular gauge shows it at ~28px with a colour ring.
The pill in the header repeats the same number in 12px. When the tile is
**collapsed**, the gauge is gone (it lives in row 2) and the pill becomes the only
place the temperature appears, which is exactly what it was added for.

So: hide it while expanded, show it while collapsed.

This is two lines of CSS. No PHP change, no markup change, no backend, no tests.

## Current state

`source/usr/local/emhttp/plugins/hbaviewer/dashboard.php`.

**The collapse mechanism already exists and is verified working on hardware.**
Unraid collapses a dashboard tile by putting an inline `display: none` on every
`<tr>` after the first — no class, no attribute, nothing else to hook. Measured on
Unraid 7.2: expanded row 2 has `style=""`, collapsed row 2 has
`style="display: none;"`. The footer already uses this, at lines 75-76:

```css
.lu-d-tile .lu-d-foot-mini { display:none; padding:10px 0 2px; }
.lu-d-tile:has(> tr:nth-child(2)[style*="display: none"]) .lu-d-foot-mini { display:block; }
```

**That is the exact pattern to mirror.** Do not invent a different mechanism, and
do not add JavaScript.

**The pill's current rule**, immediately above it:

```css
.lu-d-tile .lu-d-pill {
  display:inline-flex; align-items:center; margin-right:8px;
  padding:3px 11px; border-radius:20px;
  font-size:12px; font-weight:700; letter-spacing:0.03em;
  font-family:ui-monospace,"SF Mono",Menlo,monospace; font-variant-numeric:tabular-nums;
  color:var(--tc,#2ecc71);
  border:1px solid color-mix(in srgb, var(--tc,#2ecc71) 55%, transparent);
  background:color-mix(in srgb, var(--tc,#2ecc71) 12%, transparent);
}
```

It is emitted inside row 1's header (`{$pill}`), which is why it survives collapse
at all. **Leave the PHP that builds and emits it completely alone** — the change is
purely which state it is visible in.

## Commands you will need

```bash
bash tests/run.sh          # must end "--- all pass ---"
bash tests/run_php.sh      # must exit 0
```

There is **no local `php`**. Lint through Docker — the `MSYS_NO_PATHCONV=1` prefix
is required on Git Bash or `-w /w` is mangled into `W:/`:

```bash
MSYS_NO_PATHCONV=1 docker run --rm -v "<abs-repo-path>:/w" -w /w php:8.2-cli \
  php -l source/usr/local/emhttp/plugins/hbaviewer/dashboard.php
```

Note `grep -c` **exits 1 when the count is 0**, which breaks `&&` chains. Separate
your checks with `;` or a run will look like it stopped early on a check that
actually passed.

## Scope

**In scope**: the CSS block in
`source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` — specifically the
`.lu-d-pill` rule and one new rule beside it.

**Out of scope** (do NOT touch):

- **The PHP that builds `$t['pill']`** (`$pillTemp`, the `<span class="lu-d-pill">`
  emission, the `--tc` inline colour). The pill's content and colour are correct;
  only its visibility changes.
- **`.lu-d-foot-mini` and its `:has()` rule.** Working and verified on hardware.
  You are copying the pattern, not editing it.
- **The `.lu-d-circle` gauge.** It stays as it is — it is the expanded-state
  temperature display and the reason the pill is redundant there.
- Any backend script, test, fixture or golden. This changes no JSON and no parser
  output.
- `hbaviewer.php` (the Monitor page), `ajax_info.php`, `view.php`.

## Git workflow

- **`git switch -c advisor/015-pill-only-when-collapsed dev`** — cut from `dev`,
  not `main`. A worktree provisioned from `main` has none of plans 012-014's tile
  markup and every excerpt above will mismatch.
- One commit. Short imperative subject, no conventional-commit prefix. Suggested:
  `Dashboard tile: show the temperature pill only while collapsed`
- Do not push and do not open a PR.

## Steps

### Step 1: Default the pill to hidden

In the `.lu-d-pill` rule, change the leading `display:inline-flex;` to
`display:none;`. Keep every other declaration exactly as it is — the padding,
border, colour and font all still apply once it becomes visible.

```css
.lu-d-tile .lu-d-pill {
  display:none; align-items:center; margin-right:8px;
```

**Verify**: `grep -c 'display:none; align-items:center' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` → prints `1`

### Step 2: Reveal it while collapsed

Add one rule. Put it directly after the existing `.lu-d-foot-mini` reveal rule so
the two collapse-triggered rules sit together and a future reader sees them as a
pair:

```css
/* The pill is redundant while expanded — the circle gauge shows the same
   temperature far larger. Collapsed, the gauge is gone (it lives in row 2) and
   the pill is the only place the temperature appears. Same collapse signal as
   .lu-d-foot-mini above: Unraid's inline display:none on row 2. */
.lu-d-tile:has(> tr:nth-child(2)[style*="display: none"]) .lu-d-pill { display:inline-flex; }
```

`inline-flex`, not `block` or `inline` — the pill uses `align-items:center` to
centre its text, which requires a flex container. `display:block` would break the
vertical centring; `display:inline` would drop the padding.

**Verify the new rule is present**: `grep -c 'lu-d-tile:has(> tr:nth-child(2)\[style\*="display: none"\]) .lu-d-pill { display:inline-flex; }' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` → prints `1`

**Verify both collapse-triggered rules now exist**: `grep -c 'lu-d-tile:has(> tr:nth-child(2)\[style\*="display: none"\])' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` → prints `2`

**Verify the footer rule is untouched**: `grep -c 'lu-d-tile:has(> tr:nth-child(2)\[style\*="display: none"\]) .lu-d-foot-mini { display:block; }' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` → prints `1`

### Step 3: Lint and suites

```bash
bash tests/run.sh
bash tests/run_php.sh
MSYS_NO_PATHCONV=1 docker run --rm -v "<abs-repo-path>:/w" -w /w php:8.2-cli \
  php -l source/usr/local/emhttp/plugins/hbaviewer/dashboard.php
```

All three must pass, and **neither suite should change at all** — this touches only
a CSS string inside a heredoc. Any golden churn means an unintended edit reached a
script; that is a STOP condition.

## Test plan

Nothing to automate. This alters two CSS declarations inside a heredoc; no test in
the repo renders `dashboard.php` (`tests/ajax_render_test.php` covers
`ajax_info.php`), and adding a CSS-string assertion would test the literal rather
than the behaviour.

**Operator will verify on hardware:**

1. **Tile expanded** — no pill in the header. The temperature appears only in the
   circular gauge.
2. **Click `^` to collapse** — the pill appears in the header, correctly coloured
   for that card's status, alongside the footer.
3. **Expand again** — the pill disappears.
4. On a two-HBA box, collapsing one tile must not make the other's pill appear.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `grep -c 'display:none; align-items:center' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` prints `1`
- [ ] `grep -c 'display:inline-flex; align-items:center; margin-right:8px' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` prints `0` — the pill's old always-visible default is gone. **The `margin-right:8px` is what makes this pill-specific**: a second rule (`.lu-d-badge`, line 52) also opens `display:inline-flex; align-items:center` and must keep it, so the shorter pattern would still return `1` and is not a valid check
- [ ] `grep -c 'lu-d-tile:has(> tr:nth-child(2)\[style\*="display: none"\])' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` prints `2`
- [ ] `grep -c 'lu-d-tile:has(> tr:nth-child(2)\[style\*="display: none"\]) .lu-d-pill { display:inline-flex; }' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` prints `1`
- [ ] `grep -c 'lu-d-tile:has(> tr:nth-child(2)\[style\*="display: none"\]) .lu-d-foot-mini { display:block; }' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` prints `1`
- [ ] `grep -c 'lu-d-pill' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` prints `3` — base rule, new reveal rule, emitted markup
- [ ] `grep -c '{$pill}' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` prints `1` — emission untouched
- [ ] `grep -c 'lu-d-circle' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` prints `5` — gauge untouched (four CSS rules plus the emitted `<div>`)
- [ ] `bash tests/run.sh` ends `--- all pass ---`
- [ ] `bash tests/run_php.sh` exits 0
- [ ] `php -l` on `dashboard.php` reports no syntax errors
- [ ] `git diff --stat dev..HEAD` shows exactly one file changed, `dashboard.php`
- [ ] `git status --porcelain` is empty after committing

## STOP conditions

Stop and report instead of improvising if:

- **Any golden file changes.** This plan alters no parser output.
- **The `.lu-d-foot-mini` reveal rule does not exist** in the file as quoted. It is
  the pattern this change copies and the proof the mechanism works; if it is
  missing, the file is not in the state this plan was written against.
- **You are tempted to use JavaScript, a `MutationObserver`, or an Unraid API to
  detect the collapse.** The CSS `:has()` approach is deliberate and measured. If
  it ever stops matching, the pill silently stays hidden — a degradation, not a
  crash — which is the intended failure mode.
- **You are tempted to also hide the gauge, move the pill, or restyle the header**
  now that the header has one fewer element while expanded. Out of scope. If the
  header looks unbalanced expanded, say so in your report and let the maintainer
  decide.

## Maintenance notes

- **There are now two rules keyed off the same collapse signal** — the pill and the
  footer. If Unraid ever stops collapsing tiles via an inline `display: none` on
  row 2, both stop working at once, and both fail silently rather than erroring.
  That single selector is the thing to check first if either misbehaves.
- **The pill is now invisible in the expanded state, which is the default state.**
  Anyone eyeballing the expanded tile to check pill colour or content will see
  nothing and may conclude it is broken. Collapse the tile to inspect it.
- **`display:inline-flex` on the reveal is load-bearing**, not stylistic — the
  pill relies on `align-items:center`.
- **What a reviewer should scrutinise**: that the footer's reveal rule was not
  edited while adding the pill's (they are adjacent and near-identical, which is
  exactly how a copy-paste ends up modifying the wrong one), and that the PHP
  emitting `{$pill}` is untouched.

---

## Execution record

- **Executed**: 2026-07-29, branch `advisor/015-pill-only-when-collapsed`
- **Commit**: `0f8527e` → merged to `dev` as `8249575`
- **Rounds**: 1 — approved as submitted, no REVISE
- **Files changed**: `dashboard.php` only (+6/-1)

### Outcome

Every done criterion passed first time, and the plan's greps were all accurate —
the second plan in a row with none defective, after 012 and 013 each shipped
unpassable checks. Pre-testing every pattern against the live file before dispatch
is what changed; it caught two bad criteria in this plan's own draft (see below).

### The adjacency trap was avoided

The new rule is near-identical to, and directly beneath, the footer's existing
`:has()` rule — differing only in the trailing selector and the `display` value.
That is the classic setup for a copy-paste that edits the existing rule instead of
adding a sibling, and the failure would have been silent: the footer would just
stop appearing on collapse, with nothing erroring.

The executor anchored its edit on the footer line so that line appears as
**unchanged context** in the diff, which is direct proof it survived byte-identical.
Confirmed on review.

### Two bad criteria caught before dispatch

Both were in this plan's first draft and would have failed against a *correct*
implementation:

1. **`grep -c 'display:inline-flex; align-items:center'` → `0`** was wrong.
   `.lu-d-badge` (the status chip) opens with the same two declarations and must
   keep them, so the count is `1` after a correct change. Tightened to include
   `margin-right:8px`, which is pill-specific. The executor independently
   confirmed the qualifier is load-bearing.
2. **`grep -c 'lu-d-circle'` → `2`** was wrong; the real count is `5` (four CSS
   rules plus the emitted `<div>`).

Worth recording what these near-misses would have cost: not a wrong
implementation, but an executor either reporting a false failure or — worse —
"fixing" correct code until a broken check passed.

### Verified independently before merge

- Full diff: exactly two hunks, both traceable to Steps 1 and 2. Footer rule
  present as unchanged context.
- All eight greps re-run: `1 / 0 / 2 / 1 / 1 / 3 / 1 / 5` as specified
- `bash tests/run.sh` → `--- all pass ---`; `bash tests/run_php.sh` → exit 0
- No golden churn
- `{$pill}` emission and the `.lu-d-circle` gauge both untouched

### Still open — needs hardware

The whole behavioural claim. Expanded: no pill. Collapsed: pill appears, correctly
coloured. On a two-HBA box, collapsing one tile must not reveal the other's pill.

The executor also noted it could not judge whether the expanded header now looks
unbalanced with one fewer element on that row — it declined to restyle, per the
STOP condition. That is a look-at-it-on-hardware question.
