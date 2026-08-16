# The per-card read — design

Deepen the five lsiutil tab composers onto one module. Written 2026-08-16, the
day after multi-card support shipped (release 2026.08.16, issue #18).

## Why now

Plan 059 taught five composers to loop every card. It did so five times. The
loop is the youngest code in the plugin and the most likely to change again —
concentrating it now costs one refactor; concentrating it after the next
hardware surprise costs five.

Measured on `main` @ `8e4a12d`:

| Duplication | Count | Where |
|---|---|---|
| Comma-join emit loop (`first=1` / `printf ','`) | 5 | all five lsiutil composers |
| Banner capture (`printf '0\n' \| hba_query`) | 3 | `lib.sh:228`, `get_hba_info.sh:166`, `get_hba_health.sh:159` |
| Board-row parse (`grep ioc` + `sed -n "${p}p"` + two `awk`) | 2 | `lib.sh:231-233`, `get_hba_info.sh:181-183` |
| Port-count idiom | 2 spellings | `wc -w` in info, `wc -l` in health and drives |
| `mktemp` calls | 11 | only 3 protected by a `trap` |

`get_hba_health.sh` captures its own banner **and** calls `lsi_port_map`, which
captures another: the hardware banner is read twice per health request.

## What is genuinely shared, and what only looks it

Shared, and belongs in the module:

- capturing the banner and the `-b` board table (both list every port in one call)
- enumerating ports from the banner
- counting them
- picking a port's board row and its bus/dev columns
- resolving the port's scsi host through `lsi_host_for` — **the join rule**
- resolving that host's PCI dir
- comma-joining what each card printed
- owning the two temp files

Not shared, and must stay per-tab — the report called these "three divergent
join rules", but reading the code they are three *interpretations of a failed
join*, and the difference is load-bearing:

| Tab | On empty `hnum` | Why |
|---|---|---|
| overview | `topology=unknown`, empty subvendor/card_id | an unknown topology suppresses the firmware verdict; a wrong one destroys a multipath config |
| health | on a **single**-port box only, fall back to host `0` | preserves the historic `${hnum:-0}`, which the existing goldens pin |
| drives | on a **multi**-port box, emit an empty payload | the sysfs sweep is box-wide; unfiltered it would hand this card its neighbours' disks |

So the module yields `hnum` and lets the callback decide. Anything else would
change behaviour the goldens pin.

## Shape

One interface, one callback:

```
lsi_each_card CALLBACK
  CALLBACK PORT BANNER BOARD HNUM PDIR NPORTS
```

`HNUM` is empty when the join fails on a multi-card box — deliberately, since
handing a card its neighbour's host is the bug issue #18 was filed about.

A tab composer then declares only the hardware query it differs by, which is
what `CONTEXT.md` already says a tab composer is.

## Why this unlocks tests

Today the loop bodies are reachable only by `sed`-extracting private functions
out of composer files and `eval`-ing them — 9 extraction sites across 7 test
files, 3 of them for exactly these loops. There is no smaller interface to
call, so the tests invented one.

`get_hba_health.sh` has **no golden coverage at all**. Not an oversight:
`NOW=$(date +%s)` and `UPTIME` from `/proc/uptime` make byte-exact output
impossible. Injecting both unlocks the composer-level golden that every other
tab already has.

## Constraints

1. **Every existing golden stays byte-identical.** `tests/expected/*.json` is
   the safety rail; `bash tests/run.sh` after every task.
2. No behaviour change on a single-card box. That is what the goldens encode.
3. `hba_each`, `_host_for_pci`, `hba_card_id`, `hba_topology` and the 11 pure
   `parse/*.sh` filters are already deep. Do not touch them.
4. `lsi_ports` keeps its own name and signature — `bundle_support.sh` calls it.
