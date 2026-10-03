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
 * Approximate flame graph view for a single XHProf run.
 *
 * XHProf stores aggregated parent==>child edge data (one row per pair,
 * summed over every call), not the individual call frames a real flame
 * graph is built from. This view approximates one: each function's
 * inclusive metric is apportioned over its outgoing edges by each edge's
 * share of the function's total incoming metric, and the remainder is the
 * function's self time. The page is labelled as approximate for that
 * reason.
 *
 * The nested JSON tree is rendered by js/flamegraph.js.
 *
 * GET params:
 *   run        run id (like the other report pages)
 *   source     run namespace (default "xhprof")
 *   metric     metric used for the frame widths (default "wt"; falls back
 *              to the run's first available metric if the run lacks it)
 *   threshold  frames narrower than this fraction of the root frame are
 *              folded into "(others)" (default 0.01)
 *
 * Output: an HTML page with the tree inlined as JSON for js/flamegraph.js.
 * Recursion is capped (depth 512, 20000 nodes) so a huge run still renders.
 */

// by default assume that xhprof_html & xhprof_lib directories
// are at the same level.
$GLOBALS['XHPROF_LIB_ROOT'] = dirname(__FILE__) . '/../xhprof_lib';

require_once $GLOBALS['XHPROF_LIB_ROOT'].'/display/xhprof.php';

$params = array(// run id param
                'run' => array(XHPROF_STRING_PARAM, ''),

                // source/namespace/type of run
                'source' => array(XHPROF_STRING_PARAM, 'xhprof'),

                // metric used for the frame widths
                'metric' => array(XHPROF_STRING_PARAM, 'wt'),

                // frames narrower than this fraction of the root are folded
                // into an "(others)" frame, like callgraph's threshold.
                'threshold' => array(XHPROF_FLOAT_PARAM, 0.01),
                );

// pull values of these params, and create named globals for each param
xhprof_param_init($params);

// if invalid value specified for threshold, then use the default
if ($threshold < 0 || $threshold > 1) {
  $threshold = $params['threshold'][1];
}

/**
 * Build the nested flame graph tree below one occurrence of $name.
 *
 * A node's width is the share of $width apportioned to this occurrence of
 * $name; its children are the outgoing edges, each widened by
 * share = width * edge_metric / total_incoming_metric, and whatever is
 * left over is self time.
 *
 * @param string $name           function name
 * @param double $width          width in metric units for this occurrence
 * @param array  $edges          map[parent][child] => edge metric value
 * @param array  $children_table map[parent] => list of children
 * @param array  $total_in       map[name] => total incoming metric value
 * @param double $threshold      fold frames narrower than this * root
 * @param double $root_w         root width (main()'s metric value)
 * @param int    $depth          current depth (recursion guard)
 * @param int    $node_count     OUT: number of nodes emitted so far
 * @param int    $max_depth      give up expanding below this depth
 * @param int    $max_nodes      give up expanding past this node count
 *
 * @return array nested node: n=name, w=width, s=self time, c=children
 */
function xhprof_flamegraph_node($name, $width, $edges, $children_table,
                                $total_in, $threshold, $root_w, $depth,
                                &$node_count, $max_depth, $max_nodes) {
  // crafted data can carry negative metric values: a frame width can't be
  // negative, clamp it at zero.
  $width = max(0, $width);
  $node_count++;

  $node = array('n' => $name, 'w' => (int)round($width),
                's' => (int)round($width), 'c' => array());

  if ($depth >= $max_depth || $node_count >= $max_nodes ||
      empty($children_table[$name])) {
    return $node;
  }

  $den = isset($total_in[$name]) ? $total_in[$name] : 0;
  $shares = array();
  $shares_total = 0;

  foreach ($children_table[$name] as $child) {
    // defensive: skip edges the child table does not line up with
    if ($child === $name || !isset($edges[$name][$child])) {
      continue;
    }
    $edge_w = $edges[$name][$child];
    $share = ($den > 0) ? ($width * $edge_w / $den) : 0;
    if ($share <= 0) {
      continue;
    }
    $shares[] = array($child, $share);
    $shares_total += $share;
  }

  // crafted data can have the children add up to more than the parent
  // (each share uses its own denominator): scale them down so the picture
  // stays inside the parent frame.
  $scale = ($shares_total > $width && $shares_total > 0) ?
           ($width / $shares_total) : 1;

  // fold the frames below the threshold into one "(others)" frame; their
  // width still counts towards the parent's children, so nothing leaks
  // into self time.
  $others = 0;
  $frames = array();
  foreach ($shares as $one) {
    list($child, $share) = $one;
    $share *= $scale;

    if ($threshold > 0 && $root_w > 0 && $share < $threshold * $root_w) {
      $others += $share;
      continue;
    }
    $frames[] = array($child, $share);
  }
  if ($others > 0) {
    $frames[] = array('(others)', $others);
  }

  // Round frame widths cumulatively: each frame's width is the difference
  // between consecutive rounded boundaries, so rounding can never push the
  // children outside their parent.
  $children = array();
  $boundary = 0.0;
  $rounded = 0;
  foreach ($frames as $one) {
    list($child, $share) = $one;
    $boundary += $share;
    $next = (int)round($boundary);
    $child_w = $next - $rounded;
    if ($child_w <= 0) {
      continue;
    }
    $rounded = $next;

    $child_node = xhprof_flamegraph_node($child, $child_w, $edges,
                                         $children_table, $total_in,
                                         $threshold, $root_w, $depth + 1,
                                         $node_count, $max_depth, $max_nodes);
    $children[] = $child_node;
  }

  $node['c'] = $children;
  // whatever the (scaled) children do not cover is self time. $rounded is
  // the int sum of the emitted children's widths.
  $node['s'] = max(0, $node['w'] - $rounded);

  return $node;
}

/**
 * Build the flame graph tree for a whole run.
 *
 * @param array  $raw_data   raw XHProf run data
 * @param string $metric     metric used for the widths
 * @param double $threshold  fold frames below this fraction of the root
 *
 * @return array root node (main()), or null when the run has no data
 */
function xhprof_flamegraph_tree($raw_data, $metric, $threshold) {
  // main() has no incoming edge: its total is the bare "main()" entry,
  // which xhprof_get_children_table() files under the "" parent.
  $root_w = isset($raw_data['main()'][$metric]) ?
            $raw_data['main()'][$metric] : 0;

  $children_table = xhprof_get_children_table($raw_data);

  $edges = array();
  $total_in = array();
  // main() is the root, it has no incoming edge: its denominator is the
  // bare "main()" entry itself.
  $total_in['main()'] = $root_w;
  foreach ($raw_data as $parent_child => $info) {
    if ($parent_child === 'main()') {
      continue;
    }
    list($parent, $child) = xhprof_parse_parent_child($parent_child);
    if ($child === null) {
      continue;
    }
    $w = isset($info[$metric]) ? $info[$metric] : 0;
    $edges[$parent][$child] = $w;
    $total_in[$child] = (isset($total_in[$child]) ? $total_in[$child] : 0)
                        + $w;
  }

  $node_count = 0;
  return xhprof_flamegraph_node('main()', $root_w, $edges, $children_table,
                                $total_in, $threshold, $root_w, 0,
                                $node_count, 512, 20000);
}

$xhprof_runs_impl = new XHProfRuns_Default();

$raw_data = ($run !== '') ?
  $xhprof_runs_impl->get_run($run, $source, $description) : null;

$index_url = htmlentities($base_path . '/index.php');

if (!is_array($raw_data)) {
  // no run, or an unreadable/corrupt one: say so and go no further.
  echo "<html><head><title>XHProf flame graph</title></head><body>";
  echo "<b>Approximate flame graph</b><hr>";
  echo "Could not load XHProf run: '"
       . htmlentities($run) . "'.";
  echo "<p><a href='$index_url'>Back to the run list</a></p>";
  echo "</body></html>";
  return;
}

// fall back to a metric the run actually carries (wall time is not
// collected by the C++ profiler, for instance).
$metrics = xhprof_get_metrics($raw_data);
if (!in_array($metric, $metrics, true)) {
  $metric = count($metrics) ? $metrics[0] : '';
}

$tree = xhprof_flamegraph_tree($raw_data, $metric, $threshold);
$tree_json = json_encode($tree, JSON_INVALID_UTF8_SUBSTITUTE);

$possible_metrics = xhprof_get_possible_metrics();
$metric_desc = isset($possible_metrics[$metric][2]) ?
               $possible_metrics[$metric][2] : $metric;

$base_url_params = array('run' => $run, 'source' => $source);
if ($metric !== 'wt') {
  $base_url_params['metric'] = $metric;
}

echo "<html><head><title>XHProf flame graph (approximate)</title>";
echo '<meta charset="utf-8">';
echo "<style>\n"
  . "body { font-family: Helvetica, Arial, sans-serif; margin: 8px; }\n"
  . ".approx_banner { background: #fff3cd; border: 1px solid #e0c060; "
  . "padding: 6px 10px; margin-bottom: 8px; }\n"
  . "#breadcrumb { margin: 6px 0px; font-size: 13px; }\n"
  . "#breadcrumb span { cursor: pointer; color: #3b5998; }\n"
  . "#flamegraph { overflow-x: hidden; }\n"
  . "#flamegraph text { pointer-events: none; font-size: 11px; "
  . "font-family: Helvetica, Arial, sans-serif; }\n"
  . "</style>";
echo "</head><body>";

echo '<div class="approx_banner"><b>Approximate flame graph.</b> '
  . 'XHProf only stores aggregated caller&rarr;callee data (one row per pair), '
  . 'so each function&rsquo;s ' . htmlentities($metric_desc)
  . ' is apportioned over its outgoing calls by share. Frame widths are '
  . 'estimates, not exact call frames.</div>';

echo "<h3>Flame graph for run #" . htmlentities($run) . " (source: "
  . htmlentities($source) . ", metric: " . htmlentities($metric_desc) . ")</h3>";

echo "<div id='breadcrumb'></div>";
echo "<div id='flamegraph'></div>";

// threshold form: fold frames narrower than this fraction of the total
echo "<form method='get' action='" . htmlentities($_SERVER['SCRIPT_NAME'])
  . "' style='margin-top: 10px; font-size: 13px;'>";
foreach ($base_url_params as $k => $v) {
  echo "<input type='hidden' name='" . htmlentities($k) . "' value='"
    . htmlentities($v) . "'>";
}
echo "<label>Frames below <input type='text' name='threshold' size='6' "
  . "value='" . htmlentities($threshold) . "'> of the total are folded into "
  . "&ldquo;(others)&rdquo; <input type='submit' value='Apply'></label>";
echo " <a href='$index_url'>Back to the run list</a>";
echo "</form>";

echo "<script type='text/javascript'>var xhprof_flamegraph_data = "
  . $tree_json . ";</script>";
echo "<script type='text/javascript' src='" . htmlentities($base_path)
  . "/js/flamegraph.js'></script>";
echo "</body></html>";
