# One encoding of "which backend", one declaration of each default — design

Candidates 4 and 7 of the 2026-08-16 architecture review, planned together
because both are the same shape: one fact, declared in several places, already
disagreeing in one of them.

## Part A — the backend fact

`CONTEXT.md` states the rule outright:

> PHP reads the explicit `backend` field to pick columns — no key-sniffing.

Three renderers do not:

| Site | The sniff |
|---|---|
| `ajax_info.php:907` | `$storcli \|\| (($data['backend'] ?? '') === '' && isset($phys[0]['speed']))` |
| `ajax_info.php:1038` | same shape, `isset($drives[0]['slot'])` |
| `ajax_info.php:1353` | same shape, `isset($entries[0]['description'])` |

Each is commented "fall back to key-sniff pre-rollout". That rollout is long
past: `hba_each` stamps `backend` on every payload, the only unstamped output is
the `{"error":…}` path which `ajax_info.php:180` handles before any renderer
runs, and the one cache that could hold a pre-rollout payload (`/tmp`, 60s TTL)
is invalidated by a script mtime newer than the cache — which an upgrade
guarantees. The sniffs are dead code that contradicts a documented decision.

**`event_shape()` is NOT in this category and must not be touched.** It
classifies *archived* entries in `/boot`, written by whichever backend was
active at the time. Those records carry no backend field and the current one
cannot answer for them: a box that ran lsiutil for a year and then installed
storcli has both shapes in one file. Shape is the only available evidence, so
reading it is correct. The architecture review got this wrong.

`event_visible()`'s empty-`$backend` fallback is likewise left alone: it guards
the same genuinely-pre-rollout archives.

## Part B — the discovery re-implementation

`settings.php:37-43` probes for storcli itself, in PHP, with a four-path list:

```
/usr/local/sbin/storcli  /usr/local/sbin/storcli64  /usr/sbin/storcli  /usr/sbin/storcli64
```

`lib.sh:40-51` (`find_storcli`) uses eight, including `/usr/local/bin/storcli`
and `/usr/local/bin/storcli64`, which `settings.php` omits. The PHP falls back to
`command -v`, so the divergence only bites a storcli installed under
`/usr/local/bin` and absent from `PATH` — narrow, but it is two implementations
of one lookup that have already drifted apart once.

`settings.php` already calls `shell_exec` for its `command -v` fallback, so
routing the whole lookup through the shell's implementation adds no new class of
I/O. `lib.sh`'s top level only assigns variables and defines functions — sourcing
it touches no hardware.

## Part C — the config defaults

`ALERT_THRESHOLD` is declared four times and holds two values:

| Site | Value |
|---|---|
| `hbaviewer.plg:496` (install-time write, only when no cfg exists) | 80 |
| `scripts/config.sh:10` — comment reads "Defaults live once, here" | 80 |
| `config.php:62` `LSI_SCHEMA` | **76** |
| `settings.php:74` | 80 |

The exposure is narrower than the review implied: the `.plg` writes the key on a
fresh install, so both sides normally read 80 from the file. The 76 is reachable
only on a box whose cfg predates the key or was hand-edited — then the shell
bands temperatures against 80 while PHP labels against 76.

**80 is not a valid value for what this setting now means.** `config.php:58-60`
documents it as "the FIRST BAND at which the badge complains, stored as that
band's floor (66 elevated / 76 warning / 86 alert / 96 critical)". 80 is not a
band floor — it is a leftover from when the setting was a raw temperature. 76 is
both the schema's own value and a real floor.

## There is no behaviour decision here

Unifying on **76** changes nothing observable for any box, including one whose
cfg is missing the key. `band_of` buckets 76-85 into the same "warning" band, so
a box that banded at 80 and one that bands at 76 land in the same band, get the
same badge, and trigger the same notification. The only thing that moves is the
number printed on the Settings page's "Badge Sensitivity" line.

The reason to prefer 76 over 80 is not behavioural: 76 is one of the four legal
band floors (66/76/86/96) the schema documents, and it is the value
`config.php` and its tests already use. Unifying on 80 would instead require
changing `config.php` and `tests/config_test.php`, and would keep a value the
schema's own comment says is not a legal floor.

Recommended: 76, because it is the legal floor already in use everywhere else —
not because it changes anything a user would see.

## Constraints

1. No golden in `tests/expected/` may change. Both parts are PHP-side except the
   config declaration, which must leave `config.sh`'s resolved values identical
   for any cfg that carries its keys.
2. `event_shape()` and `event_visible()` are out of scope. See Part A.
3. `find_storcli`'s eight-path list is the one implementation; nothing may fork
   it again.
