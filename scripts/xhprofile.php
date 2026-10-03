#!/usr/bin/env php
<?php

// Profile a CLI script, save the run and print where to look at it.

if ($argc < 2) {
  throw new Exception('usage: xhprofile <script>');
}

require_once dirname(dirname(__FILE__)) . '/xhprof_lib/display/xhprof.php';

$__xhprof_target__ = $argv[1];

$argv = array_slice($argv, 1);
$argc = count($argv);

// the run namespace the web UI will look the run up under
$__xhprof_source__ = getenv('XHPROF_PROFILE_SOURCE') ?: 'xhprof';

xhprof_enable();
require_once $__xhprof_target__;
$xhprof_data = xhprof_disable();

$__xhprof_runs__ = new XHProfRuns_Default();
$__xhprof_run_id__ = $__xhprof_runs__->save_run($xhprof_data,
                                                $__xhprof_source__);

if ($__xhprof_run_id__ === null) {
  fwrite(STDERR, "xhprofile: could not save the run\n"
         . "           (set XHPROF_OUTPUT_DIR or the xhprof.output_dir "
         . "ini setting)\n");
  exit(1);
}

$__xhprof_base_url__ = getenv('XHPROF_HTML_URL') ?: 'http://localhost/xhprof_html';
$__xhprof_base_url__ = rtrim($__xhprof_base_url__, '/');
$__xhprof_query__ = 'run=' . urlencode($__xhprof_run_id__)
                    . '&source=' . urlencode($__xhprof_source__);

printf("Saved run %s (%d entries)\n", $__xhprof_run_id__,
       count($xhprof_data));
printf("Report:      %s/index.php?%s\n", $__xhprof_base_url__,
       $__xhprof_query__);
printf("Flame graph: %s/flamegraph.php?%s\n", $__xhprof_base_url__,
       $__xhprof_query__);
printf("Export:      %s/report.php?%s&format=json|csv|callgrind\n",
       $__xhprof_base_url__, $__xhprof_query__);
