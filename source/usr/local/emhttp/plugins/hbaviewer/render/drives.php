<?php
// Drives tab: dev/serial/address lookups and the attached-drives table renderer.
/* SERIAL -> /dev/NAME for every SCSI block device, from ONE lsblk call.
   Serial is the join key, not the WWN: storcli's WWN and /dev's differ by a
   nibble on the same physical drive, while the serials match exactly — the
   same correlation the per-drive SMART button (render/smart.php) has used since it shipped.
   Empty on a box without lsblk, which renders every Device cell as "—" rather
   than failing a tab. Callers pass the result in, so the render functions stay
   pure and the tests can inject a map. */
function lsi_dev_by_serial(): array {
    $map = [];
    foreach (explode("\n", (string) shell_exec('lsblk -S -o NAME,SERIAL -n 2>/dev/null')) as $line) {
        $f = preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($f) >= 2 && $f[0] !== '') $map[strtoupper($f[1])] = '/dev/' . $f[0];
    }
    return $map;
}

/* The /dev name for one drive row. The lsiutil backend already resolves it
   itself (`os_name`, from get_attached_drives.sh's sysfs join); storcli reports
   no /dev name at all, so it goes through the serial map. Null, never a guess:
   a Device column that names the wrong disk is worse than one that says "—". */
function drive_dev_name(array $d, array $devBySerial): ?string {
    if (!empty($d['os_name'])) return (string) $d['os_name'];
    $sn = strtoupper(trim((string) ($d['serial'] ?? '')));
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

/* The Unraid slot cell for a table: "Parity", "Disk 1", or an em dash for a
   drive the array does not use. One renderer so the four tables that show it
   cannot drift apart in spelling or in what they do with a miss. */
function lsi_role_cell(?string $dev, array $roles): string {
    $r = $dev !== null ? ($roles[$dev] ?? '') : '';
    return $r !== '' ? htmlspecialchars($r) : '<span class="lu-muted">—</span>';
}

/* ── Attached Drives (per controller; columns adapt to the backend) ───────── */
function renderDrivesTables(array $data, array $devBySerial = [], array $roles = [],
                            array $addrByDev = [], array $locating = []): string {
    $ctls    = $data['controllers'] ?? [$data];
    // Shape, not tool name: StorCLI2 (SAS4 / 9600) feeds these tables the same
    // record shape as the classic storcli backend, so one renderer serves both.
    $storcli = lsi_backend_shape($data['backend'] ?? '') === 'storcli';
    return luCardPerController($ctls, function (int $i, array $ctl) use ($storcli, $devBySerial, $roles, $addrByDev, $locating): string {
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
        if (empty($drives)) { $out .= '<p class="lu-muted">No drives detected.</p>'; return $out; }

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
            if ($addr === '') return '<span class="lu-muted" title="No SCSI address for this drive">—</span>';
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
                    lsi_role_cell(drive_dev_name($d, $devBySerial), $roles),
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
                    lsi_role_cell(drive_dev_name($d, $devBySerial), $roles),
                    htmlspecialchars((string) $d['bus']) . ':' . htmlspecialchars((string) $d['target']),
                    $phy, $sas,
                    $locCell($d),
                ];
            }
            $out .= luTable(['Device', 'Unraid', 'Bus:Tgt', 'Port', 'SAS Address', 'Locate'], $rows);
        }
        return $out;
    });
}
