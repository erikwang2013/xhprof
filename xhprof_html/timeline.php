<?php
//  Copyright (c) 2009 Facebook
//
//  Licensed under the Apache License, Version 2.0 (the "License");
//  you may not use this file except in compliance with the License.
//  You may obtain a copy of the License at
//
//      http://www.apache.org/licenses/LICENSE-2.0
//
//  Unless required by applicable law or agreed to in writing, software
//  distributed under the License is distributed on an "AS IS" BASIS,
//  WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
//  See the License for the specific language governing permissions and
//  limitations under the License.
//

/**
 * Timeline view for a single XHProf run.
 *
 * Requires a run profiled with xhprof.collect_timeline=1: the extension then
 * records, per function, the start of its first and of its last call (µs
 * since the profiling started), which get_run_timeline() serves. Runs from
 * before that setting (or profiled without it) have no timeline and get a
 * friendly note instead.
 *
 * Each function is one row; the bar runs from the function's FIRST call
 * start to its LAST call start on a time axis. That is a span of activity,
 * not a continuous execution range: a function called once early and once
 * late shows a long bar with idle time in between. The exact values (µs,
 * relative to the start of profiling) are in the bar's title attribute.
 *
 * GET params:
 *   run     run id (like the other report pages)
 *   source  run namespace (default "xhprof")
 *
 * Output: a plain HTML page, no JavaScript.
 */

// by default assume that xhprof_html & xhprof_lib directories
// are at the same level.
$GLOBALS['XHPROF_LIB_ROOT'] = dirname(__FILE__) . '/../xhprof_lib';

require_once $GLOBALS['XHPROF_LIB_ROOT'].'/display/xhprof.php';

$params = array(// run id param
                'run' => array(XHPROF_STRING_PARAM, ''),

                // source/namespace/type of run
                'source' => array(XHPROF_STRING_PARAM, 'xhprof'),
                );

// pull values of these params, and create named globals for each param
xhprof_param_init($params);

$xhprof_runs_impl = new XHProfRuns_Default();

// get_run_timeline() returns null for a missing run and for a run without
// the map alike; the page below cannot tell those apart, so its note
// covers both. An implementation without the accessor has no timeline
// either.
$timeline = null;
if ($run !== '' && method_exists($xhprof_runs_impl, 'get_run_timeline')) {
  $timeline = $xhprof_runs_impl->get_run_timeline($run, $source);
}

$index_url = htmlentities($base_path . '/index.php');

// keep only well-formed entries: crafted run files can carry junk in the
// map, and the arithmetic below must not see it. Each row is
// [first_us, last_us, name].
$rows = array();
if (is_array($timeline)) {
  foreach ($timeline as $fn => $span) {
    // isset (not count): an assoc-shaped span like ['first'=>..,'last'=>..]
    // has count 2 but no [0]/[1] -- reading those would warn.
    if (!is_array($span) || !isset($span[0], $span[1])
        || !is_numeric($span[0]) || !is_numeric($span[1])) {
      continue;
    }
    $first = (float)$span[0];
    $last  = (float)$span[1];
    if (!is_finite($first) || !is_finite($last)) {
      continue;
    }
    // negative or inverted values can't be drawn; clamp instead of dropping
    // (the row still names the function).
    $first = max(0, $first);
    $last  = max($first, $last);
    $rows[] = array($first, $last, (string)$fn);
  }
}

// sort by first-call start, then by name so equal starts stay deterministic
usort($rows, function ($a, $b) {
  return ($a[0] <=> $b[0]) ?: strcmp($a[2], $b[2]);
});

// total span of the axis: the largest last call start (main()'s, when it is
// in the map — it covers the whole run). Guarded against zero so the
// divisions below are safe.
$total = 1.0;
foreach ($rows as $row) {
  $total = max($total, $row[1]);
}

echo "<html><head><title>XHProf timeline</title>";
echo '<meta charset="utf-8">';
echo "<style>\n"
  . "body { font-family: Helvetica, Arial, sans-serif; margin: 8px; }\n"
  . ".tl_note { background: #f0f4ff; border: 1px solid #c0ccea; "
  . "padding: 6px 10px; margin-bottom: 8px; }\n"
  . ".tl_row { display: flex; align-items: center; margin: 2px 0; "
  . "font-size: 13px; }\n"
  . ".tl_name { flex: 0 0 320px; overflow: hidden; text-overflow: ellipsis; "
  . "white-space: nowrap; padding-right: 8px; }\n"
  . ".tl_track { flex: 1 1 auto; position: relative; height: 14px; "
  . "background: #eee; border-radius: 2px; }\n"
  . ".tl_bar { position: absolute; top: 0; height: 14px; min-width: 2px; "
  . "background: #7b9fe0; border-radius: 2px; }\n"
  . ".tl_meta { flex: 0 0 200px; color: #666; padding-left: 8px; "
  . "text-align: right; white-space: nowrap; }\n"
  . "@media (prefers-color-scheme: dark) {\n"
  . "  body { background: #1b1b1b; color: #ddd; }\n"
  . "  a { color: #8ab4f8; }\n"
  . "  .tl_note { background: #23283a; border-color: #40507a; color: #eee; }\n"
  . "  .tl_track { background: #333; }\n"
  . "  .tl_bar { background: #5a7fc0; }\n"
  . "  .tl_meta { color: #aaa; }\n"
  . "}\n"
  . "</style>";
echo "</head><body>";

if (count($rows) === 0) {
  // no run given, run not found, or the run was profiled without
  // xhprof.collect_timeline=1 (runs from before the setting never have it).
  echo "<h3>XHProf timeline</h3>";
  echo "<div class='tl_note'>No timeline data for run &lsquo;"
    . htmlentities($run) . "&rsquo;. The run does not exist, or it was "
    . "profiled without <code>xhprof.collect_timeline=1</code> (older runs "
    . "never carry it).</div>";
  echo "<p><a href='$index_url'>Back to the run list</a></p>";
  echo "</body></html>";
  return;
}

echo "<h3>Timeline for run #" . htmlentities($run) . " (source: "
  . htmlentities($source) . ")</h3>";

echo "<div class='tl_note'>Each bar runs from the function&rsquo;s <b>first "
  . "call start</b> to its <b>last call start</b> (&micro;s since profiling "
  . "began) &mdash; a span of activity, not one continuous execution. "
  . "Hover a bar for the exact values.</div>";

$format_ms = function ($us) {
  return number_format($us / 1000, 2);
};

foreach ($rows as $row) {
  list($first, $last, $fn) = $row;

  $left_pct  = min(100, max(0, $first / $total * 100));
  $width_pct = min(100 - $left_pct, max(0, ($last - $first) / $total * 100));

  $href = $index_url . '?' . htmlentities(http_build_query(array(
            'run' => $run, 'source' => $source, 'symbol' => $fn)));

  $title = 'first call start: ' . (int)round($first) . ' µs; last call '
         . 'start: ' . (int)round($last) . ' µs (relative to profiling '
         . 'start)';

  echo "<div class='tl_row'>";
  echo "<div class='tl_name'>"
    . xhprof_render_link(htmlspecialchars($fn), $href) . "</div>";
  echo "<div class='tl_track'><div class='tl_bar' style='left:"
    . sprintf('%.3f', $left_pct) . "%;width:" . sprintf('%.3f', $width_pct)
    . "%' title='" . htmlentities($title) . "'></div></div>";
  echo "<div class='tl_meta'>start &asymp; " . $format_ms($first)
    . " ms / span &asymp; " . $format_ms($last - $first) . " ms</div>";
  echo "</div>\n";
}

echo "<p style='font-size: 12px; color: #666;'>Collect this with "
  . "<code>xhprof.collect_timeline=1</code> at profile time; times are "
  . "microseconds relative to the start of profiling. "
  . "<a href='$index_url'>Back to the run list</a></p>";
echo "</body></html>";
