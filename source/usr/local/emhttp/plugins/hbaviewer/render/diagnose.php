<?php
/* Diagnose Verdict screen. Pure over a decoded job, so the whole screen is
 * testable with no /tmp, no job and no disk -- the same property every other
 * render/*.php has.
 *
 * SERVER-RENDERED, unlike the Live Job screen, because the verdict needs more
 * than the event stream carries: the per-range kernel sense keys, the worst
 * cmd_age, the expander port map and the other recent verdicts all live as
 * files the engine wrote. Reaches the browser as an HTML fragment, the shape
 * ajax_info.php?type=overview_html already uses.
 *
 * THE VERDICT IS SHOWN AS REASONING, not as a conclusion. Three evidence cards
 * and a per-range table, because "TRANSPORT" on its own is a label the user has
 * no way to check and every reason to distrust.
 */
require_once __DIR__ . '/table.php';

/* One decoded array per parseable line. A broken line is SKIPPED, not fatal:
   the engine appends while this reads, so arriving mid-write is normal and
   throwing there would blank the screen over one byte. */
function diag_events_decode(string $ndjson): array {
    $out = [];
    foreach (explode("\n", $ndjson) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $d = json_decode($line, true);
        if (is_array($d) && isset($d['t'])) $out[] = $d;
    }
    return $out;
}

/* Plain language, in the banner, above the label. An unknown verdict renders
   as unknown and never as clean -- absence is not health, and a green banner
   over a run that did not finish is a lie with a pill on it. */
function diag_verdict_words(string $v): array {
    switch ($v) {
        case 'TRANSPORT': return [
            'title' => 'TRANSPORT — the link, not the drive',
            'lead'  => 'The drive read every tested block cleanly on its own. When those same blocks had to cross the SAS link, the read failed. Replacing this drive would not fix it.',
        ];
        case 'MEDIA': return [
            'title' => 'MEDIA — the drive itself',
            'lead'  => 'The drive could not read its own platters at the tested blocks. The fault is in the media, not on the wire.',
        ];
        case 'CLEAN': return [
            'title' => 'No fault reproduced',
            'lead'  => 'Every tested block read cleanly, internally and over the link. The fault did not reproduce — re-run under load, or enable the full-surface scan.',
        ];
    }
    return [
        'title' => 'Unknown — this run did not classify',
        'lead'  => 'The job did not produce a verdict. Read the event stream: it either did not finish, or sg3_utils was missing and the VERIFY-vs-READ discriminator was unavailable.',
    ];
}

/* The three signals drive_triage.sh classifies on, shown as the reasoning.
   Chunks are summarised per op rather than listed: the per-chunk detail is the
   surface map's job, and repeating it here buries the comparison that matters. */
function diag_evidence_cards(array $events): array {
    $n = ['verify' => 0, 'read' => 0];
    $f = ['verify' => 0, 'read' => 0];
    $media = 0; $path = 0;
    foreach ($events as $e) {
        if ($e['t'] === 'chunk' && isset($n[$e['op'] ?? ''])) {
            $n[$e['op']]++;
            if (($e['ok'] ?? true) === false) $f[$e['op']]++;
        } elseif ($e['t'] === 'counter') {
            $d = (int) ($e['after'] ?? 0) - (int) ($e['before'] ?? 0);
            if ($d <= 0) continue;
            if (in_array($e['key'] ?? '', ['grown', 'uncorr'], true)) $media += $d;
            else                                                       $path  += $d;
        }
    }
    $word = function (int $total, int $failed): string {
        if ($total === 0) return 'not run';
        return $failed === 0 ? "clean ($total chunks)" : "FAILED ($failed of $total chunks)";
    };
    return [
        ['title'  => 'SCSI VERIFY',
         'result' => $word($n['verify'], $f['verify']),
         'detail' => 'The drive reads and checks the blocks internally. Nothing crosses the SAS link, so a failure here is the media.'],
        ['title'  => 'SCSI READ',
         'result' => $word($n['read'], $f['read']),
         'detail' => 'The same blocks moved over the wire. Clean VERIFY with a failing READ is a transport fault.'],
        ['title'  => 'Counter movement',
         'result' => $media === 0 && $path === 0 ? 'none'
                     : "media +$media · path +$path",
         'detail' => 'Media counters are the platters (grown defects, uncorrected reads); path counters are the wire (running disparity, invalid DWORD, loss of sync).'],
    ];
}

/* One row per tested range. $sense is [sense_key => count] harvested from the
   kernel log; $maxCmdAge is the worst cmd_age in seconds, or null.
   Commands hanging over a minute are called a TIMEOUT explicitly: the drive's
   own recovery gives up in 7-30 s, so a longer hang is the link giving up, not
   the media retrying -- and that distinction is the whole point of the screen. */
const DIAG_CMD_AGE_TIMEOUT = 60;

function diag_ranges_rows(array $events, array $sense, ?int $maxCmdAge): array {
    $ranges = [];
    foreach ($events as $e) {
        if (($e['t'] ?? '') !== 'chunk') continue;
        $key = (string) ($e['lba'] ?? 0);
        if (!isset($ranges[$key])) {
            $ranges[$key] = ['lba' => (int) ($e['lba'] ?? 0), 'n' => 0,
                             'verify' => null, 'read' => null];
        }
        $ranges[$key]['n'] = max($ranges[$key]['n'], (int) ($e['n'] ?? 0));
        $op = $e['op'] ?? '';
        if ($op === 'verify' || $op === 'read') {
            $okSoFar = $ranges[$key][$op];
            $thisOk  = ($e['ok'] ?? true) !== false;
            $ranges[$key][$op] = $okSoFar === null ? $thisOk : ($okSoFar && $thisOk);
        }
    }
    $keys = array_keys($sense);
    $evidence = $keys === [] ? '—' : ('sense ' . implode(', ', $keys));
    if ($maxCmdAge !== null) {
        $evidence .= ' · worst cmd_age ' . $maxCmdAge . 's';
        if ($maxCmdAge > DIAG_CMD_AGE_TIMEOUT) {
            $evidence .= ' — over a minute, so a LINK TIMEOUT rather than a media retry'
                       . ' (drive-internal recovery gives up in 7–30 s)';
        }
    }
    $rows = [];
    ksort($ranges, SORT_NUMERIC);
    foreach ($ranges as $r) {
        $say = fn(?bool $v) => $v === null ? 'not run' : ($v ? 'clean' : 'FAILED');
        $rows[] = [(string) $r['lba'], (string) $r['n'],
                   $say($r['verify']), $say($r['read']), $evidence];
    }
    return $rows;
}

/* Numbered, concrete actions.
 *
 * THE ARRAY-DISK RULE. Writing a block straight to an assigned disk bypasses
 * parity: the next parity check flags it as a mismatch and a future rebuild
 * could reintroduce bad data. The safe fix for a bad sector on an array disk
 * is a REBUILD -- onto the same disk, which rewrites every sector and remaps
 * the pending ones, or onto a replacement. Phase 2's guarded reassign flow
 * does not exist yet and will never apply to an assigned disk anyway, so this
 * card states the rebuild path in plain language. It is read-only advice, not
 * a control, which is what makes it Phase 1 work. */
function diag_next_steps(string $verdict, bool $arrayDisk): array {
    if ($verdict === 'TRANSPORT') {
        return [
            'Move this drive to a different bay, on a different cable, and re-run Diagnose.',
            'If the errors follow the SLOT, the fault is the cable, backplane, expander or HBA — not the drive.',
            'If the errors follow the DRIVE, the fault is that drive\'s own SAS interface electronics, and the platters are still fine.',
            'Check the PHY Health tab for other drives sharing the same port: several drives hot on one path is a shared fault, not four failing disks.',
        ];
    }
    if ($verdict === 'MEDIA' && $arrayDisk) {
        return [
            'This is an ARRAY disk. Do not write to it directly — a sector written straight to an assigned disk bypasses parity, the next parity check flags it as a mismatch, and a future rebuild could reintroduce bad data.',
            'The safe repair is a REBUILD. Rebuilding onto the same disk rewrites every sector and remaps the pending ones; rebuilding onto a replacement retires the drive.',
            'Rebuild onto a replacement if the defect count is still climbing between runs. Re-run Diagnose in a few hours and compare: a count that stops moving is a drive you can keep watching, a count that keeps moving is a drive on its way out.',
            'Back up anything not covered by parity before starting, and do not start a rebuild while another disk is disabled.',
        ];
    }
    if ($verdict === 'MEDIA') {
        return [
            'This drive is not assigned to the array or a pool, so there is nothing for parity to disagree with.',
            'Plan its replacement. The drive cannot read its own platters at these blocks, and a defect list that keeps growing between runs is a drive on its way out.',
            'Re-run Diagnose in a few hours and compare the counts before deciding: one bad block that never moves again is a different drive from one gaining blocks every run.',
        ];
    }
    if ($verdict === 'CLEAN') {
        return [
            'Nothing reproduced. Re-run while the array is under load — an intermittent link fault often needs traffic to appear.',
            'Enable the full-surface VERIFY for the next run if you have hours to spare: it tests every block rather than the ranges the kernel log already named.',
            'Re-check the PHY Health tab\'s baseline. A counter rising slowly is invisible in a single run and obvious against a baseline.',
        ];
    }
    return [
        'This run produced no verdict. Read the event stream above for where it stopped.',
        'If sg3_utils is missing, the VERIFY-vs-READ discriminator is unavailable and the job can only say THAT a disk failed, not why. Install it and re-run.',
    ];
}

function renderDiagVerdict(array $in): string {
    $disk   = (string) ($in['disk'] ?? '');
    $v      = (string) ($in['verdict'] ?? '');
    $w      = diag_verdict_words($v);
    $events = (array) ($in['events'] ?? []);

    $out = '<div class="lu-card first">'
         . '<h3>' . htmlspecialchars($w['title']) . ' — <code>/dev/'
         . htmlspecialchars($disk) . '</code></h3>'
         . '<p>' . htmlspecialchars($w['lead']) . '</p>'
         . '<p class="lu-muted" style="font-size:12px">'
         . htmlspecialchars((string) ($in['why'] ?? '')) . '</p></div>';

    $out .= '<div class="lu-diag-cols">';
    foreach (diag_evidence_cards($events) as $c) {
        $out .= '<div class="lu-card"><h4>' . htmlspecialchars($c['title']) . '</h4>'
              . '<p><strong>' . htmlspecialchars($c['result']) . '</strong></p>'
              . '<p class="lu-muted" style="font-size:12px">' . htmlspecialchars($c['detail']) . '</p></div>';
    }
    $out .= '</div>';

    $rows = diag_ranges_rows($events, (array) ($in['sense'] ?? []),
                             $in['max_cmd_age'] === null ? null : (int) $in['max_cmd_age']);
    $out .= '<div class="lu-card"><h4>Tested ranges</h4>';
    $out .= $rows === []
        ? '<p class="lu-muted">No range was tested in this run.</p>'
        : luTable(['Start LBA', 'Blocks', 'VERIFY', 'READ', 'Kernel evidence'],
                  array_map(fn($r) => array_map('htmlspecialchars', $r), $rows));
    $out .= '</div>';

    $out .= '<div class="lu-card"><h4>What to do next</h4><ol>';
    foreach (diag_next_steps($v, !empty($in['array_disk'])) as $s) {
        $out .= '<li>' . htmlspecialchars($s) . '</li>';
    }
    $out .= '</ol></div>';

    /* Which drives share a port. A single hot drive on a port is a lane or a
       cable; a whole hot expander is the cable to it or the expander itself --
       and that is not visible from one drive's verdict. */
    $out .= '<div class="lu-card"><h4>Drives sharing this path</h4>';
    $ports = (array) ($in['ports'] ?? []);
    if ($ports === []) {
        $out .= '<p class="lu-muted">No expander reported — these drives are direct-attached.</p>';
    } else {
        foreach ($ports as $addr => $devs) {
            $out .= '<p><code>' . htmlspecialchars((string) $addr) . '</code>: '
                  . htmlspecialchars(implode(', ', array_map('strval', (array) $devs))) . '</p>';
        }
    }
    $out .= '</div>';

    $out .= '<div class="lu-card"><h4>Recent verdicts</h4>';
    $recent = (array) ($in['recent'] ?? []);
    if ($recent === []) {
        $out .= '<p class="lu-muted">No other completed runs are kept.</p>';
    } else {
        foreach ($recent as $r) {
            $job = (string) ($r['job'] ?? '');
            $out .= '<p><button class="lu-refresh-btn" type="button" onclick="luDiagOpen(\''
                  . htmlspecialchars($job, ENT_QUOTES) . '\')">'
                  . htmlspecialchars($job) . '</button> '
                  . htmlspecialchars((string) ($r['disk'] ?? '')) . ' — '
                  . htmlspecialchars((string) ($r['verdict'] ?? 'unknown')) . '</p>';
        }
    }
    $out .= '</div>';
    return $out;
}

/* Worst-first, because the whole point of the list is to put the drive you
   should look at next at the top. Unassigned drives are their own group: a
   disk the array does not know about is a different kind of fact from Disk 1,
   and mixing them implies the column means one thing when it means two. */
const DIAG_BADGE_RANK = ['MEDIA' => 0, 'TRANSPORT' => 1, 'SCANNING' => 2,
                         'CLEAN' => 3, 'STANDBY' => 4];

function renderDiagDriveList(array $drives, array $verdicts): string {
    $group = ['assigned' => [], 'unassigned' => []];
    foreach ($drives as $d) {
        $dev = (string) ($d['dev'] ?? '');
        if ($dev === '') continue;
        $badge = (string) ($verdicts[$dev] ?? 'CLEAN');
        $row   = ['dev' => $dev, 'role' => (string) ($d['role'] ?? ''), 'badge' => $badge,
                  'rank' => DIAG_BADGE_RANK[$badge] ?? 9];
        $group[$row['role'] === '' ? 'unassigned' : 'assigned'][] = $row;
    }
    $sorter = fn(array $a, array $b) => $a['rank'] === $b['rank']
        ? strcmp($a['dev'], $b['dev'])
        : $a['rank'] <=> $b['rank'];
    usort($group['assigned'],   $sorter);
    usort($group['unassigned'], $sorter);

    $render = function (string $heading, array $rows): string {
        if ($rows === []) return '';
        $out = '<p class="lu-muted" style="font-size:12px;margin:6px 0 2px">'
             . htmlspecialchars($heading) . '</p>';
        foreach ($rows as $r) {
            $out .= '<p><button class="lu-refresh-btn" type="button" onclick="luDiagnose(\''
                  . htmlspecialchars($r['dev'], ENT_QUOTES) . '\')">Diagnose</button> '
                  . '<code>' . htmlspecialchars($r['dev']) . '</code> '
                  . ($r['role'] !== '' ? htmlspecialchars($r['role']) . ' ' : '')
                  . '<span class="lu-diag-pill">' . htmlspecialchars($r['badge']) . '</span></p>';
        }
        return $out;
    };
    /* Worst-first at the GROUP level too: whichever group contains the single
       worst drive on the page renders first, so the drive you should look at
       next is always the first row on the screen -- not just first within its
       own group. A fixed "assigned always above unassigned" order would bury
       a failing unassigned disk under a page of healthy array drives. */
    $minRank = fn(array $rows) => $rows === [] ? PHP_INT_MAX : min(array_column($rows, 'rank'));
    $order = $minRank($group['assigned']) <= $minRank($group['unassigned'])
        ? [['Array and pool', $group['assigned']], ['Unassigned', $group['unassigned']]]
        : [['Unassigned', $group['unassigned']], ['Array and pool', $group['assigned']]];
    $out = '';
    foreach ($order as [$heading, $rows]) $out .= $render($heading, $rows);
    return $out;
}
