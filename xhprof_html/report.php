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
 * Export a single XHProf run for other tools.
 *
 * GET params:
 *   run     run id (like the report pages)
 *   source  run namespace (default "xhprof")
 *   format  json | csv | callgrind | folded (default json)
 *
 * JSON carries the flat per-function metrics plus the run totals, CSV has
 * one row per function (last row: the totals) and callgrind emits the
 * caller/callee edges in Valgrind's profile exchange format.
 *
 * folded exports a sampling-mode run as folded stacks: one
 * "<frame>;<frame>;... <sample count>" line per distinct stack, ready for
 * the usual flame graph tooling. It is the only format that cannot work
 * off the sanitized run data (the stacks live in string values), so it
 * reads the run raw; for a run that was not collected in sampling mode the
 * response is 400 text/plain.
 *
 * CSV columns: fn, [ct,] then <metric>, excl_<metric> for every metric the
 * run carries; the TOTAL row leaves the exclusive columns empty (there is no
 * per-run exclusive total). csv, callgrind and folded are sent as downloads
 * (Content-Disposition); an unknown format falls back to json. When the run
 * cannot be loaded the response is 404 text/plain.
 */

// by default assume that xhprof_html & xhprof_lib directories
// are at the same level.
$GLOBALS['XHPROF_LIB_ROOT'] = dirname(__FILE__) . '/../xhprof_lib';

require_once $GLOBALS['XHPROF_LIB_ROOT'].'/display/xhprof.php';
require_once $GLOBALS['XHPROF_LIB_ROOT'].'/utils/xhprof_callgrind.php';

$params = array('run' => array(XHPROF_STRING_PARAM, ''),
                'source' => array(XHPROF_STRING_PARAM, 'xhprof'),
                'format' => array(XHPROF_STRING_PARAM, 'json'),
                );

// pull values of these params, and create named globals for each param
xhprof_param_init($params);

$format = strtolower($format);
if (!in_array($format, array('json', 'csv', 'callgrind', 'folded'), true)) {
  $format = 'json';
}

$xhprof_runs_impl = new XHProfRuns_Default();

$file_base = 'xhprof-' . preg_replace('/[^A-Za-z0-9_.-]/', '_', (string)$run);

if ($format === 'folded') {
  // Sampling-mode runs only, and they cannot go through get_run(): their
  // profile is the per-sample stack strings, which get_run() sanitizes to
  // 0. Read the run file raw and check what it holds.
  $raw = ($run !== '' && method_exists($xhprof_runs_impl, 'read_run_raw')) ?
         $xhprof_runs_impl->read_run_raw($run, $source) : null;

  if ($raw === null) {
    // no run id, or a run file that cannot be read/parsed.
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Could not load XHProf run: " . (is_scalar($run) ? $run : '') . "\n";
    return;
  }
  if (!xhprof_is_sampled_run($raw)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "format=folded is only supported for sampling-mode runs "
       . "(collected with xhprof_sample_enable); this run is not one.\n";
    return;
  }

  header('Content-Type: text/plain; charset=UTF-8');
  header('Content-Disposition: attachment; filename="'
         . $file_base . '.folded"');

  // one line per distinct stack, "<frame>;<frame>;... <sample count>". The
  // frames are joined with ";" (the folded-stack convention) where XHProf
  // records the same stacks with "==>".
  $stacks = array();
  foreach ($raw as $stack) {
    // crafted data can carry newlines in a stack string: they would break
    // the one-line-per-stack format.
    $stack = str_replace(array("\r", "\n"), ' ',
                         str_replace('==>', ';', (string)$stack));
    if (!isset($stacks[$stack])) {
      $stacks[$stack] = 0;
    }
    $stacks[$stack]++;
  }
  foreach ($stacks as $stack => $count) {
    echo $stack, ' ', $count, "\n";
  }
  return;
}

$raw_data = ($run !== '') ?
  $xhprof_runs_impl->get_run($run, $source, $description) : null;

if (!is_array($raw_data)) {
  http_response_code(404);
  header('Content-Type: text/plain; charset=UTF-8');
  echo "Could not load XHProf run: " . (is_scalar($run) ? $run : '') . "\n";
  return;
}

if ($format === 'callgrind') {
  header('Content-Type: text/plain; charset=UTF-8');
  header('Content-Disposition: attachment; filename="'
         . $file_base . '.callgrind"');
  echo xhprof_callgrind_report($raw_data, $description);
  return;
}

// both remaining formats work off the flat per-function view.
init_metrics($raw_data, '', '', false);

$totals = array();
$symbol_tab = xhprof_compute_flat_info($raw_data, $totals);
$metrics = xhprof_get_metrics($raw_data);

$flat_data = array();
foreach ($symbol_tab as $symbol => $info) {
  $tmp = $info;
  $tmp['fn'] = $symbol;
  $flat_data[] = $tmp;
}

if ($format === 'json') {
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode(array('run' => $run,
                         'source' => $source,
                         'description' => $description,
                         'metrics' => $metrics,
                         'totals' => $totals,
                         'functions' => $flat_data),
                   JSON_INVALID_UTF8_SUBSTITUTE);
  return;
}

/**
 * fputcsv() with backslash escaping disabled.
 *
 * Passing an empty $escape needs PHP >= 7.4: on 7.2/7.3 fputcsv() rejects it
 * and returns false without writing the row (the CSV would come out empty).
 * There the backslash escape stays on, which is what fputcsv() did anyway
 * before; it only shows up in values carrying a literal backslash.
 */
function xhprof_fputcsv($out, $line) {
  if (PHP_VERSION_ID >= 70400) {
    return fputcsv($out, $line, ',', '"', '');
  }
  return fputcsv($out, $line, ',', '"', '\\');
}

// CSV: fputcsv keeps function names containing commas/quotes/newlines safe.
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="'
       . $file_base . '.csv"');

$columns = array('fn');
if ($display_calls) {
  $columns[] = 'ct';
}
foreach ($metrics as $metric) {
  $columns[] = $metric;
  $columns[] = 'excl_' . $metric;
}

$out = fopen('php://output', 'w');

// UTF-8 BOM: without it Excel guesses the machine's ANSI codepage and
// mangles non-ASCII function names. CSV only -- nothing else here is meant
// to be opened in a spreadsheet.
fwrite($out, "\xEF\xBB\xBF");

// explicit delimiter/enclosure/escape: the escape default is deprecated
// and backslash escaping is not part of CSV anyway.
xhprof_fputcsv($out, $columns);
foreach ($flat_data as $row) {
  $line = array();
  foreach ($columns as $column) {
    $line[] = isset($row[$column]) ? $row[$column] : '';
  }
  xhprof_fputcsv($out, $line);
}

// totals row (exclusive columns have no per-run total: leave them empty)
$line = array('TOTAL');
if ($display_calls) {
  $line[] = isset($totals['ct']) ? $totals['ct'] : '';
}
foreach ($metrics as $metric) {
  $line[] = isset($totals[$metric]) ? $totals[$metric] : '';
  $line[] = '';
}
xhprof_fputcsv($out, $line);

fclose($out);
