<?PHP
/* Shared interpretation of the get_hba_info.sh JSON for display.
   One home for status->color/label, the model/chip/firmware fallbacks, and the
   PCIe-row assembly. The monitor page, the dashboard tile, and the AJAX refresh
   endpoint all consume this — each keeps its own markup/CSS, none re-derives.
   Values are returned RAW; each consumer escapes for its own medium. */

function lsi_status_color(string $s): string {
    return match ($s) { 'alert' => '#fb7185', 'warn' => '#fbbf24', default => '#34d399' };
}
function lsi_status_label(string $s): string {
    return match ($s) { 'alert' => 'ALERT', 'warn' => 'WARNING', default => 'NORMAL' };
}

/* Controllers from a decoded backend payload. Accepts the multi-controller
   contract {"controllers":[...]} and (defensively) a legacy flat single object,
   so consumers can loop uniformly regardless of backend or contract version. */
function lsi_controllers(array $data): array {
    return $data['controllers'] ?? [$data];
}

/* $data = one controller's JSON; $port = configured lsiutil port; $idx = its
   position in the controllers list (for the storcli /cN label). */
function lsi_hba_view(array $data, int $port, int $idx = 0): array {
    $status = $data['status'] ?? 'ok';
    $portName = $data['port_name'] ?? 'ioc0';
    // lsiutil cards name a port ("ioc0 (lsiutil -p1)"); storcli cards name the
    // controller index ("Controller /c0") since port_name is empty there.
    $portLabel = $portName !== '' ? "$portName (lsiutil -p$port)" : "Controller /c$idx";

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
        'fw_old'     => !empty($data['fw_old']),      // SAS2 pre-P20 flag
        'bios'       => $data['bios']        ?? '',   // storcli only
        'mode'       => $data['mode']        ?? '',   // IT/IR (storcli)
        'drives'     => $data['drive_count'] ?? '',   // connected drive count (storcli)
        'port_name'  => $portName,
        'port_label' => $portLabel,
        'pcie'       => $pcie,
    ];
}
