<?php
// Reporting layer tests: sample-run folding, degenerate run data, typeahead
// matching, callgrind events, run list parsing, raw run reads.
//
// Plain asserts, no framework:  php tests/php/report_layer_test.php
// exit status: 0 = all checks passed, 1 = at least one failed.

error_reporting(E_ALL);

$root = dirname(dirname(__DIR__));
$GLOBALS['XHPROF_LIB_ROOT'] = $root . '/xhprof_lib';
require_once $GLOBALS['XHPROF_LIB_ROOT'] . '/display/xhprof.php';
require_once $GLOBALS['XHPROF_LIB_ROOT'] . '/utils/xhprof_callgrind.php';

$checks = 0; $failures = 0; $php_notices = array();

// collect every notice/warning/deprecation instead of printing it; flows that
// must stay clean are checked with no_notices() below.
set_error_handler(function ($no, $str, $file, $line) use (&$php_notices) {
  $php_notices[] = $str . " in " . basename($file) . ":$line";
  return true;
});

function ok($cond, $msg) {
  global $checks, $failures;
  $checks++;
  if (!$cond) {
    $failures++;
    echo "FAIL: $msg\n";
  }
}

function same($expected, $actual, $msg) {
  global $checks, $failures;
  $checks++;
  if ($expected !== $actual) {
    $failures++;
    echo "FAIL: $msg\n  expected: " . var_export($expected, true)
       . "\n  actual:   " . var_export($actual, true) . "\n";
  }
}

function no_notices($label) {
  global $php_notices;
  same(array(), $php_notices, "no PHP notices/warnings during $label");
  $php_notices = array();
}

// --- fixtures ---

// run ids are hex only: the report endpoints filter the "run" parameter
// through ctype_xdigit before loading anything.
$dir = sys_get_temp_dir() . '/xhprof_report_layer_test_' . getmypid();
@mkdir($dir, 0777, true);
$runs = new XHProfRuns_Default($dir);

// instrumented run: call counts + wall time + cpu + memory
$hier = array(
  'main()'         => array('ct' => 3, 'wt' => 3000, 'cpu' => 1000, 'mu' => 100),
  'main()==>alpha' => array('ct' => 2, 'wt' => 2000, 'cpu' => 700, 'mu' => 60),
  'main()==>beta'  => array('ct' => 1, 'wt' => 1000, 'cpu' => 300, 'mu' => 40),
  'alpha==>beta'   => array('ct' => 2, 'wt' => 500, 'cpu' => 100, 'mu' => 10),
);
$runs->save_run($hier, 'xhprof', 'ae0001');

// sampled run: one entry per sample, timestamp => stack. The lone "main()"
// sample is the double counting trap: it must not be counted twice.
$sampled = array(
  '1700000000.000001' => 'main()',
  '1700000000.000002' => 'main()==>alpha',
  '1700000000.000003' => 'main()==>alpha==>beta',
  '1700000000.000004' => 'main()==>alpha==>beta',
  '1700000000.000005' => 'main()==>beta',
);
$runs->save_run($sampled, 'xhprof', '5a3d0001');

// wall time only (no cpu / mu flags on the profiler)
$runs->save_run(array(
  'main()'   => array('ct' => 1, 'wt' => 100),
  'main()==>a' => array('ct' => 1, 'wt' => 90),
), 'xhprof', 'f0c0001');

// a run whose entries carry no "ct" at all (C++ profiler shape)
$runs->save_run(array(
  'main()' => array('wt' => 1000),
  'main()==>zz' => array('wt' => 900),
  'yy==>zz' => array('wt' => 100),
), 'xhprof', '0c70001');

// crafted runs: self loop, empty
$runs->save_run(array(
  'main()' => array('ct' => 1, 'wt' => 1000),
  'foo==>foo' => array('ct' => 1, 'wt' => 500),
), 'xhprof', '5e1f1001');
$runs->save_run(array(), 'xhprof', 'e00001');

// run ids / types containing dots, and a plain legacy one
$plain = array('main()' => array('ct' => 1, 'wt' => 100));
$runs->save_run($plain, 'xhprof', 'my.run.1');
$runs->save_run($plain, 'test', 'my.run.2');
$runs->save_run($plain, 'xhprof', 'legacyid');

// a run carrying the extension's function => "file:line" map (__files__,
// collected with xhprof.collect_files=1)
$files_map = array('alpha' => '/app/alpha.php:3',
                   'beta' => '/app/lib/beta.php:9');
$runs->save_run($hier + array('__files__' => $files_map), 'xhprof', 'f11e0001');

// --- sample run folding ---

same(array('', 'main()'), xhprof_parse_parent_child('main()'),
     'a bare key parses with an empty (not null) parent');
same(array('a', 'b'), xhprof_parse_parent_child('a==>b'),
     'a parent/child key parses into its two names');

$folded = xhprof_expand_sampled_run($sampled);
same(array('samples' => 5), $folded['main()'],
     'folded main() holds the total sample count exactly once');
same(array('samples' => 3), $folded['main()==>alpha'], 'edge samples fold');
same(array('samples' => 2), $folded['alpha==>beta'], 'nested edge samples fold');
same(array('samples' => 1), $folded['main()==>beta'], 'shallow edge samples fold');
same(4, count($folded), 'only main() plus one entry per edge is produced');
foreach ($folded as $pc => $info) {
  ok(!isset($info['ct']), "folded entry $pc has no ct (sampled runs have none)");
}
same($hier, xhprof_expand_sampled_run($hier),
     'instrumented data is left untouched by the folder');
same(array(), xhprof_expand_sampled_run(array()),
     'an empty run stays empty');
no_notices('folding');

// the folder output must survive sanitizing (and get_run returns it that way)
$sanitized = xhprof_sanitize_run_data($folded);
same($folded, $sanitized, 'folded data passes sanitize unchanged');
$converted = $runs->get_run('5a3d0001', 'xhprof', $desc);
same($folded, $converted, 'get_run returns the folded sampled run');
foreach ($converted as $pc => $info) {
  ok(is_array($info), "converted entry $pc is an array");
  foreach ($info as $metric => $value) {
    ok(is_numeric($value), "converted $pc/$metric is numeric");
  }
}

// flat view of the folded run: inclusive / exclusive samples per function
$GLOBALS['display_calls'] = false;
init_metrics($converted, '', '', false);
same(false, $display_calls, 'sampled runs use the display_calls=false path');
same(array('samples'), $metrics, 'sampled runs report the samples metric');
$totals = array();
$flat = xhprof_compute_flat_info($converted, $totals);
// stacks: main(); main()>alpha; main()>alpha>beta x2; main()>beta
// inclusive = samples the frame appears in anywhere, exclusive = samples
// where the frame is the deepest one.
same(5, $flat['main()']['samples'], 'main() inclusive samples');
same(3, $flat['alpha']['samples'], 'alpha inclusive samples');
same(1, $flat['alpha']['excl_samples'], 'alpha exclusive samples');
same(3, $flat['beta']['samples'], 'beta inclusive samples');
same(3, $flat['beta']['excl_samples'], 'beta exclusive samples');
same(5, $totals['samples'], 'total samples');
same(0, $totals['ct'], 'sampled run total call count stays 0');
no_notices('flat view of a sampled run');

// samples-only runs must produce a real callgraph, not an empty digraph
same('samples', xhprof_callgraph_metric($converted),
     'the callgraph metric falls back to samples');
same('wt', xhprof_callgraph_metric($hier),
     'instrumented runs keep wall time');
same('mu', xhprof_callgraph_metric($hier, 'mu'),
     'a requested metric is honored when present');
same('samples', xhprof_callgraph_metric($converted, 'wt'),
     'a requested metric the run lacks falls back');
$dot = xhprof_generate_dot_script($converted, 0.01, 'xhprof', null, null, false);
ok(strpos($dot, 'digraph call_graph') === 0, 'dot script starts with the graph');
ok(strpos($dot, "digraph call_graph {\ngraph [bgcolor=\"transparent\"];") === 0,
   'the graph asks for a transparent background (dark mode)');
ok(substr_count($dot, ' -> ') >= 3, 'sampled callgraph has edges');
ok(strpos($dot, 'samples (') !== false, 'sample labels carry their unit');
ok(strpos($dot, 'total calls') === false,
   'no fabricated call counts for a run without ct');
no_notices('sampled callgraph');

// --- function file map (__files__): collected by xhprof.collect_files ---

$with_files = $runs->get_run('f11e0001', 'xhprof', $desc);
ok(!array_key_exists('__files__', $with_files),
   'get_run strips the function file map');
same($runs->get_run('ae0001', 'xhprof', $desc), $with_files,
     'a file map does not change how the run itself reads back');
same($files_map, $runs->get_run_files('f11e0001', 'xhprof'),
     'get_run_files returns the map');
same(null, $runs->get_run_files('ae0001', 'xhprof'),
     'runs without a file map return null');
same(null, $runs->get_run_files('ffff0001', 'xhprof'),
     'a missing run has no file map');
same($hier, xhprof_sanitize_run_data($hier + array('__files__' => $files_map)),
     'sanitize drops the file map too (bin/ CLIs read run files directly)');
no_notices('reading a run with a file map');

// display helper: map lookup, recursion suffixes, name-embedded sources
same('/app/alpha.php:3', xhprof_symbol_file($files_map, 'alpha'), 'map hit');
same('/app/alpha.php:3', xhprof_symbol_file($files_map, 'alpha@2'),
     'a recursion variant resolves to its base entry');
same('', xhprof_symbol_file($files_map, 'strlen'), 'unknown symbol has no file');
same('', xhprof_symbol_file($files_map, 'main()'), 'main() has no file');
same('', xhprof_symbol_file(null, 'alpha'), 'no map at all');
same('/tmp/x.php:12', xhprof_symbol_file(array(), '{closure:/tmp/x.php:12}'),
     'closures name their file themselves (PHP >= 8.4)');
same('tmp/inc.php', xhprof_symbol_file(array(), 'load::tmp/inc.php'),
     'include entries name their file');
same('', xhprof_symbol_file(array(), '{closure}'),
     'a bare {closure} (PHP < 8.4) has no file without the map');

// --- degenerate run data ---

// self loop: the guard reports an empty inclusive table instead of null
$selfloop = $runs->get_run('5e1f1001', 'xhprof', $desc);
same(array(), xhprof_compute_inclusive_times($selfloop),
     'a self loop yields an empty inclusive table, not null');
$totals = array();
$flat = xhprof_compute_flat_info($selfloop, $totals);
same(array(), $flat, 'flat info of a self loop run is empty');
same(0, $totals['wt'], 'self loop totals degrade to 0');
no_notices('self loop run');

// empty run
$empty = $runs->get_run('e00001', 'xhprof', $desc);
same(array(), $empty, 'an empty run reads back as an empty array');
same(array(), xhprof_compute_inclusive_times($empty), 'empty run inclusive table');
$totals = array();
same(array(), xhprof_compute_flat_info($empty, $totals), 'empty run flat info');
no_notices('empty run');

// entries without a "ct" key (C++ profiler shape): reported as 0, not warned
$GLOBALS['display_calls'] = true;
$noct = $runs->get_run('0c70001', 'xhprof', $desc);
$incl = xhprof_compute_inclusive_times($noct);
same(0, $incl['main()']['ct'], 'a missing ct is reported as 0');
same(0, $incl['zz']['ct'], 'a missing ct on a merged child is 0');
same(1000, $incl['zz']['wt'], 'metrics still add up without ct');
no_notices('missing ct run');

// ---------------------------------------------------------------------------
// mixed metric sets: a run aggregated or diffed against one that carries
// more metrics (wt only vs wt/cpu/mu) must not warn; the metrics the run
// does not have count as 0.
// ---------------------------------------------------------------------------

$GLOBALS['display_calls'] = true;
$agg = xhprof_aggregate_runs($runs, array('ae0001', 'f0c0001'), array(), 'xhprof');
same(array(), $agg['bad_runs'], 'both runs take part in the aggregation');
same(3000 + 100, $agg['raw']['main()']['wt'], 'wait time adds up');
same(1000, $agg['raw']['main()']['cpu'], 'a metric only one run has counts 0');
same(100, $agg['raw']['main()']['mu'], 'same for memory usage');
no_notices('aggregating runs with different metric sets');

$dl = xhprof_compute_diff($runs->get_run('f0c0001', 'xhprof', $desc),
                          $runs->get_run('ae0001', 'xhprof', $desc));
same(1000, $dl['main()']['cpu'], 'a metric missing from the baseline diffs as 0');
same(3000 - 100, $dl['main()']['wt'], 'wait time still diffs normally');
no_notices('diffing runs with different metric sets');

// the diff callgraph of an instrumented run against a sampled one: the two
// sides do not even share a metric, the graph still has to render.
$old_flat = array();
$sampled_flat = array();
xhprof_compute_flat_info($runs->get_run('ae0001', 'xhprof', $desc), $old_flat);
xhprof_compute_flat_info($converted, $sampled_flat);
$mixed_delta = xhprof_compute_diff($runs->get_run('ae0001', 'xhprof', $desc), $converted);
$dot = xhprof_generate_dot_script($mixed_delta, 0.01, 'xhprof', null, null,
                                  false, $old_flat, $sampled_flat);
ok(strpos($dot, 'digraph call_graph') === 0, 'the diff callgraph renders');
no_notices('diff callgraph of an instrumented and a sampled run');

// --- bin/xhprof-diff: --relative judges a function's share of the run total ---

function diff_bin($root, $args) {
  $cmd = escapeshellarg(PHP_BINARY) . ' '
       . escapeshellarg($root . '/bin/xhprof-diff');
  foreach ($args as $arg) { $cmd .= ' ' . escapeshellarg($arg); }
  $out = array();
  $rc = 0;
  exec($cmd . ' 2>&1', $out, $rc);
  return array($rc, implode("\n", $out));
}

if (function_exists('exec')) {
  // every function 20% slower: the machine was busy, the code did not change
  $slow_old = array('main()' => array('ct' => 1, 'wt' => 1000),
                    'main()==>a' => array('ct' => 1, 'wt' => 500),
                    'main()==>b' => array('ct' => 1, 'wt' => 100));
  $slow_new = array('main()' => array('ct' => 1, 'wt' => 1200),
                    'main()==>a' => array('ct' => 1, 'wt' => 600),
                    'main()==>b' => array('ct' => 1, 'wt' => 120));
  // "a" really got slower: its share of the run grew
  $regressed = array('main()' => array('ct' => 1, 'wt' => 1200),
                     'main()==>a' => array('ct' => 1, 'wt' => 700),
                     'main()==>b' => array('ct' => 1, 'wt' => 120));
  file_put_contents($dir . '/diff_old.raw', serialize($slow_old));
  file_put_contents($dir . '/diff_new.raw', serialize($slow_new));
  file_put_contents($dir . '/diff_reg.raw', serialize($regressed));

  list($rc, $out) = diff_bin($root, array($dir . '/diff_old.raw', $dir . '/diff_new.raw'));
  same(1, $rc, 'absolute mode fails a machine-wide slowdown');
  ok(strpos($out, 'REGRESSION') !== false, 'absolute mode reports it');
  ok(strpos($out, '+20.0%') !== false, 'absolute mode measures raw growth');

  list($rc, $out) = diff_bin($root, array('--relative', $dir . '/diff_old.raw',
                                          $dir . '/diff_new.raw'));
  same(0, $rc, 'relative mode passes the same pair');
  ok(strpos($out, 'mode: relative') !== false, 'relative mode says so');
  ok(strpos($out, 'no function grew by more than 5% of its baseline share') !== false,
     'relative mode reports against the share');

  list($rc, $out) = diff_bin($root, array('--relative', $dir . '/diff_old.raw',
                                          $dir . '/diff_reg.raw'));
  same(1, $rc, 'relative mode still fails a function that grew its share');
  ok(strpos($out, 'a: 500 -> 700 (+16.7%, 200)') !== false, 'share growth reported');

  list($rc, $out) = diff_bin($root, array('--help'));
  same(0, $rc, '--help exits 0');
  ok(strpos($out, '--relative') !== false, '--help documents --relative');
} else {
  echo "SKIP: exec() unavailable, xhprof-diff checks not run\n";
}

// --- callgraph.php: the threshold parameter has to be a number to take effect ---

function callgraph_svg($root, $dir, $run, $extra_query = '') {
  $query = 'run=' . $run . '&source=xhprof&type=svg' . $extra_query;
  $code = 'putenv("XHPROF_OUTPUT_DIR=" . ' . var_export($dir, true) . ');'
        . 'parse_str(' . var_export($query, true) . ', $_GET);'
        . '$_SERVER["SCRIPT_NAME"] = "callgraph.php";'
        . 'require ' . var_export($root . '/xhprof_html/callgraph.php', true) . ';';
  return shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code));
}

// functions on both sides of the 1% default (0.5% / 20% / 79%), so every
// threshold below changes which of them survive
$runs->save_run(array(
  'main()' => array('ct' => 1, 'wt' => 10000),
  'main()==>big' => array('ct' => 1, 'wt' => 7900),
  'main()==>mid' => array('ct' => 1, 'wt' => 2000),
  'main()==>tiny' => array('ct' => 1, 'wt' => 50),
), 'xhprof', '0dd0001');

if (function_exists('shell_exec')) {
  $svg_default = callgraph_svg($root, $dir, '0dd0001');
  if (strpos($svg_default, '<svg') === false) {
    echo "SKIP: graphviz dot not available, callgraph.php checks not run\n";
  } else {
    same($svg_default, callgraph_svg($root, $dir, '0dd0001', '&threshold=abc'),
         'a non numeric threshold falls back to the default');
    same($svg_default, callgraph_svg($root, $dir, '0dd0001', '&threshold='),
         'an empty threshold falls back to the default');
    same($svg_default, callgraph_svg($root, $dir, '0dd0001', '&threshold=2'),
         'an out of range threshold falls back to the default');
    ok(callgraph_svg($root, $dir, '0dd0001', '&threshold=0') !== $svg_default,
       '"0" is a valid threshold: fold nothing');
    ok(callgraph_svg($root, $dir, '0dd0001', '&threshold=0.9') !== $svg_default,
       'a high threshold folds the small functions away');
  }
} else {
  echo "SKIP: shell_exec() unavailable, callgraph.php checks not run\n";
}

// --- callgrind events follow the run's actual metrics ---

$cg = xhprof_callgrind_report($hier, 'hier');
ok(strpos($cg, "\nevents: Time Cpu MemUse\n") !== false, 'wt+cpu+mu keeps 3 events');
preg_match_all('/^0(?: [0-9]+)*$/m', $cg, $m);
ok(count($m[0]) >= 4, 'callgrind emits one cost line per function');
foreach ($m[0] as $line) {
  same(3, count(explode(' ', $line)) - 1, "cost line columns match events: $line");
}

$cg = xhprof_callgrind_report($runs->get_run('f0c0001', 'xhprof', $desc), 'wt');
ok(strpos($cg, "\nevents: Time\n") !== false, 'wt-only run has no Cpu/MemUse column');
preg_match_all('/^0(?: [0-9]+)*$/m', $cg, $m);
same(1, count(explode(' ', $m[0][0])) - 1, 'wt-only cost lines have one column');

$cg = xhprof_callgrind_report($converted, 'sampled');
ok(strpos($cg, "\nevents: Samples\n") !== false, 'sampled run reports Samples');
ok(strpos($cg, "fn=beta\n0 3\n") !== false, 'self cost is the exclusive samples');
ok(strpos($cg, "cfn=beta\ncalls=1 0\n0 2\n") !== false, 'call cost is the edge samples');
no_notices('callgrind');

// --- read_run_raw: the saved shape, before folding and sanitizing ---

$raw = $runs->read_run_raw('5a3d0001', 'xhprof');
same($sampled, $raw, 'read_run_raw returns the sampled run unconverted');
$runs->save_run(array('main()' => array('ct' => '1', 'wt' => 'oops')),
                'xhprof', 'd17177');
same('oops', $runs->read_run_raw('d17177', 'xhprof')['main()']['wt'],
     'read_run_raw does not sanitize');
same(0, $runs->get_run('d17177', 'xhprof', $desc)['main()']['wt'],
     'get_run sanitizes the same value to 0');
same(null, $runs->read_run_raw('missing404', 'xhprof'),
     'read_run_raw returns null for a missing run');
no_notices('read_run_raw');

// --- typeahead matching: the prefilter must not change the answers ---

function typeahead_reference($q, $xhprof_data, $limit = 50) {
  // the pre-prefilter implementation, kept here as the reference.
  $prefix_matches = array();
  $infix_matches = array();
  foreach ($xhprof_data as $parent_child => $info) {
    list($parent, $child) = xhprof_parse_parent_child($parent_child);
    foreach (array($parent, $child) as $name) {
      if ($name === null || $name === '') {
        continue;
      }
      if (stripos($name, $q) === 0) {
        $prefix_matches[$name] = 1;
      } else if (stripos($name, $q) !== false) {
        $infix_matches[$name] = 1;
      }
    }
  }
  $exact = array();
  if (isset($prefix_matches[$q])) {
    $exact = array($q);
    unset($prefix_matches[$q]);
  }
  $prefix = array_keys($prefix_matches);
  $infix = array_keys($infix_matches);
  sort($prefix);
  sort($infix);
  $res = array_merge($exact, $prefix, $infix);
  return ($limit > 0) ? array_slice($res, 0, $limit) : $res;
}

foreach (array('alpha', 'a', 'A', 'beta', 'zzz', '', 'n()==>a', '==>',
               'beta==>', 'main()') as $q) {
  same(typeahead_reference($q, $hier, 50),
       xhprof_get_matching_functions($q, $hier, 50),
       "typeahead answer for " . var_export($q, true));
}
foreach (array('alpha', 'beta', 'a', 'main()', 'eta==>') as $q) {
  same(typeahead_reference($q, $converted, 50),
       xhprof_get_matching_functions($q, $converted, 50),
       "typeahead answer on the folded run for " . var_export($q, true));
}
no_notices('typeahead matching');

// --- run listing: dotted run ids, and the aggregate button ---

ob_start();
$runs->list_runs();
$html = ob_get_clean();
no_notices('list_runs');

preg_match_all('/value="([^"]*)" data-source="([^"]*)"/', $html, $m, PREG_SET_ORDER);
$listed = array();
foreach ($m as $x) {
  $listed[] = array(html_entity_decode($x[1]), html_entity_decode($x[2]));
}
ok(in_array(array('my.run.1', 'xhprof'), $listed, true),
   'dotted run id keeps its type: my.run.1.xhprof.xhprof');
ok(in_array(array('my.run.2', 'test'), $listed, true),
   'dotted run id with a dotted type: my.run.2.test.xhprof');
ok(in_array(array('legacyid', 'xhprof'), $listed, true),
   'plain run file names behave exactly as before');
ok(in_array(array('ae0001', 'xhprof'), $listed, true),
   'ordinary run ids are listed');

ok(strpos($html, 'Aggregate selected') !== false,
   'list_runs offers the aggregate button');
ok(strpos($html, 'function xhprofAggregateSelectedRuns()') !== false,
   'aggregate button has its handler');
ok(strpos($html, 'function xhprofCompareSelectedRuns()') !== false,
   'the compare button is still there');
ok(strpos($html, "runs.join(',')") !== false,
   'aggregate collects the checked runs in DOM order');
ok(strpos($html, "picked[0].getAttribute('data-source')") !== false,
   'aggregate takes the source from the first checked run');

// --- report.php JSON / CSV shapes (subprocess: the script reads $_GET and exits) ---

function report_php($root, $dir, $run, $format) {
  $code = 'putenv("XHPROF_OUTPUT_DIR=" . ' . var_export($dir, true) . ');'
        . '$_GET = array("run" => ' . var_export($run, true)
        . ', "source" => "xhprof", "format" => ' . var_export($format, true) . ');'
        . '$_SERVER["SCRIPT_NAME"] = "report.php";'
        . 'require ' . var_export($root . '/xhprof_html/report.php', true) . ';';
  return shell_exec(escapeshellarg(PHP_BINARY)
                    . ' -d error_reporting=0 -d display_errors=0 -r '
                    . escapeshellarg($code));
}

if (function_exists('shell_exec')) {
  $json = json_decode(report_php($root, $dir, '5a3d0001', 'json'), true);
  same('5a3d0001', $json['run'], 'json run id');
  same(array('samples'), $json['metrics'], 'json metrics');
  same(5, $json['totals']['samples'], 'json total samples');
  $by_fn = array();
  foreach ($json['functions'] as $row) {
    $by_fn[$row['fn']] = $row;
  }
  same(5, $by_fn['main()']['samples'], 'json main() samples');
  same(3, $by_fn['beta']['samples'], 'json beta inclusive samples');
  same(3, $by_fn['beta']['excl_samples'], 'json beta exclusive samples');
  same(0, $json['totals']['ct'], 'json ct total stays 0 for a sampled run');

  $json = json_decode(report_php($root, $dir, 'ae0001', 'json'), true);
  same(3000, $json['totals']['wt'], 'json instrumented wait time total');
  same(8, $json['totals']['ct'], 'json call count total sums every entry');
  same(array('wt', 'cpu', 'mu'), $json['metrics'],
       'json instrumented metrics');
  same(null, $json['files'], 'json files is null for a run without a map');

  $json = json_decode(report_php($root, $dir, 'f11e0001', 'json'), true);
  same($files_map, $json['files'], 'json export carries the function file map');

  $lines = preg_split('/\r?\n/', trim(report_php($root, $dir, '5a3d0001', 'csv')));
  // the download may start with a UTF-8 BOM (spreadsheets want it)
  $lines[0] = preg_replace('/^\xEF\xBB\xBF/', '', $lines[0]);
  same('fn,samples,excl_samples', $lines[0], 'csv header for a sampled run');
  ok(in_array('beta,3,3', $lines, true), 'csv row for beta');
  same('TOTAL,5,', $lines[count($lines) - 1], 'csv total row');

  $cg = report_php($root, $dir, 'f0c0001', 'callgrind');
  ok(strpos($cg, "\nevents: Time\n") !== false,
     'callgrind download follows the run metrics');
} else {
  echo "SKIP: shell_exec() unavailable, report.php checks not run\n";
}

// --- cleanup + summary ---

foreach (array_merge(glob($dir . '/*.xhprof'), glob($dir . '/*.raw')) as $file) {
  unlink($file);
}
rmdir($dir);

echo "$checks checks, $failures failure(s)\n";
exit($failures ? 1 : 0);
