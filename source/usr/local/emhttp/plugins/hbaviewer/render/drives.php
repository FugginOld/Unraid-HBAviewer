<?php
// Drives tab: dev/serial/address lookups and the attached-drives table renderer.
/* SERIAL -> /dev/NAME for every SCSI block device, from ONE lsblk call.
   Serial is the join key, not the WWN: storcli's WWN and /dev's differ by a
   nibble on the same physical drive, while the serials match exactly — the
   same correlation the per-drive SMART button (render/smart.php) has used since it shipped.
   Empty on a box without lsblk, which renders every Device cell as "—" rather
   than failing a tab. Callers pass the result in, so the render functions stay
   pure and the tests can inject a map. */
/* One spelling of a serial, so the two sides of the join cannot disagree about
   it. Both sides call this; that is the whole point of it existing.

   Some SAS drives report a serial WITH A SPACE IN IT -- an HGST H7210A520SUN010T
   returns "001848RG2JHN JEHG2JHN" -- and storcli and lsblk do not agree on how
   much whitespace sits in the middle. Collapsing runs of it makes the two
   spellings one key. */
function lsi_serial_key(string $s): string {
    return strtoupper(trim((string) preg_replace('/\s+/', ' ', $s)));
}

/* $raw is injectable so the parse can be tested; null means ask lsblk. */
function lsi_dev_by_serial(?string $raw = null): array {
    $raw ??= (string) shell_exec('lsblk -S -o NAME,SERIAL -n 2>/dev/null');
    $map = [];
    foreach (explode("\n", $raw) as $line) {
        $f = preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($f) < 2 || $f[0] === '') continue;
        /* EVERY field after the name, not just the first. This took only $f[1],
           so a serial containing a space was truncated at it and the drive was
           filed under half its own name. The lookup then always missed: four of
           nine drives on the reporting box showed "-" for a device they were
           plainly attached to, while SMART -- which joins by another path --
           named all nine. */
        $map[lsi_serial_key(implode(' ', array_slice($f, 1)))] = '/dev/' . $f[0];
    }
    return $map;
}

/* The /dev name for one drive row. The lsiutil backend already resolves it
   itself (`os_name`, from get_attached_drives.sh's sysfs join); storcli reports
   no /dev name at all, so it goes through the serial map. Null, never a guess:
   a Device column that names the wrong disk is worse than one that says "—". */
function drive_dev_name(array $d, array $devBySerial): ?string {
    if (!empty($d['os_name'])) return (string) $d['os_name'];
    $sn = lsi_serial_key((string) ($d['serial'] ?? ''));
    return $sn !== '' ? ($devBySerial[$sn] ?? null) : null;
}

/* "/dev/sdb" => "0:0:2:0" for every SCSI block device — the SCSI H:C:T:L
   address, which is also the name of the device's node under /dev/bsg. That is
   what the locate blink reads (plan 048), and it comes straight out of sysfs:
   /sys/block/sdb/device is a symlink whose last component IS the address. No
   lookup table, no extra tool, no cache to go stale.
   Anything that does not look like an address is dropped rather than passed
   on — the value ends up in a device path. */
function lsi_scsi_addr_by_dev(string $sysBlock = '/sys/block'): array {
    $map = [];
    foreach (glob("$sysBlock/sd*") ?: [] as $d) {
        $target = @readlink("$d/device");
        if ($target === false) continue;
        $addr = basename($target);
        if (preg_match('/^\d+:\d+:\d+:\d+$/', $addr)) $map['/dev/' . basename($d)] = $addr;
    }
    return $map;
}

/* The Unraid slot cell for a table: "Parity", "Disk 1", a mounted unassigned
   device's label, or an em dash for a drive Unraid is not using at all. One
   renderer so the four tables that show it cannot drift apart in spelling or in
   what they do with a miss.

   Array role first. The two are mutually exclusive in reality -- a disk cannot
   be an array member AND an unassigned device -- so a drive answering to both
   means the two sources disagree, and the array's claim is the stronger one.

   The UD label is rendered muted, because "media9" and "Disk 1" are not the
   same kind of fact: one is a slot in the array, the other is where somebody
   mounted a disk the array does not know about. Same weight would imply the
   column means one thing when it means two. */
function lsi_role_cell(?string $dev, array $roles, array $udMounts = []): string {
    if ($dev === null) return '<span class="lu-muted">—</span>';
    $r = $roles[$dev] ?? '';
    if ($r !== '') return htmlspecialchars($r);
    $u = $udMounts[$dev] ?? '';
    if ($u !== '') return '<span class="lu-muted">' . htmlspecialchars($u) . '</span>';
    return '<span class="lu-muted">—</span>';
}

/* ── Attached Drives (per controller; columns adapt to the backend) ───────── */
function renderDrivesTables(array $data, array $devBySerial = [], array $roles = [],
                            array $addrByDev = [], array $locating = [],
                            array $udMounts = []): string {
    $ctls    = $data['controllers'] ?? [$data];
    // Shape, not tool name: StorCLI2 (SAS4 / 9600) feeds these tables the same
    // record shape as the classic storcli backend, so one renderer serves both.
    $storcli = lsi_backend_shape($data['backend'] ?? '') === 'storcli';
    return luCardPerController($ctls, function (int $i, array $ctl) use ($storcli, $devBySerial, $roles, $addrByDev, $locating, $udMounts): string {
        $out = '';
        // Enclosure/topology summary (storcli). VirtualSES = direct-attach, no expander.
        // storcli_drives.sh emits "eid/slot" when a drive carries an enclosure ID and a
        // bare "slot" when it does not. If NO drive on this controller carries one, the
        // enclosure's own slot/drive counts describe something the drives aren't
        // attached to — showing "0 drives" above 15 rows reads as a bug (issue #6).
        $dl = $ctl['drives'] ?? [];
        $enclLess = $dl !== [] && !array_filter($dl, fn($d) => str_contains((string) ($d['slot'] ?? ''), '/'));
        foreach ($ctl['enclosures'] ?? [] as $e) {
            $mode  = !empty($e['direct']) ? 'direct-attach (no expander)' : 'expander / backplane';
            // Only state a slot/drive count when storcli actually reported one —
            // an empty Properties section previously rendered as "8 slots / 0 drives"
            // on a controller with 15 drives. Also suppress when this controller's
            // drives are addressed without an enclosure (issue #6): the counts are
            // real but describe nothing the drive table shows.
            $counts = !$enclLess && ($e['slots'] ?? '') !== '' && ($e['drives'] ?? '') !== ''
                ? htmlspecialchars($e['slots']) . ' slots &middot; ' . htmlspecialchars($e['drives']) . ' drives &middot; '
                : '';
            $out .= '<p class="lu-muted" style="font-size:12px;margin:0 0 6px">Enclosure e' . htmlspecialchars($e['eid'])
                  . ': ' . htmlspecialchars($e['product']) . ' (' . htmlspecialchars($e['vendor']) . ') &middot; '
                  . $counts . $mode . ($enclLess ? ' &middot; drives are addressed without an enclosure' : '') . '</p>';
        }

        $drives = $ctl['drives'] ?? [];
        /* An HBA with nothing plugged into it is a legitimate state, so this
             says what was observed rather than implying a fault. */
        if (empty($drives)) {
            $out .= '<p class="lu-muted">No drives detected — this controller reported no attached devices.</p>';
            return $out;
        }

        // Leading column on both backends: encl:slot and bus:target are the
        // controller's own addressing and line up with nothing on Unraid's Main
        // page (issue #11). /dev/sdX is the name shared with Main, the SMART tab
        // and every other Unraid screen, so it goes first, like the SMART tab.
        $devCell = function (array $d) use ($devBySerial): string {
            $n = drive_dev_name($d, $devBySerial);
            return $n !== null ? '<code>' . htmlspecialchars($n) . '</code>' : '<span class="lu-muted">—</span>';
        };
        /* Locate blinks the drive's own activity light (plan 048). Offered only
           where an H:C:T:L address resolved — no address, no device to read, so
           the cell says why rather than presenting a button that cannot work. */
        $locCell = function (array $d) use ($devBySerial, $addrByDev, $locating): string {
            $dev  = drive_dev_name($d, $devBySerial);
            $addr = $dev !== null ? ($addrByDev[$dev] ?? '') : '';
            /* role=img + aria-label, because an em dash is the ONLY visible
               content here and `title` is mouse-only: a screen reader otherwise
               announces this cell as "dash", or as nothing at all. role=img is
               what lets a bare glyph take an author-supplied name. */
            if ($addr === '') return '<span class="lu-muted" role="img" aria-label="No SCSI address for this drive"'
                                   . ' title="No SCSI address for this drive">—</span>';
            $on = in_array($addr, $locating, true);
            // The address is [0-9:] by construction (lsi_scsi_addr_by_dev drops
            // anything else) and the /dev name comes from lsblk, so neither can
            // carry a quote into the handler — htmlspecialchars is the belt.
            return sprintf(
                '<button class="lu-refresh-btn%s" data-locate="%s" onclick="luLocate(event, this, \'%s\', \'%s\')">%s</button>',
                $on ? ' locating' : '',
                htmlspecialchars($addr, ENT_QUOTES),
                htmlspecialchars($addr, ENT_QUOTES),
                htmlspecialchars((string) $dev, ENT_QUOTES),
                $on ? 'STOP' : 'Locate'
            );
        };

        // The backend field, and nothing else -- see the PHY renderer, render/phy.php.
        if ($storcli) {
            // storcli backend: enclosure/slot, model, serial, state, size, SAS (WWN), link, fw
            $rows = [];
            foreach ($drives as $d) {
                $serial = $d['serial'] ?? '';
                $smart  = $serial !== ''
                    ? '<button class="lu-refresh-btn" onclick="luSmart(this,\'' . htmlspecialchars($serial, ENT_QUOTES) . '\')">SMART</button>'
                    : '<span class="lu-muted">—</span>';
                $rows[] = [
                    $devCell($d),
                    lsi_role_cell(drive_dev_name($d, $devBySerial), $roles, $udMounts),
                    htmlspecialchars($d['slot']),
                    ($d['port'] ?? '') !== '' ? htmlspecialchars($d['port']) : '<span class="lu-muted">—</span>',
                    htmlspecialchars($d['model']),
                    $serial !== '' ? '<code>' . htmlspecialchars($serial) . '</code>' : '<span class="lu-muted">—</span>',
                    htmlspecialchars($d['state'] ?? ''),
                    htmlspecialchars($d['size']),
                    !empty($d['sas_address']) ? '<code>' . htmlspecialchars(strtoupper($d['sas_address'])) . '</code>' : '<span class="lu-muted">—</span>',
                    htmlspecialchars($d['link']),
                    htmlspecialchars($d['firmware']),
                    $smart,
                    $locCell($d),
                ];
            }
            $out .= luTable(['Device', 'Unraid', 'Encl:Slot', 'Port', 'Model', 'Serial', 'State', 'Size', 'SAS Address', 'Link', 'Firmware', 'SMART', 'Locate'], $rows);
        } else {
            // lsiutil backend: device, bus:target, port, SAS address. The /dev
            // name was already here as a trailing "OS Device" column; it moves
            // to the front so all three tabs lead with the same identifier.
            $rows = [];
            foreach ($drives as $d) {
                $sas = !empty($d['sas_address']) ? '<code>' . htmlspecialchars(strtoupper($d['sas_address'])) . '</code>' : '<span class="lu-muted">—</span>';
                $phy = isset($d['phy']) && $d['phy'] !== '' ? 'PHY ' . htmlspecialchars((string) $d['phy'])              : '<span class="lu-muted">—</span>';
                $rows[] = [
                    $devCell($d),
                    lsi_role_cell(drive_dev_name($d, $devBySerial), $roles, $udMounts),
                    // ?? like sas_address and phy above: routing is by the backend
                    // field alone now, so a record whose shape disagrees with its
                    // label lands here missing keys rather than being sniffed away.
                    htmlspecialchars((string) ($d['bus'] ?? '')) . ':' . htmlspecialchars((string) ($d['target'] ?? '')),
                    $phy, $sas,
                    $locCell($d),
                ];
            }
            $out .= luTable(['Device', 'Unraid', 'Bus:Tgt', 'Port', 'SAS Address', 'Locate'], $rows);
        }
        return $out;
    });
}
