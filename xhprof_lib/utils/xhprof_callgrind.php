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
 * Events map XHProf's metrics as Time=wt, Cpu=cpu, MemUse=mu (Samples for
 * sampled runs); only the metrics a run actually carries become columns.
 * Each function's self cost is its exclusive metrics, each cfn=/calls= pair
 * the callee's inclusive metrics for that parent==>child edge.
 *
 * When the optional $files map (the run's "__files__", collected with
 * xhprof.collect_files) is passed in, each function and call target is
 * preceded by fl=/cfl= with its definition file, and the cost position is
 * the definition line instead of 0 (the position is the first column of a
 * cost line; per the format spec, fi=/fe= are for file changes *inside* a
 * function, so they are not used here). Symbols without a map entry get
 * fl=??? so they are not attributed to the previous function's file.
 *
 * Approximate by construction: XHProf has no per-callsite split, so all
 * calls from a caller to a callee are lumped into one entry, and costs are
 * emitted as rounded integers. Call-site positions stay 0 (the call targets
 * carry their definition file, not the line of the call).
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
 * Format one callgrind cost line: the position (source line, 0 when
 * unknown) followed by one value per column of the "events:" header.
 *
 * @param array $info  metrics for a function or call
 * @param array $keys  ordered metric keys making up the event columns
 * @param int   $line  source line for the position column
 *
 * @return string cost line, "\n" terminated
 */
function xhprof_callgrind_cost_line($info, $keys = array('wt', 'cpu', 'mu'),
                                    $line = 0) {
  $line = (string)max(0, (int)$line);
  foreach ($keys as $key) {
    // crafted or diff data can carry negative values; callgrind costs are
    // non-negative.
    $value = isset($info[$key]) ? $info[$key] : 0;
    $line .= " " . (int)round(max(0, $value));
  }
  return $line . "\n";
}

/**
 * Split a "__files__" value ("/path/file.php:42") for the callgrind
 * emitter: returns array(filename, line). A value without a trailing
 * ":digits" is treated as a plain filename with an unknown (0) line.
 *
 * @param array  $files   function => "file:line" map (may be null)
 * @param string $symbol  function name (recursion suffix already stripped)
 *
 * @return array array($file, $line); $file is null when unknown
 */
function xhprof_callgrind_file_of($files, $symbol) {
  if (!is_array($files) || !isset($files[$symbol])
      || !is_scalar($files[$symbol])) {
    return array(null, 0);
  }

  $file_line = (string)$files[$symbol];
  $colon = strrpos($file_line, ':');
  if ($colon !== false && $colon > 0
      && ctype_digit(substr($file_line, $colon + 1))) {
    return array(substr($file_line, 0, $colon),
                 (int)substr($file_line, $colon + 1));
  }

  return array($file_line === '' ? null : $file_line, 0);
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
 * @param array  $files     optional function => "file:line" map
 *                          (get_run_files()); adds fl=/cfl= and real cost
 *                          positions. Null keeps the position-less output
 *
 * @return string callgrind text
 */
function xhprof_callgrind_report($raw_data, $run_desc = '', $files = null) {
  $totals = array();
  $symbol_tab = xhprof_compute_flat_info($raw_data, $totals);

  // one event column per metric the run actually carries: a wall time only
  // run must not grow constant-zero Cpu / MemUse columns. The event names
  // times keep the mapping documented above; "samples" (sampling profiler
  // runs) is passed through under its own name.
  $event_names = array('wt' => 'Time', 'cpu' => 'Cpu', 'mu' => 'MemUse',
                       'samples' => 'Samples');
  $keys = array();
  $names = array();
  foreach (xhprof_get_metrics($raw_data) as $metric) {
    if (isset($event_names[$metric])) {
      $keys[] = $metric;
      $names[] = $event_names[$metric];
    }
  }
  if (empty($keys)) {
    // degenerate (e.g. empty) run: keep a well formed header.
    $keys = array('wt', 'cpu', 'mu');
    $names = array('Time', 'Cpu', 'MemUse');
  }

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
  $out .= "events: " . implode(' ', $names) . "\n";
  $out .= "\n";

  // fl= persists until changed, so file mode emits one for every function:
  // "???" (the conventional unknown-file marker) keeps map-less symbols
  // out of the previous function's file
  $file_mode = is_array($files) && count($files) > 0;

  foreach ($symbol_tab as $symbol => $info) {
    // recursion variants (rec@1) appear as their own rows in the flat
    // table but share the base function's map entry
    list($file, $line) = $file_mode
      ? xhprof_callgrind_file_of($files, preg_replace('/@\d+$/', '', $symbol))
      : array(null, 0);
    if ($file_mode) {
      $out .= "fl=" . xhprof_callgrind_name($file !== null ? $file : '???')
            . "\n";
    }
    $out .= "fn=" . xhprof_callgrind_name($symbol) . "\n";
    $self = array();
    foreach ($keys as $key) {
      $self[$key] = isset($info['excl_' . $key]) ? $info['excl_' . $key] : 0;
    }
    $out .= xhprof_callgrind_cost_line($self, $keys, $line);

    if (!empty($calls[$symbol])) {
      foreach ($calls[$symbol] as $call) {
        $call_info = $call['info'];
        if ($file_mode) {
          // recursion variants (foo@1) share the base function's file
          $callee_file = xhprof_callgrind_file_of(
            $files, preg_replace('/@\d+$/', '', $call['child']));
          $out .= "cfl=" . xhprof_callgrind_name(
                    $callee_file[0] !== null ? $callee_file[0] : '???') . "\n";
        }
        // a call count of zero has no callgrind representation; the
        // minimum meaningful count is one.
        $ct = isset($call_info['ct']) ? (int)round($call_info['ct']) : 1;
        $out .= "cfn=" . xhprof_callgrind_name($call['child']) . "\n";
        $out .= "calls=" . max(1, $ct) . " 0\n";
        $out .= xhprof_callgrind_cost_line($call_info, $keys);
      }
    }
    $out .= "\n";
  }

  return $out;
}
