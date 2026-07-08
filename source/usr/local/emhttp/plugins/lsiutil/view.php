<?PHP
/* Shared interpretation of the get_hba_info.sh JSON for display.
   One home for status->color/label, the model/chip/firmware fallbacks, and the
   PCIe-row assembly. The monitor page, the dashboard tile, and the AJAX refresh
   endpoint all consume this — each keeps its own markup/CSS, none re-derives.
   Values are returned RAW; each consumer escapes for its own medium. */

function lsi_status_color(string $s): string {
    return match ($s) { 'alert' => '#e74c3c', 'warn' => '#f39c12', default => '#2ecc71' };
}
function lsi_status_label(string $s): string {
    return match ($s) { 'alert' => 'ALERT', 'warn' => 'WARNING', default => 'NORMAL' };
}

/* $data = decoded get_hba_info.sh JSON; $port = configured lsiutil port (for the label). */
function lsi_hba_view(array $data, int $port): array {
    $status = $data['status'] ?? 'ok';

    $pcie = [];
    foreach ([
        'pcie_width'   => 'PCIe Width',
        'pcie_speed'   => 'PCIe Speed',
        'power_mode'   => 'Power Mode',
        'pci_location' => 'PCI Location',
    ] as $key => $label) {
        if (!empty($data[$key])) $pcie[] = ['label' => $label, 'value' => $data[$key]];
    }

    return [
        'temp'       => $data['temp'] ?? '',
        'status'     => $status,
        'color'      => lsi_status_color($status),
        'label'      => lsi_status_label($status),
        'model'      => !empty($data['board_name']) ? $data['board_name'] : ($data['model'] ?? 'Unknown'),
        'chip'       => $data['model']     ?? 'Unknown',
        'firmware'   => $data['firmware']  ?? 'Unknown',
        'port_name'  => $data['port_name'] ?? 'ioc0',
        'port_label' => ($data['port_name'] ?? 'ioc0') . " (lsiutil -p$port)",
        'pcie'       => $pcie,
    ];
}
