<?php

function bar($x) {
  if ($x > 0) {
    bar($x - 1);
  }
}

function foo() {
  for ($idx = 0; $idx < 5; $idx++) {
    bar($idx);
    $x = strlen("abc");
  }
}

// start profiling
xhprof_enable();

// run program
foo();

// stop profiler
$xhprof_data = xhprof_disable();

// display raw xhprof data for the profiler run
print_r($xhprof_data);


$XHPROF_ROOT = realpath(dirname(__FILE__) .'/..');
include_once $XHPROF_ROOT . "/xhprof_lib/utils/xhprof_lib.php";
include_once $XHPROF_ROOT . "/xhprof_lib/utils/xhprof_runs.php";

// save raw data for this profiler run using default
// implementation of iXHProfRuns. XHProfRuns_Default writes the run file into
// the xhprof.output_dir ini setting (set it in php.ini; see the README).
$xhprof_runs = new XHProfRuns_Default();

// save the run under a namespace "xhprof_foo"
$run_id = $xhprof_runs->save_run($xhprof_data, "xhprof_foo");

// Point a browser at the UI to view the run. Quickest route is the Docker
// quick start in the README (`docker compose up`, then open
// http://localhost:8080): the container seeds this script and serves the UI.
// Running from a source checkout instead, serve the xhprof_html/ directory
// with any PHP web server and open index.php?run=<id>&source=<source>.
echo "---------------\n".
     "Saved run $run_id under source \"xhprof_foo\".\n".
     "View it in the XHProf UI (index.php?run=$run_id&source=xhprof_foo)\n".
     "-- Docker quick start: docker compose up, then http://localhost:8080\n".
     "-- source checkout: serve xhprof_html/ and set xhprof.output_dir\n".
     "---------------\n";
