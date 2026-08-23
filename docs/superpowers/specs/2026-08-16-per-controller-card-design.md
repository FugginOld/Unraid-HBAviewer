# The per-controller card, and what ajax_info.php actually is — design

Candidate 3 of the 2026-08-16 architecture review, rescoped after reading the
file properly.

## What the review got wrong

The review said `ajax_info.php` has "four request handlers buried inside the
render section". That is not what is there. Lines 241-264 hold ONE shared fetch
for the phy, drives, events and baymap tabs — one `$scriptMap`, one
`shell_exec`, one error path. Execution then falls through roughly 700 lines of
**function definitions** and reaches four `if ($type === …)` calls that render
and exit.

So the dispatch is not scattered; it is separated from its own fetch by every
renderer in the file. That is a layout problem, not a routing one, and it is
worth naming correctly because "lift the buried dispatches" would have produced
a worse file — moving four render calls above the functions they call.

## What is genuinely duplicated

Four renderers open with the same seven lines. Verbatim, modulo one word of
comment:

```php
$multi = count($ctls) > 1;
$out   = '';
foreach ($ctls as $i => $ctl) {
    $out .= '<div class="lu-card first" data-ctl="' . $i . '">';
    if ($multi) $out .= luCtlHead($i);
    if (isset($ctl['error'])) { $out .= '<p class="lu-muted">' . htmlspecialchars($ctl['error']) . '</p></div>'; continue; }
```

| Renderer | Line |
|---|---|
| `renderPhyTables` | 821 |
| `renderDrivesTables` | 976 |
| `renderEventsTables` | 1331 |
| `renderHealthTables` | 1406 |

Each carries a comment pointing at `renderOverviewCards` — the contract is
documented by cross-reference four times instead of by code once. The rule they
share is load-bearing: an errored controller must still get its own card, or it
renders as bare text floating between its neighbours'.

That loop is the deep module hiding in this file. A caller should say "render
one card per controller, and here is the body for one" and get the card shell,
the multi-controller heading, and the error branch for free.

## What the file is, by mass

1,502 lines, 33 functions. By concern:

| Concern | Functions | Approx lines |
|---|---|---|
| dispatch + fetch | — | 60-265 |
| shared (`luTable`) | 1 | 21 |
| smart | 5 | ~70 |
| overview cards | 6 | ~215 |
| phy | 7 | ~370 |
| drives | 5 | ~200 |
| unraid state + bay map | 5 | ~180 |
| events | 1 | ~65 |
| health | 2 | ~110 |

Only `luTable` is shared across families. Every other function belongs to
exactly one tab, which is what makes a per-tab split clean rather than arbitrary.

## Scope of this plan

1. **Own the per-controller card loop once.** Four call sites collapse onto it.
2. **Move each tab's renderers into its own file**, leaving `ajax_info.php` as
   dispatch, fetch and requires.

## Explicitly NOT in this plan

**Merging `renderGroupedCard` into `renderControllerCard`.** The review counted
23 normalised lines identical between them and called it a deletion-test pass.
It probably is — but they are 70 and 96 lines respectively, the grouped one
carries the dual-IOC sub-card layout that was hardware-verified only this
morning, and merging them is a design question (what does one function that
renders both shapes actually look like?) rather than a transcription. It
deserves its own brainstorm and its own plan. Doing it inside a file-move plan
is how a mechanical refactor acquires a behavioural bug.

## Constraints

1. No golden in `tests/expected/` may change, and no rendered-HTML expectation
   may change. `tests/expected/overview_single_pcie.html` pins exact markup.
2. `tests/ajax_render_test.php` requires `ajax_info.php` and calls its render
   functions directly, relying on the `if (PHP_SAPI === 'cli') return;` seam at
   line 60. That seam must keep working: after the split, requiring
   `ajax_info.php` must still define every render function, so the 292 existing
   assertions run unchanged.
3. The per-controller card contract — card shell, `luCtlHead` when multi, error
   branch that closes the card — must survive byte-identical in the output. It
   is what stops an errored controller rendering as loose text.
