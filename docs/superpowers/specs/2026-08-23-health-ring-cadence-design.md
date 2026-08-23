# The health ring records visits, not time — design

Written 2026-08-23, after an "add trend data" idea turned out to be aimed at the
wrong obstacle.

## The idea that was wrong

The proposal was: move the health ring from `/tmp` to `/boot` so PHY error
history survives a reboot. `health.php`'s own header refuses it, and is right to:

> this ring lives in /tmp (RAM), not /boot, so there is no conditional-write
> rule to defend flash wear — every sample is appended unconditionally

`/tmp` was chosen **so that** the write could be unconditional. Unraid boots
from a USB stick; moving an unconditional writer onto it is a wear problem, not
a feature. `event_archive.php` pays a conditional-write rule for the privilege
and `health.php` names it as the shape it would have to copy.

## The obstacle that is real

From the same file:

> One sample per Health-tab render, not a timer — there is no cron or daemon
> here, so the ring's span is however often someone actually opens the tab
> (open today, open tomorrow, and the ring is 24h wide; refresh twice in a
> minute, and it's seconds wide).

**The ring is not a time series. It is a record of when a human looked.**
Persisting it would preserve a sparse, irregular log of tab visits. Storage was
never the blocker; cadence is.

This is not a hypothetical weakness. `HEALTH_MIN_CLEAR_SECS` is 1800 — below a
30-minute span the link-integrity indicator will not issue an all-clear, because
"0 errors in a 10-minute window" is not evidence about a PHY that logs 2/hour.
A visit-driven ring is frequently narrower than that, so the indicator most
worth trusting is the one most often unable to speak. Opening the tab twice to
"check again" actively makes it worse.

## The cadence already exists

`scripts/notify_check.php` runs from cron **every 10 minutes** (`hbaviewer.plg`
installs the entry) and already reads hardware — the same `get_hba_info.sh` the
Overview uses. The plugin has a scheduled sampler. It just does not feed the
ring.

Feeding it gives 6 samples/hour, so `HEALTH_RING_CAP` of 240 becomes **40 hours
of continuous history** instead of an unknown span of visits, and the ring is
always wider than `HEALTH_MIN_CLEAR_SECS` after the first half hour.

## What this costs

An extra composer run per cron tick: the ring's sample comes from
`get_hba_health.sh`, and `notify_check.php` currently runs only
`get_hba_info.sh`. That doubles the cron's hardware work — 144 extra reads a
day. It is background work with no request behind it, and `foreground-reads.md`
places cron in the "may block" category, so the cost is real but paid in the
right place.

## The prerequisite nobody would notice until it bit

`health_store_write()` is a bare `file_put_contents`. That is safe today because
there is effectively one writer: a tab render, one request at a time.

Adding a second writer makes it unsafe in a specific and nasty way.
`health_store_read()` ends in `?: []` — a torn or partial file decodes to
nothing and is read as **an empty ring**, which `health_ingest()` then treats as
a fresh start. So a cron write landing mid-render would not corrupt one sample;
it would silently discard **the entire accumulated history**, occasionally, with
no error anywhere. The feature would appear to work and quietly reset itself.

Atomic write first — `tmp` then `rename()`, which is atomic within a filesystem
— then the second writer. In that order, or the feature is worse than not
having it.

## Scope

**Phase 1, this spec:**

- `health_store_write()` becomes write-temp-then-rename.
- The 10-minute cron samples health and ingests it into the ring.
- The ring stays in `/tmp`. Nothing touches flash.

**Correction, same day, from testing it on hardware.** Phase 1 first put the
sampler *inside* the existing `ENABLE_NOTIFY` guard, reasoning that the guard
already enforced "a disabled feature must not poll silicon" and that trend
history could ride along with it. Watching the maintainer try to verify the
feature showed the cost: two unrelated-looking conditions had to be true, and
the second was a **notifications** toggle that says nothing about link-error
history. A feature nobody can find is a feature nobody has.

It has its own opt-in now, `TRACK_HISTORY`, off by default. The contract is
unchanged — with neither switch set the cron still exits before reading any
hardware — and one more consequence fell out of separating them: a failed
notify read used to `exit(0)`, which would have silently skipped the history
sample on any box whose overview read hiccupped.

**Phase 2, deliberately deferred to the maintainer:** whether any of this
survives a reboot. That is a flash-write policy question, and the answer is a
judgement about wear vs. value that belongs to whoever fields the support
threads. If it is ever taken up, `event_archive.php`'s conditional write is the
shape, and a downsampled hourly summary is likely a better fit than the raw
ring — 40 hours of RAM history and a long, coarse record on flash answer
different questions.

## Verification

Cadence cannot be observed from a unit test, so what gets pinned is the part
that can be: the write is atomic, the cron path ingests through the same
`health_ingest()` the tab uses (one append rule, not two), and a partial file is
never handed to a reader. On hardware, the check is that the Health tab's link
integrity reports a span measured in hours on a box nobody has opened the tab
on, where today it would report minutes or refuse to judge.
