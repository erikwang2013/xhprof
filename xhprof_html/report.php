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
 *   format  json | csv | callgrind (default json)
 *
 * JSON carries the flat per-function metrics plus the run totals, CSV has
 * one row per function (last row: the totals) and callgrind emits the
 * caller/callee edges in Valgrind's profile exchange format.
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
if (!in_array($format, array('json', 'csv', 'callgrind'), true)) {
  $format = 'json';
}

$xhprof_runs_impl = new XHProfRuns_Default();

$raw_data = ($run !== '') ?
  $xhprof_runs_impl->get_run($run, $source, $description) : null;

if (!is_array($raw_data)) {
  http_response_code(404);
  header('Content-Type: text/plain; charset=UTF-8');
  echo "Could not load XHProf run: " . (is_scalar($run) ? $run : '') . "\n";
  return;
}

$file_base = 'xhprof-' . preg_replace('/[^A-Za-z0-9_.-]/', '_', (string)$run);

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

// explicit delimiter/enclosure/escape: the escape default is deprecated
// and backslash escaping is not part of CSV anyway.
fputcsv($out, $columns, ',', '"', '');
foreach ($flat_data as $row) {
  $line = array();
  foreach ($columns as $column) {
    $line[] = isset($row[$column]) ? $row[$column] : '';
  }
  fputcsv($out, $line, ',', '"', '');
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
fputcsv($out, $line, ',', '"', '');

fclose($out);
