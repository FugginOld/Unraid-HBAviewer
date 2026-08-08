# 057 — Extract the inline JavaScript so something can lint it

**State:** **DONE, archived 2026-08-08.** Executed on `dev`, suite green, both
`.js` files pass `node --check`, both `.php` files pass `php -l`, and
**confirmed working in a browser on the maintainer's box** — the check this plan
said no test here could perform. One deviation, recorded in this plan's row in
`plans/README.md`: the cache buster is `filemtime`, not the plg version, because
no PHP file here has access to the plg version and the plan forbade inventing
plumbing for it.

Written 2026-08-08 against `57543bd` on `dev`.

**Why now:** `dev` just gained ShellCheck, PHPStan and actionlint (`bf87d73`),
so every language this repo ships is statically analysed — except the largest
body of JavaScript in it, which no tool can see because it lives inside two
`.php` files.

---

## The gap, measured

| Where | Inline JS | PHP interpolations inside it |
|---|---|---|
| `hbaviewer.php` lines 216–1197 | 981 lines | one, at line 363 |
| `flash_view.php` lines 134–266 | 132 lines | two, at lines 138 and 151 |

That is ~1,113 lines. For scale, the repo's only tracked `.js` file is an
archived design handoff under `plans/archive/047/`, and `chart.umd.min.js` is
gitignored and fetched by `build.sh`. So **all** of this plugin's first-party
JavaScript is currently invisible to linting:

- GitHub linguist counts those bytes as PHP (hence "JavaScript 8.9%" being
  entirely an archived plan file, not shipped code)
- PHPStan sees inert markup inside a template
- Codacy's jshint only opens files ending in `.js`

This is the code that builds the bay grid, drives the Locate buttons and polls
the SMART tab — the surface where issue #15's whole class of bug lives.

## Why this is a small change, not a rewrite

Both blocks are a **single contiguous `<script>` running to the end of the
file**, and between them they interpolate PHP exactly three times:

```
hbaviewer.php:363   var luCsrf = (typeof csrf_token !== 'undefined' && csrf_token) ? csrf_token : '<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>';
flash_view.php:138  var flashArrayStopped = <?= $arrayStopped ? 'true' : 'false' ?>;
flash_view.php:151  var flashCsrf = (typeof csrf_token !== 'undefined' && csrf_token) ? csrf_token : '<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>';
```

So the shape is: leave a **three-line inline `<script>`** declaring those vars,
move everything else to a static file. No templating engine, no build step, no
bundler. `build.sh` tars the plugin directory wholesale (line 91), so a new
`.js` file ships with no packaging change.

## Drift check — run first, STOP if it fails

```bash
cd "$(git rev-parse --show-toplevel)"
D=source/usr/local/emhttp/plugins/hbaviewer
grep -n '<script\|</script>' $D/hbaviewer.php $D/flash_view.php
awk '/<script/{s=1} s{print NR": "$0} /<\/script>/{s=0}' $D/hbaviewer.php | grep '<?=\|<?php'
awk '/<script/{s=1} s{print NR": "$0} /<\/script>/{s=0}' $D/flash_view.php | grep '<?=\|<?php'
```

Expect one `<script>` per file, closing at EOF, and exactly the three
interpolations above. **If either file now has a second `<script>` block or a
fourth interpolation, stop and re-scope** — the extraction below assumes one
contiguous block per file.

## Steps

**1. `hbaviewer.php` → new `hbaviewer.js`.** Move lines 217–1196 verbatim.
Leave behind:

```php
<script>
var luCsrf = (typeof csrf_token !== 'undefined' && csrf_token) ? csrf_token : '<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>';
</script>
<script src="/plugins/hbaviewer/hbaviewer.js"></script>
```

Order matters — `luCsrf` must be defined before the external file runs.

**2. `flash_view.php` → new `flash_view.js`.** Same shape, with the two vars
from lines 138 and 151 staying inline.

**3. Add the blocking syntax gate to `.github/workflows/php.yml`,** matching
the tier `php -l` and `bash -n` already occupy. Node is preinstalled on
`ubuntu-latest`:

```yaml
      - name: Lint JS (node --check on every .js)
        run: find source -name '*.js' -not -name '*.min.js' -print0 | xargs -0 -r -n1 node --check
```

Deep analysis comes free the moment the files exist: Codacy already runs jshint
and `.codacy.yml` excludes only `plans/` and the vendored bundle, so both new
files get picked up on the next scan with no configuration. Expect a first
batch of jshint findings — that is the point, and they are advisory, so they
cannot block a merge.

## The trap 055 fell into — repeat its check

Plan 055 moved JavaScript between files and **dangled three live call sites**,
because it cut a block as a unit and took `flashCsrf` with it. After moving,
grep the moved JS for every identifier it does not itself define:

```bash
# any bare identifier used but never declared in the new file
grep -oE '\b[a-zA-Z_$][a-zA-Z0-9_$]*\b' $D/hbaviewer.js | sort -u > /tmp/used
grep -oE '(var|let|const|function)\s+[a-zA-Z_$][a-zA-Z0-9_$]*' $D/hbaviewer.js \
  | awk '{print $2}' | sort -u > /tmp/defined
comm -23 /tmp/used /tmp/defined | head -40
```

Everything left should be a browser global, an Unraid global (`csrf_token`),
Chart.js, or one of the vars deliberately left inline. Anything else is a
reference that just broke.

055's second lesson also applies: `$csrfToken` must be read **unconditionally**
in the PHP, not inside a feature flag. It was scoped inside `if ($enableFlash)`
once already, which silently made the bay map depend on Unraid's `csrf_token`
global existing.

## One genuinely new risk: browser caching

`chrome.css` is referenced with a plain `href` and no cache-buster, and the
same convention applied to a `.js` file is riskier — the plugin directory is
tmpfs and re-extracted on upgrade, but a browser holding a stale
`hbaviewer.js` against freshly-rendered PHP gets a broken page, not a
cosmetically-old one.

**Decision for the executor:** either match the existing convention (plain
`src`, accept the exposure `chrome.css` already has) or append `?v=` and the
plg version. Recommend the version query — it costs one PHP expression and
this file is behaviour, not styling. Do not invent a hashing step.

## Verification

```bash
bash tests/run.sh                                    # must stay green
php -l source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php
node --check source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.js
node --check source/usr/local/emhttp/plugins/hbaviewer/flash_view.js
```

`tests/view_test.php` resolves internal links against the real `.page` files
(added by 055) — check whether it also needs to learn about `<script src>`, so
a typo'd asset path fails a test rather than a page.

**Not verifiable by any test here:** that the pages still work in a browser.
Load the Bay Map, click Locate, open the SMART tab, and open the firmware page
with flashing both on and off. 055 shipped three dead internal links precisely
because no test covered the rendered page.

## STOP conditions

- Drift check disagrees with the line ranges or interpolation count above
- The `comm -23` grep lists an identifier that is not a known global
- `tests/run.sh` goes red — the JS move must be behaviour-neutral, so a red
  suite means something moved that should not have

## Scope guard

This plan is a **file move plus a CI step**. It is not permission to reformat,
modularise, or ES6-ify 1,100 lines of working JavaScript. Fixing what jshint
subsequently reports is a separate plan, written after seeing the findings.
