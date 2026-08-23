# One Backend Fact, One Default Implementation Plan

> **Status: COMPLETE.** Shipped. `lsi_backend_shape()` is the single backend question.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the PHP side read the `backend` field and nothing else, route storcli discovery through the shell's one implementation, and collapse `ALERT_THRESHOLD`'s four default declarations to one.

**Architecture:** Three renderers in `ajax_info.php` currently pair the explicit `backend` field with a key-sniff fallback; the sniffs are deleted. `settings.php` re-implements storcli discovery in PHP with a path list that has already diverged from `lib.sh`'s; it is replaced by a call into `find_storcli`. `config.sh` and `config.php` each restate `ALERT_THRESHOLD`'s default and disagree; the shell view derives its value instead of restating it.

**Tech Stack:** PHP 8 (Unraid's bundled), Bash 5, golden-file and assertion tests via `tests/run.sh` and `tests/run_php.sh`.

**Spec:** `docs/superpowers/specs/2026-08-16-one-backend-fact-design.md`

## Global Constraints

- **No file in `tests/expected/` may change.** `bash tests/run.sh` must end with `--- all pass ---` after every task. Never run `UPDATE=1` (it has a known quirk that rewrites four unrelated goldens).
- Run everything from the repo root. Branch is `advisor/codebase-improvements`.
- **`event_shape()` and `event_visible()` in `event_archive.php` are OUT OF SCOPE and must not be touched.** They classify archived entries on `/boot` whose provenance the current `backend` field cannot answer — a box that switched backends has both shapes in one file. Reading shape there is correct. An earlier review called this a leak; it was wrong.
- `find_storcli` in `scripts/lib.sh` is the single implementation of storcli discovery. Do not change it, and do not fork it again.
- shellcheck runs in CI at `-S warning` and is not installed locally, but is reachable via docker:
  `MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W)":/mnt -w /mnt koalaman/shellcheck:stable -S warning -e SC1090,SC2034,SC2207,SC1007 <files>` — must exit 0 for any `.sh` file changed.
- PHP suite alone is `bash tests/run_php.sh`; it runs php via docker if no local php.
- **There is no user-visible behaviour change in this plan.** `ALERT_THRESHOLD` becomes 76 everywhere. `band_of` buckets 76-85 into the same "warning" band, so a box with the key missing bands and badges identically before and after — only the number printed on the Settings page's "Badge Sensitivity" line changes. 80 is not one of the four legal band floors (66/76/86/96) that `config.php:58-60` documents; 76 is, which is the reason to prefer it.
- Commit style: a sentence saying what changed and why, no `feat:`/`chore:` prefixes.

---

### Task 1: Delete the three key-sniff fallbacks

**Files:**
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php:906-907`, `:1037-1038`, `:1352-1353`
- Test: `tests/ajax_render_test.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: nothing later tasks depend on.

- [ ] **Step 1: Confirm the sniffs are unreachable before deleting them**

```bash
grep -n "backend" source/usr/local/emhttp/plugins/hbaviewer/scripts/lib.sh | grep printf
grep -n "isset(\$data\['error'\])" source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php
```

Expected: `hba_each` emits `"backend"` on both the storcli and lsiutil paths, and `ajax_info.php` returns early on the `error` payload before any renderer runs. Those two facts are why a renderer can never see a payload without a `backend`. Record what you saw in your report.

- [ ] **Step 2: Write the characterization test**

**There is no red-first test here, and pretending otherwise would be a lie.** The sniff branch requires `($data['backend'] ?? '') === ''` — it cannot fire on a payload that states a backend, so it never misrenders a real payload. It is unreachable code, and unreachable code has no failing test. What these checks do instead is pin the contract that survives the deletion, so a future change that reintroduces sniffing fails.

Add to `tests/ajax_render_test.php`, after the existing PHY renderer checks (the block around line 86 that tests error and empty payloads):

```php
/* The backend field is the ONLY input that picks columns. CONTEXT.md says so.
   These are characterization checks, not regression ones: the key-sniff they
   replaced could only fire when `backend` was absent entirely, which no live
   payload is -- hba_each stamps both paths and the {"error":…} payload returns
   before any renderer runs. First pair: a stated backend decides even when the
   keys look like the other one. Second pair: with NO backend at all, the
   renderers now fall to the lsiutil table rather than guessing from keys. */
$sniffBait = ['backend' => 'lsiutil', 'controllers' => [['phys' => [
    ['phy' => 0, 'link' => 'up', 'speed' => '12.0 Gbps', 'sas_addr' => 'AABB',
     'inv' => 0, 'disp' => 0, 'sync' => 0, 'reset' => 0],
]]]];
check('phy: stated backend wins over storcli-looking keys',
    !str_contains(renderPhyTables($sniffBait), 'Attached SAS Address'));

$drvBait = ['backend' => 'lsiutil', 'controllers' => [['drives' => [
    ['slot' => '0', 'model' => 'X', 'serial' => 'S', 'state' => 'JBOD',
     'sas_address' => 'AABB', 'size' => '1 TB', 'link' => '12.0Gb/s', 'firmware' => 'A'],
]]]];
// 'Encl:Slot' is the storcli drives header and 'Bus:Tgt' the lsiutil one --
// those are the discriminators. ('Enclosure' is NOT: it appears only in a PHY
// topology summary, so asserting on it passes on both branches and tests
// nothing.) Asserting both directions proves which table rendered, not merely
// which one did not.
$drvOut = renderDrivesTables($drvBait);
check('drives: stated backend wins over storcli-looking keys',
    str_contains($drvOut, 'Bus:Tgt') && !str_contains($drvOut, 'Encl:Slot'));

// No backend stated: no guessing. These two FAIL before the deletion and pass
// after, which is the only behavioural difference the change makes.
$noBackendPhy = ['controllers' => [['phys' => [
    ['phy' => 0, 'link' => 'up', 'speed' => '12.0 Gbps', 'sas_addr' => 'AABB',
     'inv' => 0, 'disp' => 0, 'sync' => 0, 'reset' => 0],
]]]];
check('phy: an unstamped payload does not sniff its way to storcli columns',
    !str_contains(renderPhyTables($noBackendPhy), 'Attached SAS Address'));

$noBackendDrv = ['controllers' => [['drives' => [
    ['slot' => '0', 'model' => 'X', 'serial' => 'S', 'state' => 'JBOD',
     'sas_address' => 'AABB', 'size' => '1 TB', 'link' => '12.0Gb/s', 'firmware' => 'A'],
]]]];
$noBackendDrvOut = renderDrivesTables($noBackendDrv);
check('drives: an unstamped payload does not sniff its way to storcli columns',
    str_contains($noBackendDrvOut, 'Bus:Tgt') && !str_contains($noBackendDrvOut, 'Encl:Slot'));
```

- [ ] **Step 3: Run the test and record which checks fail**

```bash
bash tests/run_php.sh 2>&1 | grep -E "^FAIL|ajax_render"
```

Expected: the two `stated backend wins` checks PASS already (the sniff cannot fire when a backend is stated — that is the point). The two `unstamped payload` checks FAIL, because today the sniff sees `speed`/`slot` and renders storcli columns. Those two are the deletion's entire behavioural footprint.

Record exactly which passed and which failed. **If the split is anything other than 2 pass / 2 fail, stop and report it** — an assertion that passes when it should fail is testing the wrong string, and a check that cannot fail is worse than no check. (An earlier draft of this task asserted on `'Enclosure'`, which appears in neither drives table; it passed on both branches and pinned nothing.)

- [ ] **Step 4: Delete the three sniffs**

At `ajax_info.php:906-907`, replace:

```php
        // storcli backend if stamped; fall back to key-sniff pre-rollout.
        if ($storcli || (($data['backend'] ?? '') === '' && isset($phys[0]['speed']))) {
```

with:

```php
        // The backend field, and nothing else. It is always stamped: hba_each
        // writes it on both paths, and the {"error":…} payload returns long
        // before any renderer runs. The key-sniff that used to sit here read
        // storcli columns onto an lsiutil payload whose keys happened to match.
        if ($storcli) {
```

At `:1037-1038`, the same replacement with this comment:

```php
        // The backend field, and nothing else -- see the PHY renderer above.
        if ($storcli) {
```

At `:1352-1353`, likewise:

```php
        // The backend field, and nothing else -- see the PHY renderer above.
        if ($storcli) {
```

- [ ] **Step 5: Run the tests to verify they pass**

```bash
bash tests/run_php.sh 2>&1 | grep -E "^FAIL|ajax_render"
```

Expected: `ajax_render: all pass`, including both new checks.

- [ ] **Step 6: Run the full suite**

```bash
bash tests/run.sh 2>&1 | grep -E "^FAIL|FAILURES|^---"
```

Expected: `--- all pass ---`. Every existing render fixture states its backend explicitly, so nothing else should move.

- [ ] **Step 7: Commit**

```bash
git add source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php tests/ajax_render_test.php
git commit -m "Pick columns from the backend field alone

Three renderers paired the explicit backend field with a key-sniff fallback,
commented 'pre-rollout'. The sniff could only fire when the field was absent
ENTIRELY, and no live payload is: hba_each stamps it on both paths, and the
{\"error\":…} payload returns before any renderer runs. So this removes
unreachable code rather than fixing a misrender -- worth saying plainly, because
the plan that asked for it claimed the latter and was wrong.

What it buys is that CONTEXT.md's rule (\"PHP reads the explicit backend field
to pick columns -- no key-sniffing\") is now true of the code, and the
ajax_info.php split has three fewer branches to carry.

Four checks pin the contract: a stated backend decides even when the keys look
like the other one, and an unstamped payload falls to the lsiutil table instead
of guessing."
```

---

### Task 2: Route settings' storcli lookup through the shell's one implementation

**Files:**
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/settings.php:37-43`

**Interfaces:**
- Consumes: `find_storcli` from `scripts/lib.sh` (unchanged).
- Produces: nothing later tasks depend on.

- [ ] **Step 1: Confirm the divergence before changing anything**

```bash
sed -n '/^find_storcli()/,/^}/p' source/usr/local/emhttp/plugins/hbaviewer/scripts/lib.sh
sed -n '37,43p' source/usr/local/emhttp/plugins/hbaviewer/settings.php
```

Expected: the shell checks eight locations, the PHP four plus a `command -v` fallback. The PHP omits `/usr/local/bin/storcli` and `/usr/local/bin/storcli64`. Record both lists in your report.

- [ ] **Step 2: Replace the PHP probe**

In `settings.php`, replace this block:

```php
$storcli  = '';
foreach (['/usr/local/sbin/storcli','/usr/local/sbin/storcli64','/usr/sbin/storcli','/usr/sbin/storcli64'] as $c) {
    if (is_executable($c)) { $storcli = $c; break; }
}
if ($storcli === '') {
    $w = trim((string) shell_exec('command -v storcli storcli64 2>/dev/null'));
    if ($w !== '') $storcli = strtok($w, "\n");
}
```

with:

```php
// One implementation of this lookup, and it lives in the shell. This page used
// to carry its own four-path list against lib.sh's eight, already missing
// /usr/local/bin/storcli* -- two copies of one question that had drifted apart.
// Sourcing lib.sh runs nothing: its top level only assigns variables and
// defines functions. shell_exec is not new here; the old fallback used it too.
$storcli = trim((string) shell_exec(
    'bash -c ". /usr/local/emhttp/plugins/hbaviewer/scripts/lib.sh 2>/dev/null; find_storcli" 2>/dev/null'
));
```

- [ ] **Step 3: Verify the page still resolves storcli the same way**

On a box with storcli installed this must print its path; on one without, nothing:

```bash
bash -c '. source/usr/local/emhttp/plugins/hbaviewer/scripts/lib.sh 2>/dev/null; find_storcli'; echo "[exit $?]"
```

Expected: either an absolute path or empty output, and no error text. Record which you got. Sourcing must not print anything of its own — if it does, the value is polluted and this approach needs revisiting; report that rather than working around it.

- [ ] **Step 4: Run the full suite**

```bash
bash tests/run.sh 2>&1 | grep -E "^FAIL|FAILURES|^---"
```

Expected: `--- all pass ---`.

- [ ] **Step 5: Commit**

```bash
git add source/usr/local/emhttp/plugins/hbaviewer/settings.php
git commit -m "Ask the shell where storcli is, instead of guessing again in PHP

The Settings page carried its own four-path probe against lib.sh's eight, and
had already drifted: it never looked in /usr/local/bin, so a storcli installed
there and absent from PATH was found by every composer and missed by the one
page that reports whether storcli is present. It now calls find_storcli."
```

---

### Task 3: One declaration of `ALERT_THRESHOLD`'s default

**Files:**
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/scripts/config.sh:10`
- Modify: `hbaviewer.plg:496`
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/settings.php:74`
- Test: `tests/config_test.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: nothing.

- [ ] **Step 1: Establish what each site currently says**

```bash
grep -rn "ALERT_THRESHOLD" hbaviewer.plg source/usr/local/emhttp/plugins/hbaviewer/config.php source/usr/local/emhttp/plugins/hbaviewer/settings.php source/usr/local/emhttp/plugins/hbaviewer/scripts/config.sh
```

Expected: four declarations, three saying 80 and `config.php`'s `LSI_SCHEMA` saying 76. Record them.

- [ ] **Step 2: Write the failing test**

Add to `tests/config_test.php`, after the existing default checks:

```php
/* One declaration, two views. The shell reads the same cfg file PHP does, and
   used to restate the default itself -- with a different number. On a box whose
   cfg lacks the key, the shell banded temperatures against 80 while PHP labelled
   them against 76. 80 was never a legal value for what this setting means: it is
   the FIRST BAND at which the badge complains, stored as that band's floor, and
   the floors are 66/76/86/96. */
$shellDefault = trim((string) shell_exec(
    'LSI_CFG_PATH=/nonexistent bash -c '
    . escapeshellarg('. ' . __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/scripts/config.sh; printf %s "$ALERT"')
));
check('shell and PHP agree on the ALERT_THRESHOLD default',
    $shellDefault === (string) LSI_SCHEMA['ALERT_THRESHOLD'][0]);
check('the default is a real band floor',
    in_array((int) $shellDefault, [66, 76, 86, 96], true));
```

- [ ] **Step 3: Run the test to verify it fails**

```bash
bash tests/run_php.sh 2>&1 | grep -E "^FAIL|config:"
```

Expected: both checks FAIL — the shell says 80, PHP says 76, and 80 is not a band floor.

- [ ] **Step 4: Make the shell derive the default instead of restating it**

In `scripts/config.sh`, replace:

```bash
ALERT="${ALERT_THRESHOLD:-80}"
```

with:

```bash
# 76, matching config.php's LSI_SCHEMA. This file's header claimed "defaults
# live once, here" while config.php declared a different number, so a box whose
# cfg lacked the key banded temperatures against 80 in the shell and labelled
# them against 76 in PHP. The two are still written in two places -- a shell
# script cannot read a PHP const, and parsing one would trade a wrong number for
# a fragile one -- but tests/config_test.php now fails if they ever disagree,
# which is the guarantee the comment was claiming all along.
ALERT="${ALERT_THRESHOLD:-76}"
```

- [ ] **Step 5: Bring the other two sites to the same value**

In `hbaviewer.plg:496`, change the install-time write:

```bash
  printf "HBA_PORT=1\nALERT_THRESHOLD=76\n" > /boot/config/plugins/hbaviewer/hbaviewer.cfg
```

In `settings.php:74`, change the POST fallback:

```php
        'ALERT_THRESHOLD' => $_POST['threshold'] ?? 76,
```

- [ ] **Step 6: Run the tests to verify they pass**

```bash
bash tests/run_php.sh 2>&1 | grep -E "^FAIL|config:"
```

Expected: `config: all pass`, including both new checks.

- [ ] **Step 7: Decouple the three routing goldens that were pinning the default**

Three composer-level checks in `tests/run.sh` — `route-storcli`, `route-storcli-dual` and `route-lsiutil` — set no `ALERT_THRESHOLD` and no `LSI_CFG_PATH`, so their `alert_threshold` field records whatever the shell default happens to be. They are routing tests; the threshold is incidental to them, and inheriting it silently couples three unrelated goldens to a config default.

Add an explicit `ALERT_THRESHOLD=76` to the environment prefix of those three `check` lines, with this comment above the first of them:

```bash
# These three pin backend ROUTING, not thresholds — but they set no cfg, so
# their alert_threshold field used to record whatever the shell default was.
# Stating it here decouples them: the default itself is pinned once, in
# tests/config_test.php, and changing it no longer moves three routing goldens.
```

- [ ] **Step 8: Regenerate exactly those three goldens, and verify field by field**

The three expectations move `alert_threshold` from 80 to 76. Do NOT use `UPDATE=1` — it rewrites every golden and strips trailing newlines from four unrelated files. Regenerate only these three, by hand:

```bash
cd tests
for f in storcli_multi storcli_dual lsiutil_overview; do cp "expected/$f.json" "/tmp/$f.before"; done
cd ..
```

Then run the suite once to see the three failures, and for each one confirm from the `diff` output that `alert_threshold` is the ONLY field that moved. Write the three diffs into your report. If any other field differs on any of the three, STOP and report — that is a real regression this change would otherwise mask.

Only once you have confirmed all three diffs are the single field, update those three files by editing the `alert_threshold` value in place:

```bash
cd tests && sed -i 's/"alert_threshold": *80/"alert_threshold": 76/; s/"alert_threshold":80/"alert_threshold":76/' \
  expected/storcli_multi.json expected/storcli_dual.json expected/lsiutil_overview.json && cd ..
git diff --stat tests/expected/
```

Expected: exactly three files changed, one line each.

- [ ] **Step 9: Run the full suite and shellcheck**

```bash
bash tests/run.sh 2>&1 | grep -E "^FAIL|FAILURES|^---"
MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W)":/mnt -w /mnt koalaman/shellcheck:stable \
  -S warning -e SC1090,SC2034,SC2207,SC1007 source/usr/local/emhttp/plugins/hbaviewer/scripts/config.sh
```

Expected: `--- all pass ---` and shellcheck exit 0. No golden other than those three may differ from `HEAD` — check with `git diff --stat tests/expected/`.

- [ ] **Step 8: Commit**

```bash
git add source/usr/local/emhttp/plugins/hbaviewer/scripts/config.sh hbaviewer.plg source/usr/local/emhttp/plugins/hbaviewer/settings.php tests/config_test.php
git commit -m "Declare the alert default once, and make it a legal value

ALERT_THRESHOLD had four declarations and two values: 80 in the plg, config.sh
and settings.php, 76 in config.php's schema. On a box whose cfg lacked the key
the shell banded against 80 while PHP labelled against 76.

76 is the value that survives, because 80 was never legal for what this setting
now means -- the FIRST BAND at which the badge complains, stored as that band's
floor, and the floors are 66/76/86/96. config.sh derives it from the schema
instead of restating it, so its header comment is true for the first time.

Only a box with the key missing changes behaviour: the badge complains one band
earlier, which is what the setting was always meant to say."
```

---

## Self-review notes

Checked against the spec, 2026-08-16:

- **Spec coverage.** Part A (three sniffs) → Task 1. Part A's exclusion of `event_shape`/`event_visible` → Global Constraints, and no task touches `event_archive.php`. Part B (divergent probe) → Task 2. Part C (four declarations) → Task 3, including the 76-over-80 preference the spec recommends (a legal-floor choice, not a behaviour change — `band_of` puts both in "warning").
- **Type consistency.** No task defines an interface another consumes; the three are independent and could be done in any order. Task 1 and Task 3 both add checks to existing PHP test files and neither renames anything.
- **Task 3 leaves the number written twice, deliberately.** A first draft had `config.sh` parse `LSI_SCHEMA` out of `config.php` with a `sed`. That trades a wrong number for a fragile one: the shell would gain a dependency on a PHP file's text formatting, and a reformat would silently stop it tracking. Since the new test fails the moment the two disagree, a literal plus a test is both simpler and stricter than a parse. The second check ("is a real band floor") catches the other failure — both sites agreeing on a value that is not one of 66/76/86/96.
- **Not attempted.** Candidates 2, 3, 5 and 6 of the architecture review. Candidate 3 (`ajax_info.php` split) is the natural next one and is unaffected by this plan, though Task 1 removes three lines it would have had to move.
