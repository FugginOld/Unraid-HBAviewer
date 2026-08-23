# The Unraid column labels the wrong disks — design

Reported 2026-08-22: "All my disks in Raven unraid are unassigned disks yet some
of them have been assigned an array device label. I am wondering if a disk on
the HBA is a mounted unassigned drive, it should follow unassigned disk device
label."

Two asks, one of which is a defect and one of which is a feature. They are in
one document because they are the same column and the second is only worth
building once the first is understood.

## Part 1 — the defect. CLOSED, NOT A DEFECT (see Finding).

**This spec does not know the cause, and the plan's first task is to find out
rather than to fix.** Writing it the other way round is how this repo's
architecture review was wrong four times.

What is known. `unraid_disk_roles()` (`render/baymap.php:27`) reads
`/var/local/emhttp/disks.ini`, and for every section with a non-empty `device`
maps it to a label:

```php
$roles[str_starts_with($dev, '/dev/') ? $dev : '/dev/' . $dev] = $label;
```

The map is keyed by **`/dev/sdX`**. That is the part worth suspecting, for a
reason independent of this report: `sdX` is assigned in kernel enumeration
order. It is not stable across boots, and on a box where controllers initialise
in a race it is not stable across reboots of identical hardware. Unraid itself
keys array membership by disk identity, not by `sdX`.

Two candidate causes, which the same one command distinguishes:

**(A) Stale or foreign sections.** `disks.ini` carries slot definitions that no
longer describe a present disk, and the reader accepts any section with a
non-empty `device`. It has no notion of "this slot is actually filled" — the
only thing it skips is an empty `device` string, which is the single case the
original author hit (parity2 on a single-parity array).

**(B) A `/dev/sdX` collision.** The `device` values are correct for the disks
Unraid means, but one of those names now belongs to a different physical disk
than it did when the entry was written — so an HBA drive inherits a label
belonging to a pool or array member.

If (A), the fix is a membership check. If (B), the fix is to stop keying on
`sdX` at all. **If both, only the second fix is sufficient**, which is why the
diagnosis has to come first: fixing (A) alone would make the symptom disappear
on the reporter's box and leave the real hazard in place.

The evidence that settles it, read-only, on the box:

```bash
grep -E '^\[|^device=|^id=|^name=|^status=' /var/local/emhttp/disks.ini
lsblk -o NAME,SERIAL,MOUNTPOINT -dn
```

The first shows what the plugin is reading and whether the suspect sections
describe present disks. The second shows what `sdX` currently means. Compare the
`device=` of any section whose label is appearing wrongly against the disk that
name resolves to now.

## Finding (2026-08-22, from the reporting box)

**Both candidates are refuted for the drives in the report.** Raven's
`disks.ini` holds five sections and no others:

| Section | `device` | `status` | Becomes |
|---|---|---|---|
| `parity` | `""` | `DISK_NP` | *skipped* — the empty-device guard |
| `parity2` | `""` | `DISK_NP` | *skipped* |
| `cache` | `sdj` | `DISK_OK` | `/dev/sdj => "Cache"` |
| `cache2` | `sdk` | `DISK_OK` | `/dev/sdk => "Cache2"` |
| `flash` | `sdl` | `DISK_OK` | `/dev/sdl => "Flash"` |

There are no `disk1..diskN` sections — the array is empty. The roles map on this
box can therefore produce exactly three labels, for `sdj`, `sdk` and `sdl`. The
nine HBA drives are `sda`–`sdi` and **cannot receive a label from it**; they must
already be rendering the em dash.

So there is no stale section (candidate A) and no `sdX` collision (candidate B).
Whatever the reporter saw, `unraid_disk_roles()` is not the source. Part 1 is
**re-opened pending an answer to one question: which drive showed which label.**
If it was `Cache`/`Cache2` on the SSDs, that is correct behaviour and Part 1
closes as not-a-defect.

What survives regardless: keying the map on `/dev/sdX` is still fragile for the
reason the spec gave, and `status` turns out to be present and populated
(`DISK_NP` vs `DISK_OK`), so a membership check is available if one is ever
wanted. Neither is worth doing on this evidence alone.

## Part 2 — the feature

A drive on the HBA that is a **mounted unassigned device** should say so.
Currently `lsi_role_cell()` renders an em dash for anything not in the array,
which is accurate — it is not an array disk — but unhelpful, because the drive
does have an identity the user recognises: the mount label Unassigned Devices
gives it, `media1`, `media9`, which is what the Main page shows.

The reporter's box is the strongest possible case for this: **every** HBA drive
there is an unassigned device, so the entire Unraid column is em dashes and the
column is dead weight on the screen it was added for. Its actual map, which is
the fixture Task 2 should use verbatim:

```
sda => media1   sdb => media2   sdc => media8
sdd => media6   sde => media7   sdf => media4
sdg => media5   sdh => media9   sdi => media3
```

Note the numbering does not follow the device order — `sdc` is `media8` and
`sdi` is `media3`. A fixture with `sdN => mediaN` would pass an implementation
that ignored the mount point and derived the label from the device name.

**Source of truth: `/proc/mounts`, not the Unassigned Devices plugin.** UD keeps
its own state, and reading another plugin's files couples HBAviewer to a
third-party layout that can change without notice. A mounted UD appears in
`/proc/mounts` as a partition mounted under `/mnt/disks/`:

```
/dev/sdh1 /mnt/disks/media9 xfs rw,relatime,...
```

The label is the mount point's basename. This needs no plugin, no dependency,
and reports exactly what "is a mounted unassigned drive" means — if it is not
mounted, there is nothing to show and the em dash is right.

Two details the implementation must not skip:

- **The partition is not the device.** `/proc/mounts` names `sdh1`; the drive is
  `sdh`. The suffix strip has to handle both `sdh1` and NVMe's `nvme0n1p1`,
  whose base device is `nvme0n1` rather than `nvme0n`.
- **`/mnt/disks/` is UD's convention, not a guarantee.** Restrict to that prefix
  and to nothing else: `/mnt/user`, `/mnt/cache` and the array mounts are not
  unassigned devices and must not be labelled as though they were.
- **`/mnt/disks` is itself a mount.** The box's own `/proc/mounts` opens with
  `tmpfs /mnt/disks tmpfs rw,...` — the directory UD mounts into is a tmpfs. A
  prefix match written as `str_starts_with($mp, '/mnt/disks')` accepts it and
  yields `['tmpfs' => 'disks']`. It has to be `/mnt/disks/` with the trailing
  slash, and the device has to look like a device. This was not in the first
  draft of this spec; it came out of reading the real file, which is the
  argument for having read it.

## How the two labels coexist

An array role and a UD mount are mutually exclusive in reality — a disk cannot
be both — so precedence is not a judgement call, it is a consistency check. If a
device resolves to both, the two sources disagree and **that is the bug from
Part 1 showing up again**. Array role wins for display, because it is the
stronger claim, but the collision is exactly what Part 1 exists to make
impossible.

The cell should distinguish them visually. `Disk 1` and `media9` mean different
things and the column must not imply the second is an array slot — the existing
`.lu-muted` treatment used for the em dash is the natural home for the weaker
claim.

## Scope

In:

- Whatever Part 1's diagnosis proves is necessary.
- A `/proc/mounts` reader for `/mnt/disks/*`, and `lsi_role_cell()` falling back
  to it.

Out:

- The Unassigned Devices plugin's own state files.
- Anything that writes. This column is read-only and stays that way.
- The bay map's parity detection (`unraid_parity_devs()`), unless Part 1's fix
  changes the shape of the roles map — in which case that caller moves with it
  and its tests come along.

## Verification

Part 2 is fully testable with a fixture `/proc/mounts`, and must be: the
partition-suffix strip and the `/mnt/disks/` restriction are both the kind of
rule that looks obviously right and is wrong on NVMe.

Part 1's verification depends on its diagnosis and cannot be written until then.
Whatever it turns out to be, it needs a test that fails against the box's real
`disks.ini` shape — a fixture invented from the fix's own assumptions would pass
regardless.


## Outcome (2026-08-23)

Part 1 closed as not-a-defect: the reporter's own screenshots show every Unraid
cell on Drives and SMART as an em dash, exactly as the `disks.ini` reading
predicted. Nothing was mislabelled.

Part 2 implemented. One correction to this spec came out of building it: the
trailing slash on `/mnt/disks/` does **not** exclude the `tmpfs /mnt/disks`
line — the `/dev/*` device test does that. The slash guards a *sibling*
directory, `/mnt/disksbackup`, and a mutation proved the original test suite
did not cover that at all. Both the code comment and the tests now say what is
actually true.
