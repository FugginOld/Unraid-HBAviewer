<?PHP
/* Runnable check for view.php: status map, fallbacks, PCIe assembly.
   Needs php (present on the Unraid box):  php tests/view_test.php  */

require_once __DIR__ . '/../source/usr/local/emhttp/plugins/lsiutil/view.php';

$fails = 0;
function check(string $name, bool $ok): void {
    global $fails;
    echo ($ok ? "PASS  " : "FAIL  ") . $name . "\n";
    if (!$ok) $fails++;
}

// status map
check('color ok',    lsi_status_color('ok')    === '#2ecc71');
check('color warn',  lsi_status_color('warn')  === '#f39c12');
check('color alert', lsi_status_color('alert') === '#e74c3c');
check('label alert', lsi_status_label('alert') === 'ALERT');

// full view over a representative payload
$data = [
    'temp' => 47, 'status' => 'ok',
    'model' => 'SAS2308', 'firmware' => '14.00.07.00',
    'port_name' => 'ioc0', 'board_name' => 'SAS9207-8i',
    'pci_location' => '03:00', 'pcie_width' => 'x8',
    'pcie_speed' => 'Gen3 (8.0 GT/s)', 'power_mode' => 'Full',
];
$v = lsi_hba_view($data, 1);
check('temp',       $v['temp'] === 47);
check('color',      $v['color'] === '#2ecc71');
check('label',      $v['label'] === 'NORMAL');
check('model=board',$v['model'] === 'SAS9207-8i');       // board_name wins
check('chip=model', $v['chip'] === 'SAS2308');
check('port label', $v['port_label'] === 'ioc0 (lsiutil -p1)');
check('pcie count', count($v['pcie']) === 4);
check('pcie order', $v['pcie'][0]['label'] === 'PCIe Width' && $v['pcie'][0]['value'] === 'x8');

// fallbacks + empty PCIe
$bare = lsi_hba_view(['temp' => 30, 'status' => 'alert'], 2);
check('model fallback', $bare['model'] === 'Unknown');
check('port name def',  $bare['port_label'] === 'ioc0 (lsiutil -p2)');
check('pcie empty',     $bare['pcie'] === []);
check('alert color',    $bare['color'] === '#e74c3c');

echo $fails === 0 ? "view: all pass\n" : "view: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
