# SYNTHETIC — not captured from hardware

Plan 059 Step 0 asks for a diagnostic bundle from a multi-card SAS2 box. None
exists yet (neither Raven nor Golem has two SAS2 cards; the request is on issue
#18), so these files were **hand-built from the single-card fixtures** to prove
the per-port *loop*. They do not prove the *text*.

Every field below is invented:

| File | Real part | Invented part |
|------|-----------|---------------|
| `banner.txt` | Row 1 is `fixtures/hba_banner.txt` verbatim (a real SAS2308 capture) | Row 2, the `ioc1` name, and the `[1-2 or 0 to quit]` prompt |
| `board_p1.txt` | `fixtures/hba_board.txt` verbatim | — |
| `board_p2.txt` | shape only | bus `04`, board name, serial |
| `ioc_p2.txt` | shape only | `IOCTemperature: 0x0037` (55 °C), so a crossed wire between ports shows up as a wrong number rather than a duplicate one |

**What stays open until a real bundle arrives** (STOP conditions 2 and 3 of the
plan): whether a real multi-port banner numbers its rows the way row 2 here
assumes, whether `lsiutil -p<n> -b` accepts `-p` at all, and whether the Bus and
Device columns of `-b` match the card's sysfs PCI address. Delete this file and
these fixtures the moment the real captures land — they exist only because the
loop had to be testable before the hardware could be found.
