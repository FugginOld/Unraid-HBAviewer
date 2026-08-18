<?PHP
/* The overview controller record: what every backend must emit, and where they
   legitimately differ. No framework:
     php tests/controller_schema_test.php   ->  "controller_schema: all pass"  (exit 0)

   Three backends build this record independently -- parse/hba.sh (lsiutil),
   parse/storcli_overview.sh and parse/storcli2_overview.sh -- and nothing
   declared what it is supposed to contain. Every consumer reads it with
   `?? ''` or `?? 'unknown'`, so a field one backend forgets does not crash: it
   renders blank, or silently takes a default that happens to look plausible.
   That is the failure this file exists to make loud.

   It already happened once. storcli2 arrived (issue #19, released 2026.08.17)
   emitting neither card_id, subvendor_id nor topology, and nothing noticed
   until someone went looking. See KNOWN GAPS below for what that costs.

   Why a test and not one shared declaration: a shell script cannot read a PHP
   const, and parsing one would trade a wrong number for a fragile one. This
   repo already settled that argument for ALERT_THRESHOLD -- two declarations,
   one test that fails if they disagree (config_test.php). Same answer here. */

$fails = 0;
function check(string $name, bool $ok): void {
    global $fails;
    echo ($ok ? "PASS  " : "FAIL  ") . $name . "\n";
    if (!$ok) $fails++;
}

/* ── The declaration ──────────────────────────────────────────────────────────
   Which backends must carry each field of the overview record. This is the
   thing the architecture review said was missing; it is data rather than prose
   so that a test can hold it to account. */
const CTL_SCHEMA = [
    // The core: every backend, always. A consumer may read these without a
    // fallback and be right.
    'temp'            => ['lsiutil', 'storcli', 'storcli2'],
    'model'           => ['lsiutil', 'storcli', 'storcli2'],
    'firmware'        => ['lsiutil', 'storcli', 'storcli2'],
    'mode'            => ['lsiutil', 'storcli', 'storcli2'],
    'board_name'      => ['lsiutil', 'storcli', 'storcli2'],
    'port_name'       => ['lsiutil', 'storcli', 'storcli2'],
    'pci_location'    => ['lsiutil', 'storcli', 'storcli2'],
    'pcie_width'      => ['lsiutil', 'storcli', 'storcli2'],
    'pcie_speed'      => ['lsiutil', 'storcli', 'storcli2'],
    'power_mode'      => ['lsiutil', 'storcli', 'storcli2'],
    'alert_threshold' => ['lsiutil', 'storcli', 'storcli2'],
    'temp_band'       => ['lsiutil', 'storcli', 'storcli2'],
    'cfg_band'        => ['lsiutil', 'storcli', 'storcli2'],
    'status'          => ['lsiutil', 'storcli', 'storcli2'],

    // Backend-specific and correct. lsiutil is a SAS2 tool talking to a card
    // through a numbered port, and it reports neither a BIOS version nor a
    // drive count; the storcli family reports both and has no port index to
    // report. Asking either for the other's fields would be asking for a
    // number it cannot know.
    'fw_old'          => ['lsiutil'],
    'port'            => ['lsiutil'],              // optional, see CTL_OPTIONAL
    'bios'            => ['storcli', 'storcli2'],
    'drive_count'     => ['storcli', 'storcli2'],

    /* KNOWN GAPS -- these three SHOULD be on storcli2 and are not.
       card_id is the PCI root port, and it is what card_group.php buckets on:
       a controller without one gets a bucket of its own ("?$i"), so a dual-IOC
       board comes back as two cards instead of one. Harmless today only
       because grouping merges a bucket solely when its size equals the board's
       declared ioc_count, and exactly one board declares more than one
       (SAS9300-16i); no 9600 is in the firmware index at all. The day a
       dual-IOC SAS4 board is added, that board renders as two half-cards --
       which flash.php's own comment calls "the mismatch this feature exists to
       prevent".
       subvendor_id and topology feed the firmware verdict, which reads their
       absence as out-of-scope. Correct today for the same reason: no 9600
       firmware is indexed.
       Closing the gap means threading card_id in as another positional
       argument, because the storcli2 filters are required to stay pure --
       positional args only, no environment reads -- while storcli's parser
       takes it from LSI_CARD_ID. Deliberately not done while no 9600 has run
       this code. */
    'card_id'         => ['lsiutil', 'storcli'],
    'subvendor_id'    => ['lsiutil', 'storcli'],
    'topology'        => ['lsiutil', 'storcli'],
];

/* Fields a backend emits only sometimes. Everything else is required of the
   backends listed above, and a golden missing one fails.
   lsiutil emits `port` only where a port was actually selected -- the fixtures
   that predate the setting have no port to name. */
const CTL_OPTIONAL = ['port' => ['lsiutil']];

/* Which golden belongs to which backend. Enumerated rather than sniffed: a
   heuristic that guessed the backend from the fields present would be reading
   its answer off the thing it is meant to be checking. A new overview golden
   that nobody adds here fails the last check in this file, which is the point
   -- silent drift is the whole risk. */
const CTL_GOLDENS = [
    'lsiutil' => [
        'hba_gen1.json', 'hba_mode_ir.json', 'hba_mode_it.json', 'hba_mode_noport.json',
        'hba_normal.json', 'hba_notemp.json', 'hba_p16.json', 'hba_zerotemp.json',
        'lsiutil_overview.json',
    ],
    'storcli' => [
        'band_65.json', 'band_66.json', 'band_75.json', 'band_76.json',
        'band_85.json', 'band_86.json', 'band_95.json', 'band_96.json',
        'phy_over_floor.json', 'phy_under_floor.json',
        'rollup_faildrive.json', 'rollup_healthy.json', 'rollup_phyerr.json',
        'storcli_dual.json', 'storcli_multi.json', 'storcli_overview.json',
        'storcli_overview_9305.json', 'storcli_overview_chiparg.json',
        'storcli_overview_noencl_ugood.json', 'storcli_overview_pcie.json',
    ],
    'storcli2' => [
        'route_sas4_mpi3mr.json', 'storcli2_overview.json',
        'storcli2_overview_notemp.json', 'storcli2_overview_phyerr.json',
    ],
];

const EXPECTED_DIR = __DIR__ . '/expected';

/* An overview record is one carrying pcie_width. The composers wrap theirs in
   {"backend":…,"controllers":[…]}; the parsers emit one bare record. Health,
   PHY and drives payloads also carry a controllers array, and their records
   are a different shape entirely -- this is the overview shape only. */
function ctl_records(array $doc): array {
    if (isset($doc['controllers']) && is_array($doc['controllers'])) {
        return array_values(array_filter($doc['controllers'],
            fn($r) => is_array($r) && array_key_exists('pcie_width', $r)));
    }
    return array_key_exists('pcie_width', $doc) ? [$doc] : [];
}

function fields_for(string $backend): array {
    $req = $opt = [];
    foreach (CTL_SCHEMA as $field => $backends) {
        if (!in_array($backend, $backends, true)) continue;
        if (in_array($backend, CTL_OPTIONAL[$field] ?? [], true)) $opt[] = $field;
        else                                                      $req[] = $field;
    }
    return [$req, $opt];
}

// Every golden every backend claims, checked field by field.
foreach (CTL_GOLDENS as $backend => $files) {
    [$required, $optional] = fields_for($backend);
    $allowed = array_merge($required, $optional);
    foreach ($files as $file) {
        $path = EXPECTED_DIR . '/' . $file;
        $doc  = json_decode((string) @file_get_contents($path), true);
        if (!is_array($doc)) { check("$file parses", false); continue; }
        $records = ctl_records($doc);
        check("$file: holds at least one overview record", $records !== []);
        foreach ($records as $i => $rec) {
            $have    = array_keys($rec);
            $missing = array_diff($required, $have);
            $extra   = array_diff($have, $allowed);
            check("$backend/$file" . (count($records) > 1 ? "[$i]" : '') . ": every required field present",
                $missing === []);
            check("$backend/$file" . (count($records) > 1 ? "[$i]" : '') . ": no field outside the schema",
                $extra === []);
        }
    }
}

/* The core is what a consumer may read without a fallback. Stated as its own
   assertion because it is the number that matters when a fourth backend is
   written: fourteen fields it has to produce before it can render at all. */
$core = array_keys(array_filter(CTL_SCHEMA, fn($b) => count($b) === 3));
check('the core is the 14 fields every backend emits', count($core) === 14);
check('temp and status are core', in_array('temp', $core, true) && in_array('status', $core, true));

/* The gap, asserted so it cannot close by accident and go unrecorded. If
   storcli2 starts emitting card_id, this fails and whoever did it updates the
   schema above -- which is how the comment explaining the consequence gets
   revisited at the same time. */
foreach (['card_id', 'subvendor_id', 'topology'] as $gap) {
    check("known gap: storcli2 still does not emit $gap",
        !in_array('storcli2', CTL_SCHEMA[$gap], true));
}

/* No overview golden may go unclassified. This is the drift guard: a new
   backend, or a new fixture for an existing one, has to be named above before
   the suite goes green again. */
$classified = array_merge(...array_values(CTL_GOLDENS));
$unclassified = [];
foreach (glob(EXPECTED_DIR . '/*.json') as $path) {
    $doc = json_decode((string) file_get_contents($path), true);
    if (!is_array($doc) || ctl_records($doc) === []) continue;
    if (!in_array(basename($path), $classified, true)) $unclassified[] = basename($path);
}
check('every overview golden is classified: ' . (implode(' ', $unclassified) ?: 'none missing'),
    $unclassified === []);

echo $fails === 0 ? "controller_schema: all pass\n" : "controller_schema: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
