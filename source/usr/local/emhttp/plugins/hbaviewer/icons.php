<?php
/* The plugin's SVG icon sprite, in one file because THREE pages need it now:
   the Monitor, Settings, and the flash page all render controls that carry an
   icon. It used to be inline in hbaviewer.php, which is why the other two were
   still using HTML dingbat entities -- they had no sprite to reach for.

   Included, not linked: an external `<use href="file.svg#id">` is same-origin
   and cache-friendly but fails silently in enough contexts to be the wrong
   trade for a warning sign on a firmware flasher. Inline always resolves.

   The display rule ships with the sprite for the same reason the sprite does:
   settings.php does not link chrome.css, so a rule left there would size the
   icon on two pages out of three. It is three declarations; they travel with
   the thing they size. */
?>
<style>
/* Sized in em so the icon tracks whatever text it sits in -- these appear in a
   12.5px tab label and in a 13px <strong> inside a warning paragraph, and a
   fixed px size would be wrong in one of them. The negative vertical-align is
   the usual optical seat: baseline-aligning a square box hangs it too high
   next to lowercase text. */
.lu-i { width: 1em; height: 1em; vertical-align: -.125em; flex: none; }
</style>
<!-- ── HBA Health row icons ──────────────────────────────────────────────────
     Icons are Tabler Icons (https://tabler.io/icons), MIT licensed. Paths are
     verbatim from tabler/tabler-icons: temperature, plug-connected, server-2,
     topology-star-3, cpu, alert-triangle, settings. Keep this notice with the
     sprite.

     Emitted HERE, once, and NOT from ajax_info.php: that file re-renders the
     Health tab on every poll and its HTML replaces the pane's contents, so a
     sprite defined there would be re-inserted each refresh — duplicate DOM ids
     with <use> resolving against whichever copy won. Parsed once here, it
     persists across every poll.

     Ids are `lu-i-` prefixed because the plugin renders inside Unraid's webGui
     DOM, not a standalone page; unprefixed ids can collide with the shell's own
     markup. render/health.php's row loop maps indicator keys to these ids. -->
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
  <symbol id="lu-i-thermal" viewBox="0 0 24 24" fill="none" stroke="currentColor"
          stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <path d="M10 13.5a4 4 0 1 0 4 0v-8.5a2 2 0 0 0 -4 0v8.5" />
    <path d="M10 9l4 0" />
  </symbol>

  <symbol id="lu-i-link" viewBox="0 0 24 24" fill="none" stroke="currentColor"
          stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <path d="M7 12l5 5l-1.5 1.5a3.536 3.536 0 1 1 -5 -5l1.5 -1.5" />
    <path d="M17 12l-5 -5l1.5 -1.5a3.536 3.536 0 1 1 5 5l-1.5 1.5" />
    <path d="M3 21l2.5 -2.5" />
    <path d="M18.5 5.5l2.5 -2.5" />
    <path d="M10 11l-2 2" />
    <path d="M13 14l-2 2" />
  </symbol>

  <symbol id="lu-i-topology" viewBox="0 0 24 24" fill="none" stroke="currentColor"
          stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <path d="M3 7a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v2a3 3 0 0 1 -3 3h-12a3 3 0 0 1 -3 -3v-2" />
    <path d="M3 15a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v2a3 3 0 0 1 -3 3h-12a3 3 0 0 1 -3 -3l0 -2" />
    <path d="M7 8l0 .01" />
    <path d="M7 16l0 .01" />
    <path d="M11 8h6" />
    <path d="M11 16h6" />
  </symbol>

  <symbol id="lu-i-hostlink" viewBox="0 0 24 24" fill="none" stroke="currentColor"
          stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <path d="M10 19a2 2 0 1 0 -4 0a2 2 0 0 0 4 0" />
    <path d="M18 5a2 2 0 1 0 -4 0a2 2 0 0 0 4 0" />
    <path d="M10 5a2 2 0 1 0 -4 0a2 2 0 0 0 4 0" />
    <path d="M6 12a2 2 0 1 0 -4 0a2 2 0 0 0 4 0" />
    <path d="M18 19a2 2 0 1 0 -4 0a2 2 0 0 0 4 0" />
    <path d="M14 12a2 2 0 1 0 -4 0a2 2 0 0 0 4 0" />
    <path d="M22 12a2 2 0 1 0 -4 0a2 2 0 0 0 4 0" />
    <path d="M6 12h4" />
    <path d="M14 12h4" />
    <path d="M15 7l-2 3" />
    <path d="M9 7l2 3" />
    <path d="M11 14l-2 3" />
    <path d="M13 14l2 3" />
  </symbol>

  <symbol id="lu-i-controller" viewBox="0 0 24 24" fill="none" stroke="currentColor"
          stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <path d="M5 6a1 1 0 0 1 1 -1h12a1 1 0 0 1 1 1v12a1 1 0 0 1 -1 1h-12a1 1 0 0 1 -1 -1l0 -12" />
    <path d="M9 9h6v6h-6l0 -6" />
    <path d="M3 10h2" />
    <path d="M3 14h2" />
    <path d="M10 3v2" />
    <path d="M14 3v2" />
    <path d="M21 10h-2" />
    <path d="M21 14h-2" />
    <path d="M14 21v-2" />
    <path d="M10 21v-2" />
  </symbol>

  <!-- The two below replaced the HTML entities for U+26A0 and U+2699. The
       warning sign especially: U+26A0 takes EMOJI presentation on Windows and
       Android, which renders it in the font's own yellow and ignores the
       colour the surrounding element sets — so a danger marker rendered as
       decoration, in a fixed hue, next to a firmware flasher. A stroked path
       inherits currentColor and cannot do that. -->
  <symbol id="lu-i-warn" viewBox="0 0 24 24" fill="none" stroke="currentColor"
          stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <path d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.87l-8.106 -13.536a1.914 1.914 0 0 0 -3.274 0z" />
    <path d="M12 9v4" />
    <path d="M12 16v.01" />
  </symbol>

  <symbol id="lu-i-settings" viewBox="0 0 24 24" fill="none" stroke="currentColor"
          stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <path d="M10.325 4.317c.426 -1.756 2.924 -1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543 -.94 3.31 .826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756 .426 1.756 2.924 0 3.35a1.724 1.724 0 0 0 -1.066 2.573c.94 1.543 -.826 3.31 -2.37 2.37a1.724 1.724 0 0 0 -2.572 1.065c-.426 1.756 -2.924 1.756 -3.35 0a1.724 1.724 0 0 0 -2.573 -1.066c-1.543 .94 -3.31 -.826 -2.37 -2.37a1.724 1.724 0 0 0 -1.065 -2.572c-1.756 -.426 -1.756 -2.924 0 -3.35a1.724 1.724 0 0 0 1.066 -2.573c-.94 -1.543 .826 -3.31 2.37 -2.37c1 .608 2.296 .07 2.572 -1.065z" />
    <path d="M9 12a3 3 0 1 0 6 0a3 3 0 0 0 -6 0" />
  </symbol>
</svg>
