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

/*
 * This file contains callgrind (Valgrind profile exchange format) export
 * related XHProf utility functions.
 *
 * See http://valgrind.org/docs/manual/cl-format.html for the format.
 */

/**
 * Sanitize a function name for the callgrind format.
 *
 * The format is line based, so a name must never span lines. The raw
 * profiler data is untrusted: strip anything that would break the layout.
 *
 * @param string $name function name
 *
 * @return string name safe to put after fn=/cfn=
 */
function xhprof_callgrind_name($name) {
  return str_replace(array("\r", "\n", "\t"), ' ', (string)$name);
}

/**
 * Format one callgrind cost line: the position (line number, XHProf has
 * none so 0) followed by one value per column of the "events:" header.
 *
 * @param array $info  metrics for a function or call
 * @param array $keys  ordered metric keys making up the event columns
 *
 * @return string cost line, "\n" terminated
 */
function xhprof_callgrind_cost_line($info, $keys = array('wt', 'cpu', 'mu')) {
  $line = "0";
  foreach ($keys as $key) {
    // crafted or diff data can carry negative values; callgrind costs are
    // non-negative.
    $value = isset($info[$key]) ? $info[$key] : 0;
    $line .= " " . (int)round(max(0, $value));
  }
  return $line . "\n";
}

/**
 * Render an XHProf run in callgrind format.
 *
 * The events map XHProf's metrics as: Time=wt, Cpu=cpu, MemUse=mu.
 * Each function gets one "fn=" block holding its exclusive metrics as self
 * cost and one cfn=/calls= pair per outgoing edge with the callee's
 * inclusive metrics as the call cost.
 *
 * @param array  $raw_data  raw XHProf run data
 * @param string $run_desc  text for the "cmd" header line
 *
 * @return string callgrind text
 */
function xhprof_callgrind_report($raw_data, $run_desc = '') {
  $totals = array();
  $symbol_tab = xhprof_compute_flat_info($raw_data, $totals);

  // group outgoing edges per caller: callgrind wants the calls listed
  // inside their caller's fn= block.
  $calls = array();
  foreach ($raw_data as $parent_child => $info) {
    if ($parent_child === 'main()') {
      continue;
    }
    list($parent, $child) = xhprof_parse_parent_child($parent_child);
    if ($parent === null || $child === null || $parent == $child ||
        !isset($symbol_tab[$parent]) || !isset($symbol_tab[$child])) {
      continue;
    }
    $calls[$parent][] = array('child' => $child, 'info' => $info);
  }

  $out = "# callgrind format\n";
  $out .= "version: 1\n";
  $out .= "creator: xhprof\n";
  $out .= "pid: 0\n";
  $out .= "cmd: " . xhprof_callgrind_name(
            ($run_desc !== '') ? $run_desc : 'xhprof run') . "\n";
  $out .= "events: Time Cpu MemUse\n";
  $out .= "\n";

  foreach ($symbol_tab as $symbol => $info) {
    $out .= "fn=" . xhprof_callgrind_name($symbol) . "\n";
    $out .= xhprof_callgrind_cost_line(array(
                'wt' => isset($info['excl_wt']) ? $info['excl_wt'] : 0,
                'cpu' => isset($info['excl_cpu']) ? $info['excl_cpu'] : 0,
                'mu' => isset($info['excl_mu']) ? $info['excl_mu'] : 0,
              ));

    if (!empty($calls[$symbol])) {
      foreach ($calls[$symbol] as $call) {
        $call_info = $call['info'];
        // a call count of zero has no callgrind representation; the
        // minimum meaningful count is one.
        $ct = isset($call_info['ct']) ? (int)round($call_info['ct']) : 1;
        $out .= "cfn=" . xhprof_callgrind_name($call['child']) . "\n";
        $out .= "calls=" . max(1, $ct) . " 0\n";
        $out .= xhprof_callgrind_cost_line($call_info);
      }
    }
    $out .= "\n";
  }

  return $out;
}
