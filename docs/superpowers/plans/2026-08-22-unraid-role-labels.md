# Unraid Role Labels Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The Unraid column tells the truth on a box whose HBA drives are unassigned devices — no array label on a disk that is not an array disk, and the UD mount label on one that is mounted.

**Architecture:** `unraid_disk_roles()` stops being the only source for the column. A second reader resolves `/proc/mounts` entries under `/mnt/disks/` to their base device, and `lsi_role_cell()` falls back to it. Whether `unraid_disk_roles()` itself changes is decided by Task 1 and not before.

**Tech Stack:** PHP 8 (no framework), PHP unit tests via `tests/run_php.sh`.

**Spec:** `docs/superpowers/specs/2026-08-22-unraid-role-labels-design.md`

## Global Constraints

- Run from the repo root: `cd c:/Users/Joe/Documents/GitHub/Unraid-HBAviewer`.
- Full verification is `bash tests/run.sh` (~3 min). It must print `--- all pass ---` at the end of **every** task.
- **Task 1 is a diagnosis and produces no code.** Do not skip it, do not start Task 2 while its finding is still a guess, and if the evidence contradicts both candidate causes in the spec, stop and say so rather than picking the nearest one. This repo has a documented history of implementing against a wrong diagnosis four times in a row.
- **Everything here is read-only.** No task writes to `disks.ini`, to `/proc`, or to any Unraid state. The column reports; it does not manage.
- `unraid_disk_roles()` has a second caller — `unraid_parity_devs()`, which the bay map uses to find parity. If Task 3 changes the roles map's shape, that caller and its tests move with it in the same task.
- No golden in `tests/expected/` should change without a stated reason.
- Commit after every task. Message style: a sentence saying what changed and why, no `feat:`/`chore:` prefixes.

---

### Task 1: Diagnose — why does an unassigned disk carry an array label?

No code. The output is a written finding appended to the spec.

- [ ] **Step 1: Collect, on the reporting box**

```bash
grep -E '^\[|^device=|^id=|^name=|^status=' /var/local/emhttp/disks.ini
lsblk -o NAME,SERIAL,MOUNTPOINT -dn
grep /mnt/disks /proc/mounts
```

The `id=` values are model+serial strings. They can be redacted for sharing —
what matters is whether the field **exists and is populated**, since it is the
candidate replacement key.

- [ ] **Step 2: Answer these four, in writing**

1. Which sections exist, and does each `device=` name a disk that is currently
   present? (Distinguishes spec candidate A.)
2. For any section whose label is appearing on the wrong drive: does its
   `device=` resolve, per `lsblk`, to a different physical disk than the section
   describes? (Distinguishes candidate B.)
3. Is `id=` present and populated on every section? If not, keying by identity
   is not available and the fix has to be something else.
4. Do sections carry a status/membership field that distinguishes a filled slot
   from a defined one?

- [ ] **Step 3: Append the finding to the spec**

Under a new "## Finding" heading in
`docs/superpowers/specs/2026-08-22-unraid-role-labels-design.md`: which
candidate it was, the evidence, and which of Tasks 3a/3b follows. Commit the
spec on its own — the finding is the deliverable of this task and it must be
readable by whoever picks the work up next.

---

### Task 2: The UD mount reader

Independent of Task 1's finding, so it can proceed while that is being gathered.

**Files:**
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/render/baymap.php` (beside `unraid_disk_roles()`)
- Test: `tests/bay_map_test.php`

**Interfaces:**
- Produces: `unraid_ud_mounts(string $procMounts = '/proc/mounts'): array` → `['/dev/sdh' => 'media9']`. Path injectable so tests use a fixture; the default is the real file.

- [ ] **Step 1: Write the failing tests**

Every one of these is a rule that looks obviously right and has a way to be
wrong:

```php
// The mount names a PARTITION; the column names a drive.
```

1. `/dev/sdh1 /mnt/disks/media9 xfs …` → `['/dev/sdh' => 'media9']`. The suffix strip.
2. `/dev/nvme0n1p1 /mnt/disks/fast …` → `['/dev/nvme0n1' => 'fast']`, **not** `/dev/nvme0n`. The naive `rtrim($dev, '0..9')` gets this wrong, and gets case 1 right, so case 1 alone would ship it.
3. An unpartitioned whole-disk mount, `/dev/sdh /mnt/disks/media9` → `['/dev/sdh' => 'media9']`. The strip must not eat a device that has no partition suffix.
4. `/mnt/user`, `/mnt/cache`, `/mnt/disk1` and `/` are **absent** from the result. The prefix restriction — an array disk is not an unassigned device and must never be labelled as one.
5. A mount point with a space in it (`/mnt/disks/my\040disk`) does not corrupt the label or the parse. `/proc/mounts` octal-escapes; decide whether to decode or to skip, and assert whichever it is.
6. Missing `/proc/mounts` → `[]`, no warning. Same failure posture as `unraid_disk_roles()` with a missing `disks.ini`.

- [ ] **Step 2: Implement** to pass, and no further.

- [ ] **Step 3: Verify** — `bash tests/run.sh` prints `--- all pass ---`.

- [ ] **Step 4: Mutate** — replace the suffix strip with `rtrim($dev, '0123456789')` and confirm case 2 fails. If it does not, case 2 is not testing what it claims.

---

### Task 3: The column falls back

**Files:**
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/render/drives.php` (`lsi_role_cell()`, line 50)
- Modify: the four callers that pass `$roles` — `ajax_info.php:109, 294, 299, 316`
- Test: `tests/ajax_render_test.php`

- [ ] **Step 1: Write the failing tests**

1. A device in the array roles → its array label, unchanged. (The existing tests cover this; they must keep passing untouched.)
2. A device **not** in the array but mounted under `/mnt/disks/` → its mount label.
3. A device in neither → the em dash, unchanged.
4. A device in **both** → the array label wins, **and** this is the collision the spec calls a Part 1 symptom. Assert the precedence, and if Task 1 found candidate B, assert additionally that the collision cannot occur after the fix.
5. The UD label is rendered with a weaker visual treatment than an array role, so the column does not imply `media9` is an array slot.

- [ ] **Step 2: Implement**

`lsi_role_cell()` takes the UD map as a third argument rather than reaching for
`/proc/mounts` itself — it is called from four places and a hidden filesystem
read inside a render function is not testable from any of them.

- [ ] **Step 3: Verify** — `bash tests/run.sh` prints `--- all pass ---`.

---

### Task 3a / 3b: Whatever Task 1 proved

**Only one of these exists. Task 1 says which. Delete the other.**

- [ ] **3a — candidate A (stale or foreign sections):** `unraid_disk_roles()`
  skips sections that do not describe a present, member disk, using the field
  Task 1 question 4 identified. Test with a fixture `disks.ini` **shaped like
  the box's real one**, including the stale section that caused the report — a
  fixture invented from the fix's own assumptions passes regardless of whether
  the fix is right.

- [ ] **3b — candidate B (`/dev/sdX` collision):** the roles map is keyed by
  disk identity (`id=`) rather than by `device=`, and resolved to the current
  `sdX` at render time through the existing `lsi_dev_by_serial()` join, which
  four renderers already use for exactly this reason. This changes the map's
  shape, so `unraid_parity_devs()` and the bay map's parity tests move in the
  same task. Test that a roles entry whose `device=` has been reassigned to
  another disk labels the **right** drive.

---

## Hardware verification

On the reporting box, after install:

1. Drives tab — no drive carries an array label it does not hold. This is the
   report, and it is the one thing the suite cannot prove, because the fixture
   is whatever the diagnosis says the box looks like.
2. Every mounted unassigned drive shows its `media*` label, matching the Main
   page's Unassigned Disk Devices list name-for-name.
3. An unmounted HBA drive still shows the em dash.
4. On a box **with** a populated array, the array labels are unchanged — this
   change must be invisible there, and that is the regression worth checking on
   a second machine.
