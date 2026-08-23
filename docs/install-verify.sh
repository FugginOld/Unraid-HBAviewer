#!/bin/bash
# Install the dev build on a real Unraid box and prove the plugin still works
# from its INSTALLED location -- the one thing fixtures cannot settle, because
# the render/ split is new and nothing has ever loaded it from /usr/local.
#
# Expects the package at /tmp/hbaviewer.txz (scp it over first).
#
# Installs the same way the .plg does -- BOTH of its blocks, the upgradepkg
# call and the wipe-and-extract that actually places the files -- so this
# exercises the real install path, including a removed file disappearing.
#
# ROLLBACK, if anything below fails or looks wrong -- the extract, not just the
# upgradepkg, because the extract is what places files:
#     rm -rf /usr/local/emhttp/plugins/hbaviewer
#     tar -xJf /boot/config/plugins/hbaviewer/hbaviewer.txz -C /
#     chmod +x /usr/local/emhttp/plugins/hbaviewer/hbaviewer.x86_64
#     chmod +x /usr/local/emhttp/plugins/hbaviewer/scripts/*.sh
# That .txz is the RELEASED package, already on flash -- the .plg put it there.
# A reboot also restores it: Unraid re-runs the .plg at boot, and its own
# install block does exactly the four lines above. Nothing here is permanent.

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
# BOTH halves of what the .plg does, in its order.
#
# upgradepkg first, as the <FILE Run="upgradepkg --install-new"> block does. It
# reports "Skipping package hbaviewer (already installed)" every single time,
# on a real release too: nothing versions the package name, so the installed
# and incoming packages are both plain "hbaviewer" and upgradepkg sees no
# change. Harmless, because it is not what installs the files.
#
# The second <FILE Run="/bin/bash"> block is. It wipes the plugin directory and
# extracts the tarball over /, which is also what makes a REMOVED file actually
# disappear. Skip this half -- as the first version of this script did -- and
# nothing is installed at all, while every check after it happily reports on
# the old code still sitting there.
upgradepkg --install-new "$PKG" 2>&1 | tail -3
rm -rf "$PLUGIN"
tar -xJf "$PKG" -C /
chmod +x "$PLUGIN/hbaviewer.x86_64"
chmod +x "$PLUGIN"/scripts/*.sh
mkdir -p /usr/local/emhttp/plugins/HBAviewer
cp -f "$PLUGIN/icon.png" /usr/local/emhttp/plugins/HBAviewer/hbaviewer.png

echo
echo "=== files ==="
n=$(ls "$PLUGIN/render"/*.php 2>/dev/null | wc -l)
[ "$n" = 8 ] && note OK "render/ holds 8 files" || note FAIL "render/ holds $n files, expected 8"
lines=$(wc -l < "$PLUGIN/ajax_info.php")
[ "$lines" -lt 400 ] && note OK "ajax_info.php is $lines lines (split applied)" \
                     || note FAIL "ajax_info.php is $lines lines -- old version still installed?"

# Stop here if the new code is not actually on disk. Rendering the OLD plugin
# and printing six OK lines is worse than failing: it reads as a pass.
if [ "$fail" = 1 ]; then
    echo
    echo "=== ABORTING: the new code is not installed, so nothing below would"
    echo "    be testing it. Roll back with:"
    echo "    rm -rf $PLUGIN"
    echo "    tar -xJf $ROLLBACK -C /"
    echo "    chmod +x $PLUGIN/hbaviewer.x86_64 $PLUGIN/scripts/*.sh"
    exit 1
fi

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
    echo "    rm -rf $PLUGIN"
    echo "    tar -xJf $ROLLBACK -C /"
    echo "    chmod +x $PLUGIN/hbaviewer.x86_64 $PLUGIN/scripts/*.sh"
fi
exit $fail
