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
 * Flame graph view for a single XHProf run.
 *
 * Two kinds of run are handled, and the page says which one it is showing:
 *
 *  - Sampling-mode runs (xhprof_sample_enable) carry one whole call stack
 *    per sample. Their tree is exact: frames are counted along the stacks,
 *    so a frame's width is the number of samples that really carried it and
 *    a path exists only when a sample really took it. Banner: "Sampled".
 *
 *  - Hierarchical runs store aggregated parent==>child edge data (one row
 *    per pair, summed over every call), not the individual call frames a
 *    real flame graph is built from. This view approximates one: each
 *    function's inclusive metric is apportioned over its outgoing edges by
 *    each edge's share of the function's total incoming metric, and the
 *    remainder is the function's self time. Banner: "Approximate".
 *
 * The nested JSON tree is rendered by js/flamegraph.js.
 *
 * GET params:
 *   run        run id (like the other report pages)
 *   source     run namespace (default "xhprof")
 *   metric     metric used for the frame widths (default "wt"; falls back
 *              to the run's first available metric if the run lacks it;
 *              sampling-mode runs always use their sample count)
 *   threshold  frames narrower than this fraction of the root frame are
 *              folded into "(others)" (default 0.01)
 *
 * Output: an HTML page with the tree inlined as JSON for js/flamegraph.js.
 * Recursion is capped (depth 512, 20000 nodes) so a huge run still renders;
 * a run that hits a cap gets "truncated":true in the JSON, a page banner,
 * and the cut frames folded into "(others)" instead of silently turning
 * into self time.
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

// if invalid value specified for threshold, then use the default. The
// upstream float cast quietly turns "abc" (or "") into 0.0, which would mean
// "fold nothing at all" -- not what the caller asked for -- so a value that
// is not a number falls back to the default as well. "0" is a number: it
// still means "fold nothing".
$threshold_raw = isset($_GET['threshold']) ? $_GET['threshold']
               : (isset($_POST['threshold']) ? $_POST['threshold'] : null);
if (!is_string($threshold_raw) || !is_numeric(trim($threshold_raw))
    || $threshold < 0 || $threshold > 1) {
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
 * @param bool   $truncated      OUT: set when a cap stopped the expansion
 *
 * @return array nested node: n=name, w=width, s=self time, c=children
 */
function xhprof_flamegraph_node($name, $width, $edges, $children_table,
                                $total_in, $threshold, $root_w, $depth,
                                &$node_count, $max_depth, $max_nodes,
                                &$truncated) {
  // crafted data can carry negative metric values: a frame width can't be
  // negative, clamp it at zero.
  $width = max(0, $width);
  $node_count++;

  $node = array('n' => $name, 'w' => (int)round($width),
                's' => (int)round($width), 'c' => array());

  if (empty($children_table[$name])) {
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

  // Round frame widths cumulatively: each frame's width is the difference
  // between consecutive rounded boundaries, so rounding can never push the
  // children outside their parent.
  $children = array();
  $drawn_w = 0;   // int width of the frames actually appended so far
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

    // caps: don't expand any further. The frame's width is not drawn but it
    // stays in $boundary, so it comes back out as part of the "(others)"
    // frame below rather than quietly becoming self time of its parent --
    // an unexpanded frame is not self time, and saying so would make the
    // picture lie.
    if ($depth + 1 >= $max_depth || $node_count >= $max_nodes) {
      $truncated = true;
      continue;
    }

    $child_node = xhprof_flamegraph_node($child, $child_w, $edges,
                                         $children_table, $total_in,
                                         $threshold, $root_w, $depth + 1,
                                         $node_count, $max_depth, $max_nodes,
                                         $truncated);
    $children[] = $child_node;
    $drawn_w += $child_w;
  }

  // the "(others)" frame is only known once the loop is done (it holds the
  // threshold-folds plus every frame a cap stopped). $drawn_w, not
  // $rounded, is the sum of the frames actually drawn: a capped frame
  // advanced $rounded without leaving a frame behind.
  $boundary += $others;
  $others_w = (int)round($boundary) - $drawn_w;
  if ($others_w > 0) {
    $children[] = array('n' => '(others)', 'w' => $others_w,
                        's' => $others_w, 'c' => array());
    $drawn_w += $others_w;
  }

  $node['c'] = $children;
  // whatever the (scaled) children do not cover is self time.
  $node['s'] = max(0, $node['w'] - $drawn_w);

  return $node;
}

/**
 * Build the flame graph tree for a whole run.
 *
 * @param array  $raw_data   raw XHProf run data
 * @param string $metric     metric used for the widths
 * @param double $threshold  fold frames below this fraction of the root
 * @param bool   $truncated  OUT: set when a cap stopped the expansion
 *
 * @return array root node (main()), or null when the run has no data
 */
function xhprof_flamegraph_tree($raw_data, $metric, $threshold,
                                &$truncated) {
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
                                $node_count, 512, 20000, $truncated);
}

/**
 * Fold one sample into the tree being built from a sampling-mode run.
 *
 * Every sample carries its whole stack, so it adds 1 to the sample count
 * (w) of each frame it passed through and 1 to the self count (s) of the
 * frame it ended on. No share/apportioning math happens anywhere: a path
 * only comes into existence when a sample really took it.
 *
 * @param array $node       node being built (children keyed by name)
 * @param array $frames     the sample's stack, bottom frame first
 * @param int   $i          next frame to fold in
 * @param int   $depth      depth of $node (recursion guard)
 * @param int   $max_depth  stop expanding below this depth
 * @param int   $node_count OUT: number of nodes created so far
 * @param int   $max_nodes  stop expanding past this node count
 * @param bool  $truncated  OUT: set when a cap stopped the expansion
 *
 * @return void
 */
function xhprof_flamegraph_sample_add(&$node, $frames, $i, $depth, $max_depth,
                                      &$node_count, $max_nodes, &$truncated) {
  $node['w']++;

  if ($i >= count($frames)) {
    // the sample ended on this frame: this is its self count.
    $node['s']++;
    return;
  }

  // caps: the rest of the stack is folded into "(others)". Those samples
  // ran below this frame, so counting them as this frame's self time would
  // make the picture lie.
  if ($depth + 1 >= $max_depth || $node_count >= $max_nodes) {
    $truncated = true;
    if (!isset($node['c']['(others)'])) {
      $node['c']['(others)'] = array('n' => '(others)', 'w' => 0, 's' => 0,
                                     'c' => array());
      $node_count++;
    }
    $node['c']['(others)']['w']++;
    $node['c']['(others)']['s']++;
    return;
  }

  $name = $frames[$i];
  if (!isset($node['c'][$name])) {
    $node['c'][$name] = array('n' => $name, 'w' => 0, 's' => 0,
                              'c' => array());
    $node_count++;
  }
  xhprof_flamegraph_sample_add($node['c'][$name], $frames, $i + 1, $depth + 1,
                               $max_depth, $node_count, $max_nodes, $truncated);
}

/**
 * Fold frames narrower than $min_w into an "(others)" child, bottom-up.
 *
 * Only the children move: a node's own self count is untouched, so nothing
 * leaks into (or out of) self time. An "(others)" frame the caps already
 * created absorbs the extra width instead of duplicating the name.
 *
 * @param array  $node   node whose children are folded in place
 * @param double $min_w  frames narrower than this are folded away
 *
 * @return void
 */
function xhprof_flamegraph_fold_small(&$node, $min_w) {
  $kept = array();
  $others_w = 0;

  foreach ($node['c'] as $name => $child) {
    if ($name !== '(others)' && $child['w'] < $min_w) {
      $others_w += $child['w'];
      continue;
    }
    xhprof_flamegraph_fold_small($child, $min_w);
    $kept[$name] = $child;
  }

  if ($others_w > 0) {
    if (!isset($kept['(others)'])) {
      $kept['(others)'] = array('n' => '(others)', 'w' => 0, 's' => 0,
                                'c' => array());
    }
    $kept['(others)']['w'] += $others_w;
    $kept['(others)']['s'] += $others_w;
  }

  $node['c'] = $kept;
}

/**
 * Turn the name-keyed children map of the builder into the JSON shape:
 * children as a list, in the order they were first seen.
 *
 * @param array $node  node to convert in place
 *
 * @return void
 */
function xhprof_flamegraph_finalize(&$node) {
  $children = array();
  foreach ($node['c'] as $child) {
    xhprof_flamegraph_finalize($child);
    $children[] = $child;
  }
  $node['c'] = $children;
}

/**
 * Build the flame graph tree for a sampling-mode run.
 *
 * Each entry of such a run is one sample of a whole call stack, so frames
 * are counted along the stacks: a frame's width is the exact number of
 * samples that carried it, and a path exists only when a sample took it.
 *
 * @param array  $raw_data   sampled run data: "%d.%06d" => stack string
 * @param double $threshold  fold frames below this fraction of the samples
 * @param bool   $truncated  OUT: set when a cap stopped the expansion
 *
 * @return array root node
 */
function xhprof_flamegraph_sampled_tree($raw_data, $threshold, &$truncated) {
  // the bottom frame of the stacks is the entry point of the run.
  $root_name = 'main()';
  $first = reset($raw_data);
  if (is_string($first)) {
    $frames = explode('==>', $first);
    $root_name = $frames[0];
  }

  $root = array('n' => $root_name, 'w' => 0, 's' => 0, 'c' => array());
  $node_count = 1;

  foreach ($raw_data as $stack) {
    if (!is_string($stack)) {
      continue;
    }
    $frames = explode('==>', $stack);
    // the root frame is the root node itself: consume it when present
    // (a differently rooted stack from crafted data becomes a child).
    if ($frames[0] === $root_name) {
      array_shift($frames);
    }
    xhprof_flamegraph_sample_add($root, $frames, 0, 0,
                                 512, $node_count, 20000, $truncated);
  }

  // the same threshold knob as the approximate view: frames below it are
  // folded into "(others)" (the sample counts they carry stay accounted).
  if ($threshold > 0 && $root['w'] > 0) {
    xhprof_flamegraph_fold_small($root, $threshold * $root['w']);
  }

  xhprof_flamegraph_finalize($root);
  return $root;
}

$xhprof_runs_impl = new XHProfRuns_Default();

// Sampling-mode runs keep their whole profile in string values that
// get_run() would sanitize away, so read the run file raw first and see
// whether it is one. read_run_raw() is the raw reader that sits next to
// get_run(); an implementation without it falls back to the approximate
// path below (which is what its banner says).
$sampled = false;
$raw_data = null;
if ($run !== '') {
  if (method_exists($xhprof_runs_impl, 'read_run_raw')) {
    $raw = $xhprof_runs_impl->read_run_raw($run, $source);
    if (xhprof_is_sampled_run($raw)) {
      $sampled = true;
      $raw_data = $raw;
    }
  }
  if (!$sampled) {
    $raw_data = $xhprof_runs_impl->get_run($run, $source, $description);
  }
}

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

$truncated = false;
if ($sampled) {
  // a sampled run has exactly one metric: the sample count
  $metric = 'samples';
  $metric_desc = 'Samples';
  $tree = xhprof_flamegraph_sampled_tree($raw_data, $threshold, $truncated);
} else {
  // fall back to a metric the run actually carries (wall time is not
  // collected by the C++ profiler, for instance).
  $metrics = xhprof_get_metrics($raw_data);
  if (!in_array($metric, $metrics, true)) {
    $metric = count($metrics) ? $metrics[0] : '';
  }

  $possible_metrics = xhprof_get_possible_metrics();
  $metric_desc = isset($possible_metrics[$metric][2]) ?
                 $possible_metrics[$metric][2] : $metric;

  $tree = xhprof_flamegraph_tree($raw_data, $metric, $threshold, $truncated);
}

// a tree cut short by the depth/node caps says so in the JSON as well as
// on the page (the renderer ignores the extra key).
if ($truncated) {
  $tree['truncated'] = true;
}

// json_encode() refuses to nest deeper than its $depth argument (default
// 512) and spends about two per array level, so a tree that reaches the
// builder's own 512 frame cap needs a good deal more than 512 here.
$tree_json = json_encode($tree, JSON_INVALID_UTF8_SUBSTITUTE, 2048);

$base_url_params = array('run' => $run, 'source' => $source);
if ($metric !== 'wt') {
  $base_url_params['metric'] = $metric;
}

echo "<html><head><title>XHProf flame graph ("
  . ($sampled ? 'sampled' : 'approximate') . ")</title>";
echo '<meta charset="utf-8">';
echo "<style>\n"
  . "body { font-family: Helvetica, Arial, sans-serif; margin: 8px; }\n"
  . ".approx_banner { background: #fff3cd; border: 1px solid #e0c060; "
  . "padding: 6px 10px; margin-bottom: 8px; }\n"
  . ".exact_banner { background: #e6f4ea; border-color: #a3cfa9; }\n"
  . ".truncated_banner { background: #fdecea; border: 1px solid #e0a0a0; "
  . "padding: 6px 10px; margin-bottom: 8px; }\n"
  . "#breadcrumb { margin: 6px 0px; font-size: 13px; }\n"
  . "#breadcrumb span, #breadcrumb a { cursor: pointer; color: #3b5998; }\n"
  . "#flamegraph { overflow-x: hidden; }\n"
  . "#flamegraph text { pointer-events: none; font-size: 11px; "
  . "font-family: Helvetica, Arial, sans-serif; }\n"
  . "@media (prefers-color-scheme: dark) {\n"
  . "  body { background: #1b1b1b; color: #ddd; }\n"
  . "  a { color: #8ab4f8; }\n"
  . "  .approx_banner { background: #3a3320; border-color: #6b5c2a; "
  . "color: #eee; }\n"
  . "  .exact_banner { background: #1e3323; border-color: #3c6b46; }\n"
  . "  .truncated_banner { background: #3a2320; border-color: #6b3c36; }\n"
  . "  #breadcrumb span, #breadcrumb a { color: #8ab4f8; }\n"
  . "  /* frame fills stay the light pastels the renderer assigns, so the\n"
  . "     labels stay dark; only the separators follow the page. */\n"
  . "  #flamegraph rect { stroke: #1b1b1b; }\n"
  . "  #flamegraph text { fill: #333; }\n"
  . "}\n"
  . "</style>";
echo "</head><body>";

if ($sampled) {
  echo '<div class="approx_banner exact_banner"><b>Sampled flame graph '
    . '(exact).</b> This run was collected in sampling mode: every recorded '
    . 'entry is one whole call stack, so a frame&rsquo;s width is the exact '
    . 'number of samples that carried it, and every path shown is a path a '
    . 'sample really took.</div>';
} else {
  echo '<div class="approx_banner"><b>Approximate flame graph.</b> '
    . 'XHProf only stores aggregated caller&rarr;callee data (one row per pair), '
    . 'so each function&rsquo;s ' . htmlentities($metric_desc)
    . ' is apportioned over its outgoing calls by share. Frame widths are '
    . 'estimates, not exact call frames.</div>';
}

if ($truncated) {
  echo '<div class="truncated_banner"><b>Partial graph:</b> this run is too '
    . 'deep or too wide to draw in full. Frames past the limit (depth 512, '
    . '20000 nodes) are folded into &ldquo;(others)&rdquo; instead of being '
    . 'shown.</div>';
}

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
