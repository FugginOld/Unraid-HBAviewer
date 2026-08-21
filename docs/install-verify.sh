#!/bin/bash
# Install the dev build on a real Unraid box and prove the plugin still works
# from its INSTALLED location -- the one thing fixtures cannot settle, because
# the render/ split is new and nothing has ever loaded it from /usr/local.
#
# Expects the package at /tmp/hbaviewer.txz (scp it over first).
#
# Installs the same way the .plg does (upgradepkg --install-new), so this
# exercises the real install path, including upgradepkg removing the files the
# old package had and this one does not.
#
# ROLLBACK, if anything below fails or looks wrong:
#     upgradepkg --reinstall /boot/config/plugins/hbaviewer/hbaviewer.txz
# That file is the RELEASED package, already on flash -- the .plg put it there.
# A reboot also restores it: Unraid re-runs the .plg at boot and reinstalls the
# released version, so nothing here is permanent.

PKG=/tmp/hbaviewer.txz
PLUGIN=/usr/local/emhttp/plugins/hbaviewer
ROLLBACK=/boot/config/plugins/hbaviewer/hbaviewer.txz
fail=0
note() { printf '%-6s %s\n' "$1" "$2"; [ "$1" = FAIL ] && fail=1; return 0; }

echo "=== preflight ==="
[ -f "$PKG" ] || { echo "no package at $PKG -- scp it over first"; exit 2; }
if [ -f "$ROLLBACK" ]; then
    note OK "rollback package present ($(md5sum "$ROLLBACK" | cut -c1-12)...)"
else
    echo "REFUSING: $ROLLBACK is missing, so there is no one-command way back."
    echo "Reinstall the plugin from Community Applications first, then re-run."
    exit 2
fi
echo "       installed before: $(cat /var/log/packages/hbaviewer* 2>/dev/null | head -1 | tr -d '\n')"
echo "       ajax_info.php before: $(wc -l < "$PLUGIN/ajax_info.php" 2>/dev/null) lines"
echo "       render/ before: $(ls "$PLUGIN/render" 2>/dev/null | wc -l) files"

echo
echo "=== install ==="
upgradepkg --install-new "$PKG" 2>&1 | tail -3

echo
echo "=== files ==="
n=$(ls "$PLUGIN/render"/*.php 2>/dev/null | wc -l)
[ "$n" = 8 ] && note OK "render/ holds 8 files" || note FAIL "render/ holds $n files, expected 8"
lines=$(wc -l < "$PLUGIN/ajax_info.php")
[ "$lines" -lt 400 ] && note OK "ajax_info.php is $lines lines (split applied)" \
                     || note FAIL "ajax_info.php is $lines lines -- old version still installed?"

echo
echo "=== php syntax, every file ==="
bad=$(find "$PLUGIN" -name '*.php' -exec php -l {} \; 2>&1 | grep -v '^No syntax errors' | head -5)
[ -z "$bad" ] && note OK "all PHP files parse" || { note FAIL "parse errors:"; echo "$bad"; }

echo
echo "=== every tab renderer, against THIS box's hardware ==="
# Reads live, from the installed tree, exactly as the webgui would. Diagnostics
# are fatal here: a renderer that emits its table while warning about a missing
# key is the defect this whole exercise exists to catch.
php -d error_reporting=E_ALL -d display_errors=1 -r '
$P = "'"$PLUGIN"'";
require "$P/ajax_info.php";
$S = "$P/scripts";
$read = function ($s) use ($S) {
    return (array) json_decode((string) shell_exec("bash $S/$s.sh 2>/dev/null"), true);
};
$ov = $read("get_hba_info");
$tabs = [
    "overview" => fn() => renderOverviewCards($ov, lsi_config_read()),
    "health"   => fn() => renderHealthTables($read("get_hba_health"), lsi_config_read()),
    "phy"      => fn() => renderPhyTables($read("get_phy_health")),
    "drives"   => fn() => renderDrivesTables($read("get_attached_drives")),
    "events"   => fn() => renderEventsTables($read("get_event_log")),
    "smart"    => fn() => renderSmartTable([]),
];
foreach ($tabs as $name => $fn) {
    try {
        $html = $fn();
        $err  = substr_count($html, "lu-error");
        printf("%-6s %-9s %6d bytes%s\n",
            strlen($html) > 0 ? "OK" : "FAIL", $name, strlen($html),
            $err ? "  ($err error card(s) -- check the tab)" : "");
    } catch (Throwable $e) {
        printf("%-6s %-9s threw %s: %s\n", "FAIL", $name, get_class($e), $e->getMessage());
    }
}
' 2>&1 | tee /tmp/hbav-render.log
grep -qE "^(Warning|Deprecated|Notice|Fatal error|Parse error):" /tmp/hbav-render.log \
    && note FAIL "PHP emitted diagnostics while rendering (see above)" \
    || note OK   "no PHP diagnostics from any renderer"
grep -q "^FAIL" /tmp/hbav-render.log && fail=1

echo
if [ "$fail" = 0 ]; then
    echo "=== PASS -- now open the plugin in the browser and click every tab. ==="
    echo "This proved it loads and renders; only your eyes prove it looks right."
else
    echo "=== FAILURES above. Roll back with: ==="
    echo "    upgradepkg --reinstall $ROLLBACK"
fi
exit $fail
