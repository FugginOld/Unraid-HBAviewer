<?php
// Bay map tab: Unraid slot roles/rebuild state and the bay-map assembly.
/* ── Unraid parity rebuild ───────────────────────────────────────────────────
   Which /dev names Unraid has assigned to parity, and whether the array is
   currently reconstructing. Read from the same two files Unraid's own webGui
   renders from, with parse_ini_file — the identical approach flash.php already
   uses for mdState (`flash_array_stopped`).

   NARROW ON PURPOSE, and BOTH halves of the test are load-bearing:
   - mdResyncAction is STICKY. A live idle array still reports the operation it
     last ran ("check P" on the reference box, with mdResync="0" and no
     operation running for weeks). Matching on the action alone would paint a
     permanent rebuild on the parity disk of every array that has ever run one.
     Hence mdResync > 0, which is the "something is running now" signal.
   - Only `recon` counts. A parity CHECK reads the array and writes nothing;
     animating it as a rebuild would be a claim about a disk that is not being
     rebuilt.
   Anything unreadable, missing or unrecognised means "no rebuild" — this only
   ever claims one on positive evidence. */
/* Which slot Unraid has each device in: "/dev/sdp" => "Parity", "/dev/sdg" =>
   "Disk 1". This is the identifier every OTHER Unraid screen uses, and until
   now none of this plugin's tables carried it — so matching a row here against
   the Main page meant tracking /dev/sdX by eye.
   Labels are spelled the way Main spells them, so the two can be read side by
   side. A slot with no disk assigned (parity2 on a single-parity array reports
   device="") is skipped rather than becoming "/dev/". */
function unraid_disk_roles(string $disksIni = UNRAID_DISKINI): array {
    if (!is_file($disksIni)) return [];
    $ini = @parse_ini_file($disksIni, true);
    if (!is_array($ini)) return [];
    $roles = [];
    foreach ($ini as $section => $sec) {
        if (!is_array($sec)) continue;
        $dev = trim((string) ($sec['device'] ?? ''));
        if ($dev === '') continue;
        $name = trim((string) ($sec['name'] ?? '')) !== '' ? (string) $sec['name'] : (string) $section;
        if (preg_match('/^disk(\d+)$/i', $name, $m))       $label = 'Disk ' . (int) $m[1];
        elseif (preg_match('/^parity(\d*)$/i', $name, $m)) $label = 'Parity' . ($m[1] !== '' ? ' ' . (int) $m[1] : '');
        else                                               $label = ucfirst($name);   // cache, and any named pool
        $roles[str_starts_with($dev, '/dev/') ? $dev : '/dev/' . $dev] = $label;
    }
    return $roles;
}

/* Which mounted UNASSIGNED device each drive is: "/dev/sdh" => "media9".
   The Unraid column showed an em dash for every drive on a box with no array,
   which was accurate and useless -- the drives do have a name their owner
   recognises, the one the Main page's Unassigned Devices list shows.

   /proc/mounts, NOT the Unassigned Devices plugin's own state. UD keeps its
   files where it likes and may move them; /proc/mounts is the kernel's and
   answers the actual question -- "is this drive mounted as an unassigned
   device" -- with no dependency on another plugin at all. Not mounted means
   nothing to show, and the em dash is then right.

   The mount point's basename is the label. Its device is a PARTITION and the
   column names drives, so the suffix comes off. */
function unraid_ud_mounts(string $procMounts = '/proc/mounts'): array {
    if (!is_file($procMounts)) return [];
    $out = [];
    foreach (explode("\n", (string) @file_get_contents($procMounts)) as $line) {
        $f = preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($f) < 2) continue;
        [$dev, $mp] = $f;
        /* Two guards, and they catch different things.
           The device must be a /dev/* node: /proc/mounts opens with
           `tmpfs /mnt/disks tmpfs ...`, because the directory UD mounts INTO
           is itself a mount, and "tmpfs" is not a drive.
           The trailing slash on the prefix is what keeps a SIBLING directory
           out -- /mnt/disksbackup starts with /mnt/disks and is not it. */
        if (!str_starts_with($mp, '/mnt/disks/') || !str_starts_with($dev, '/dev/')) continue;
        $label = basename($mp);
        if ($label === '') continue;
        $out[ud_base_device($dev)] = $label;
    }
    return $out;
}

/* "/dev/sdh1" => "/dev/sdh", "/dev/nvme0n1p1" => "/dev/nvme0n1".
   Not rtrim(0..9): that is right for sdh1 and wrong for nvme0n1p1, which it
   would cut back to /dev/nvme0n -- a device that does not exist. The two
   families spell a partition differently and the rule has to know both. */
function ud_base_device(string $dev): string {
    $name = substr($dev, strlen('/dev/'));
    if (preg_match('/^((?:nvme\d+n\d+|mmcblk\d+))p\d+$/', $name, $m)) return '/dev/' . $m[1];
    if (preg_match('/^([a-z]+)\d+$/', $name, $m))                     return '/dev/' . $m[1];
    return $dev;   // whole-disk mount, or a shape we do not recognise
}

/* Parity is just the slots whose label says so — one reader for disks.ini, not
   two that could disagree about which device is parity. */
function unraid_parity_devs(string $disksIni = UNRAID_DISKINI): array {
    return array_keys(array_filter(unraid_disk_roles($disksIni),
        fn($label) => str_starts_with($label, 'Parity')));
}

function unraid_rebuilding(string $varini = UNRAID_VARINI): bool {
    if (!is_file($varini)) return false;
    $ini = @parse_ini_file($varini);
    if (!is_array($ini)) return false;
    return (int) ($ini['mdResync'] ?? 0) > 0
        && stripos((string) ($ini['mdResyncAction'] ?? ''), 'recon') !== false;
}

/* ── Drive bay map: drives × stored positions × SMART health (plan 047) ──────
   The data half only. It returns the payload the map view renders client-side
   — the grid is interactive (click a drive, click a bay), so its state lives
   in JS either way and server-rendered cells would just have to be re-derived
   there on every click.
   $smart is the decoded SMART cache or null (see smart_cache_read); null and
   "collected but this drive is not in it" are the same thing here — no data,
   which is a state of its own and never renders as healthy. */
function bay_map_assemble(array $drivesData, ?array $smart, array $map, int $rows, int $cols,
                          array $devBySerial = [], bool $locked = false, int $warnTemp = 45,
                          ?int $smartAge = null, array $rebuildDevs = [], array $roles = [],
                          array $addrByDev = [], array $locating = [],
                          array $udMounts = []): array {
    /* Serial is the join key the SMART collector already emits per drive; it is
       also the only identifier the STORCLI payload shares with it (storcli's WWN
       differs by a nibble from /dev's — see lsi_dev_by_serial).

       The lsiutil payload has no serial at all — parse/drives_join.sh emits
       bus, target, sas_address, phy, expander and os_name, and nothing else. So
       a serial-only join missed every drive on that backend and the bay cards
       came up with no temperature, no health, no model and no capacity, which
       is what issue #15 reported on a 9207-8i. /dev is the identifier those two
       payloads DO share, so it is the fallback, and the collector's own entry
       supplies the fields the backend never reported. */
    $bySerial = $byDev = [];
    foreach ($smart['drives'] ?? [] as $sd) {
        $sn = strtoupper(trim((string) ($sd['serial'] ?? '')));
        if ($sn !== '') $bySerial[$sn] = $sd;
        $dv = (string) ($sd['dev'] ?? '');
        if ($dv !== '') $byDev[$dv] = $sd;
    }

    $placed = [];
    $tray   = [];
    foreach ($drivesData['controllers'] ?? [$drivesData] as $i => $ctl) {
        foreach ($ctl['drives'] ?? [] as $d) {
            $sn  = strtoupper(trim((string) ($d['serial'] ?? '')));
            $key = bay_map_key((int) $i, $d);
            $dev = drive_dev_name($d, $devBySerial);
            // Serial first: it is the drive's own identity and survives a /dev
            // name that moved across a reboot. /dev only when there is no serial
            // to match on, which on lsiutil is every drive.
            $sd  = $bySerial[$sn] ?? ($dev !== null ? ($byDev[$dev] ?? []) : []);
            $s   = $sd['smart'] ?? [];
            // Backend first, collector second: storcli's own model/serial/size
            // are the controller's view of the drive and stay authoritative
            // where it reports them.
            $serial = $sn !== '' ? (string) $d['serial'] : (string) ($sd['serial'] ?? '');
            $model  = ($d['model'] ?? '') !== '' ? (string) $d['model'] : (string) ($sd['model'] ?? '');
            $size   = ($d['size']  ?? '') !== '' ? (string) $d['size']  : (string) ($sd['size']  ?? '');
            /* Two different rebuilds can reach the same cell. Unraid's parity
               reconstruct wins, because on an Unraid box it is the one the
               person is actually waiting on — and on an IT-mode HBA (which is
               most of them) storcli's Rbld can never fire at all. */
            $rebuild = $dev !== null && in_array($dev, $rebuildDevs, true) ? 'PARITY REBUILD'
                     : (str_starts_with((string) ($d['state'] ?? ''), 'Rbld') ? 'RESILVER' : null);
            $entry = [
                // null key = this drive reported neither a port nor a PHY, so it
                // cannot be placed. It still appears in the tray, greyed: a drive
                // silently missing from both lists reads as a detection bug.
                'key'    => $key,
                'ctl'    => (int) $i,
                'dev'    => $dev,
                'serial' => $serial,
                'model'  => $model,
                'size'   => $size,
                // The bay card prints the number and its unit at different
                // sizes, so they are split once here rather than parsed in the
                // view. "12.733 TB" -> "12.733" + "TB"; anything that does not
                // look like a measurement passes through whole as the value.
                'cap'    => preg_match('/^\s*([0-9.]+)\s*([A-Za-z]+)/', $size, $cm) ? $cm[1] : $size,
                'cap_unit' => $cm[2] ?? '',
                'slot'   => $d['slot'] ?? (isset($d['phy']) ? 'PHY ' . $d['phy'] : ''),
                // What Unraid calls this disk — the name on its Main page, and
                // the one identifier a person already knows before they look
                // here. Empty for a drive the array does not use.
                /* Array role first, then the unassigned-device mount label --
                   the same precedence, and the same reason, as lsi_role_cell()
                   in the tables: the two are mutually exclusive in reality and
                   the array's claim is the stronger one. Without the fallback
                   every cell on a box with no array reads UNRAID with nothing
                   after it, which is what was reported. */
                'role'   => $dev !== null ? ($roles[$dev] ?? $udMounts[$dev] ?? '') : '',
                // The SCSI address the locate blink reads, and whether it is
                // blinking right now (plan 048). Empty address = no Locate
                // button on this bay, rather than one that cannot work.
                'addr'   => $dev !== null ? ($addrByDev[$dev] ?? '') : '',
                'locating' => $dev !== null && ($addrByDev[$dev] ?? '') !== ''
                              && in_array($addrByDev[$dev], $locating, true),
                // Display-ready, because the two backends key on different
                // wires and the word has to match: "Port 14" is storcli's
                // Connected Port Number, "PHY 2" is lsiutil's PHY index.
                // Calling a PHY a port on the cell would be a small lie in
                // exactly the place someone reads before pulling a drive.
                'port'   => isset($d['phy']) && $d['phy'] !== '' ? 'PHY ' . $d['phy']
                          : ((($d['port'] ?? '') !== '') ? 'Port ' . $d['port'] : ''),
                'temp'   => ($s['temp'] ?? '') !== '' ? (int) $s['temp'] : null,
                /* Health comes from SMART, never from storcli's `state` field,
                   which is a RAID-topology role rather than a verdict (plan
                   047). Rebuild is the one exception, and it is not an
                   exception to that rule: neither "Rbld" nor Unraid's resync is
                   a health claim — a rebuilding disk is not a sick disk, it is
                   a busy one, and nothing else reports it. */
                'state'  => $rebuild !== null ? 'rebuild' : smart_state($s),
                // Which rebuild, for the chip. Null on every drive that is not
                // rebuilding, so the view never has to guess a default.
                'rebuild_label' => $rebuild,
            ];
            $pos = $key !== null ? ($map[$key] ?? null) : null;
            // An out-of-grid position falls back to the tray rather than being
            // dropped: bay_map_prune_to_dims() normally clears these on resize,
            // but a hand-edited bay_map.json must not strand a drive off-screen.
            if ($pos !== null && (int) $pos['row'] < $rows && (int) $pos['col'] < $cols) {
                $placed[] = $entry + ['row' => (int) $pos['row'], 'col' => (int) $pos['col']];
            } else {
                $tray[] = $entry;
            }
        }
    }
    /* The tray comes out of the loop in controller/wire order, which is the one
       order nobody reading it is thinking in. Sorted into Unraid's Main-page
       order instead, so the chip you are hunting for sits where you already
       expect it. Only the tray is sorted; placed drives sit at coordinates the
       person chose.
       The /dev tiebreak compares LENGTH before text, because sd names are
       bijective base-26 and not decimal: the kernel goes sdz -> sdaa, so any
       plain string compare puts sdaa ahead of sdz. strnatcmp is no help either
       — it only groups digit runs, and there are no digits here. A drive with
       no /dev name at all is '' from the cast and so leads its tier, which is
       where an undetected device is worth looking at first. */
    usort($tray, fn(array $a, array $b) => bay_tray_order($a) <=> bay_tray_order($b)
                                        ?: [strlen((string) $a['dev']), (string) $a['dev']]
                                       <=> [strlen((string) $b['dev']), (string) $b['dev']]);
    return ['rows' => $rows, 'cols' => $cols, 'locked' => $locked, 'warn_temp' => $warnTemp,
            // Rendered in the legend row: the map's colours and temperatures are
            // only as current as the collection behind them.
            'smart_age' => $smartAge === null ? null : lsi_age_str($smartAge),
            'placed' => $placed, 'unassigned' => $tray];
}

/* Sort rank for one tray entry, as [tier, number, label] — compared
   element-wise by <=>, so the tiers separate first and the number only breaks
   ties inside one.
   Tiers are Main's own reading order: parity, then the data disks, then pools,
   then everything Unraid has no slot for. The number is pulled out as an
   integer rather than compared inside the label, because "Disk 10" sorts
   before "Disk 2" as a string and that is exactly the list where an off-by-one
   read gets the wrong drive pulled. Bare "Parity" ranks as 1 so it leads
   "Parity 2" — unraid_disk_roles() emits the first one without a number.
   Roleless drives sort last: they have nothing to match against Main, so they
   are not what anyone is scanning this list for. */
function bay_tray_order(array $e): array {
    $role = (string) ($e['role'] ?? '');
    if ($role === '')                                   return [3, 0, ''];
    if (preg_match('/^Parity(?: (\d+))?$/', $role, $m)) return [0, isset($m[1]) ? (int) $m[1] : 1, ''];
    if (preg_match('/^Disk (\d+)$/', $role, $m))        return [1, (int) $m[1], ''];
    return [2, 0, $role];   // cache and any named pool, alphabetical among themselves
}
