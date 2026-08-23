# The dashboard tile splits a dual-IOC card — design

Reported 2026-08-22: "If the HBA is a single card, dual controller card, the
dashboard card should be the same as the overview card, showing 1 card with 2
controllers. Currently it still shows 2 cards, 1 per controller even though it
is a single HBA."

## What is actually wrong

`dashboard.php:141`:

```php
foreach ($controllers as $i => $c) {
```

One tile per controller. A SAS9300-16i is one board carrying two SAS3008 IOCs,
so it produces two tiles that both say "HBAviewer" and differ only by
`Controller /c0` and `Controller /c1`. The reporter has one card in one slot and
the dashboard tells them they have two.

The grouping rule this needs already exists and is already agreed. `card_group.php`
was written for exactly this question (plan `2026-08-10-dual-ioc-card-grouping`),
and three consumers use it:

| Consumer | Call |
|---|---|
| Overview tab | `render/overview.php:235` |
| Firmware page | `flash.php:147` |
| Per-controller tabs | `ajax_info.php:225` |
| **Dashboard tile** | **— none —** |

So this is not a new feature. It is the one consumer that was not updated when
grouping landed, and the fix is to call the function the other three call.

## The rule, and why it is conservative

`lsi_group_cards()` merges two controllers only when **both** hold: they share a
`card_id` (the PCI root port — two cards cannot occupy one slot), **and** the
board is one the firmware index says carries that many IOCs, with the count
matching exactly. Anything unrecognised stays split.

That guard is the interesting part and it must not be softened here. Server
boards and risers can put several genuine slots behind one PCIe switch, where a
shared root port means two separate cards. Merging those would be a worse bug
than the one being fixed, and it would appear only on hardware the maintainer
does not have.

## What a grouped tile shows

The Overview already decided every one of these, and the dashboard tile must
agree with it or the two screens will disagree about the same hardware — which
is the complaint, in a different form. From `render/overview.php` and the checks
in `tests/ajax_render_test.php`:

| Question | Overview's answer | Where it is pinned |
|---|---|---|
| Status of the group | **Worst** of its members, not the last one | "alert outranks warn whichever IOC carries it" |
| Identity of the group | Built from the **first member**, not slot 0 | "the parent is built from member 1, not slot 0" |
| Board-level facts (PCIe) | Stated once, at board level | "the board PCIe row carries only slot-level facts" |
| Per-IOC facts | Each IOC states its own PCI location | "each IOC states its own PCI location" |
| Temperature | Both sensors shown — a dual-IOC board has two dies | "the real dual-IOC capture keeps both sensors" |

The tile has less room than a card, and the first cut of this spec got the
consequence wrong. It reasoned that a dashboard tile cannot afford a
sub-section per IOC, so it should show the **higher** of the two temperatures
and let the Overview remain the screen that shows both.

**Corrected 2026-08-23, on the reporter's judgement:** the tile shows both dies,
the way the Overview's parent card does. "The dashboard tile should still show
both controllers info just like the overview but as 1 dashboard tile since it
is 1 HBA." That is right, and the original reasoning was answering the wrong
question — the ask was never "which of the two numbers matters most", it was
"stop claiming this is two cards". Hiding one die still misrepresents the
board, just less obviously than two tiles did.

So the tile mirrors the card's structure: the board's own facts once, then a
labelled section per IOC carrying its own gauge, temperature band and die-level
rows (PCI location, drive count, health). Board-level gauge is dropped when
grouped — repeating the hottest die's reading above the per-die sections would
show it twice and imply the board has a temperature of its own.

The **header** still carries the hottest temperature and the worst status,
because a collapsed tile shows only its header and those are the two facts
worth seeing without opening it.

## Scope

In:

- `dashboard.php` groups via `lsi_group_cards()`.
- One tile per **card**, keyed by the group rather than by the controller index.
- Grouped tile shows the worst status, the higher temperature, and names both IOCs.

Out:

- `lsi_group_cards()` itself. It is used by three other consumers and is not
  suspected. Any change to it belongs in its own spec.
- The tile key format for **ungrouped** cards. Unraid remembers per-tile
  position and collapse state by key, and changing a key that grouping does not
  need to change would silently reset users' dashboard layouts.

## The key-stability trap

Tile keys are `{$pluginname}_c{$i}` today. Unraid's Dashboard persists layout
against them. Two constraints pull against each other:

- A single-controller box must keep its existing key, or every user's dashboard
  layout resets on upgrade for a change that does not affect them.
- A grouped tile is a new thing and needs a key that does not collide.

Both are satisfied by keying on the group's **first member index** — which for
every ungrouped card is the index it already had. Only a box that actually has a
dual-IOC board sees a key change, and on that box the two old tiles are becoming
one, so its layout was going to change regardless.

## Verification

The suite can cover all of this: `lsi_group_cards()` is pure, the tile builder
can be exercised with the same dual-IOC fixture `ajax_render_test.php` already
uses for the Overview, and the assertions are the table above. Hardware confirms
only that the reporter's specific board groups — and that is worth doing, since
grouping depends on the firmware index recognising the board name.
