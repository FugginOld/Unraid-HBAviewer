# Review tooling policy

Three agent tools get pointed at this repo and they disagree, because they
govern different things. Superpowers governs *process* — spec, plan, TDD,
verify; its output lives in `docs/superpowers/`. ui-ux-pro-max governs *UX
rules*; its output lives in `design-system/MASTER.md`. Ponytail governs
*how much code gets written* — a YAGNI bias injected into every generation
turn. Only the first is a default here.

The reason this file exists: a finding should be accepted or rejected on
policy, not re-argued. Most of ARCHITECTURE.md is a list of places where the
simpler version shipped and was wrong, and a tool whose entire premise is
"take the lowest rung that works" will propose re-introducing several of
them, fluently.

## Scope

**Superpowers** — all code, default workflow. A spec in `docs/superpowers/`
does not outrank a golden. Where a plan and a test disagree, the test is
right and the plan carries the correction, per that directory's own habit of
recording where an earlier reading turned out to be wrong.

**Ponytail** — `/ponytail-review` on a diff, scoped to presentation and
packaging: `hbaviewer.js` / `flash_view.js` behaviour that isn't keyed to
`c.ctl`, `chrome.css`, `build.sh`, the workflow files. Do **not** run
`/ponytail-audit` against this tree. A whole-repo over-engineering scan reads
layered validation as redundancy, and nearly every guard here is layered on
purpose — shape, then size, then uniqueness, then membership, each catching
something the previous one cannot. Adjudicating those false positives costs
more than the true positives are worth.

**ui-ux-pro-max** — consulted through `--domain ux` and `--domain icons`
only, as a source of rules to check the code against. Never
`--design-system`, and never `--persist`: `design-system/MASTER.md` is
*derived* from `chrome.css`, `tokens.css` and the renderers, and a generated
one would overwrite a description of this UI with a description of a
different UI. This plugin is a guest inside the Dynamix webGui — it inherits
the user's theme through `tokens.css`, ships no fonts and adds no framework.
Any finding that proposes a font, a utility framework, or a colour not
reachable from a theme variable is out of scope by construction.

## Findings that are rejected on sight

A proposal to delete, collapse or "simplify away" any of the following is a
defect in the review. Each one is here because the simpler version was tried.

**On the flash path.** Every gate in `flash_preflight()`, including its
fail-closed behaviour on a missing `card` — the one gate that did not fail
closed was the most dangerous one there. The membership derivation in
`flash_cards_from()` and its dropping of groups smaller than the board's
declared `ioc_count`. The ordering that puts `flash_card_chips()` before
`flash_claim_lock()`. The separation of exit 7 from exit 6. `flash_rc` being
reset inside the loop rather than left unset. The absence of `-fwall` and
`-listall`. The controller-list loop for dual-IOC boards. The typed
confirmation and the acknowledgement, which are two things and not one.

Corollary: the greyed-out Step 3 is an affordance. A finding that treats
client-side state as the control has inverted the safety model, whatever
else it says.

**On the read paths.** `hba_is_sas_proc` as the single place the personality
list lives. `hba_driver()` being keyed on personality rather than module.
`use_storcli` probing rather than trusting a filename. The four parallel
`storcli2_*` parsers — their payload shape is identical to the `storcli` ones
*deliberately*, and merging them is the most attractive-looking wrong edit in
this repo; only `storcli_events.sh` is shared, and only because exactly one
key differs. The 15-digit prefix comparison in the SAS join and its
fail-closed behaviour on a non-unique prefix. `expander` in the drives
payload and its folding into `bay_map_key()`. `min(card, slot)` for PCIe
width. `-s` rather than `-f` in `cached_read()`.

**On state and signal.** Any `unknown`, `unmeasured`, `stale` or grey path.
Absence is not health, and a tri-state collapsed to a boolean is a lie with a
green pill on it. Baseline-relative PHY reporting — raw counters are not a
simpler equivalent. The exclusion of un-baselined PHYs from top offenders;
ranking them zero means "measured and clean". `"phy":null` from the
Performance poll on a SAS4 eHBA card. Session gating on `export.php` in
either format. Anonymisation in the diagnostic bundle.

**On structure that looks like noise.** Consts declared above the dispatch
guard — this shipped once and blanked the SMART tab. The inline `<script>`
block above the `<script src>` in `hbaviewer.php` and `flash_view.php`, and
`$csrfToken` being read unconditionally rather than inside a feature flag.
The `.js` files existing as `.js` at all: they spent a year inside two `.php`
files where nothing analysed them. The absent plugin-side CSRF check, which
is marked do-not-re-attempt for a reason.

**On the cron and verification paths.** `TRACK_HISTORY` being its own switch
rather than a rider on `ENABLE_NOTIFY` — one feature hidden behind another's
toggle is not a saved setting, it is two unrelated conditions nobody
discovering the plugin would connect (`7d6e8f2`). The notify branch in
`notify_check.php` ending in `return` and not `exit(0)`: the exit sat above
the history sample, so any box whose overview read hiccupped stopped
collecting trend data silently, with the setting still ticked. The content
`diff -r` of the extracted package against the installed tree in
`install-verify.sh`, which runs *before* every check below it — a package
checksum is not the shorter equivalent, because makepkg is not
byte-reproducible across machines and the same commit built twice gives two
sums. That script reported PASS twice against a stale tarball before this
landed (`05a4578`).

Anything named in **Where the sharp edges are** is on this list whether or not
it is repeated here.

## Documented boundaries

Read the comment before proposing the change; these explain intent but no
failure has shipped behind them.

`renderHealthTables()` appends to the health ring — the same store the cron
sampler feeds. The ring growing after `install-verify.sh` runs therefore
proves nothing about the cron, which is the wrong conclusion most available
while debugging it.

## Arbitration

1. A failing golden or unit test refutes a finding. Fixtures are evidence.
2. Correctness over concision. Where Ponytail and a guard conflict, the guard
   stands and the finding is closed, not deferred.
3. Host convention over design opinion. Where a UI finding conflicts with
   Dynamix markup or theme variables, the host wins.
4. The mutating paths are reviewed for correctness and not for style.
5. Where `MASTER.md` and the code disagree the code is the bug — but that is a
   licence to fix the code, not to let a tool rewrite `MASTER.md`.

## Cadence and disposal

Review on trigger, not on a calendar: a change under `flash.php` or
`flash_hba.sh`, a chipset or generation added, a new tab or renderer, a new
`.js` surface, and each tagged release.

Findings become issues or are rejected in the same session. Review reports
are not committed. A retained report is read as context by the next pass and
its contents re-reported, which is how a repo ends up with three documents
describing the same finding in three vocabularies — the state this file was
written to end.
