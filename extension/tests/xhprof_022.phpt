--TEST--
XHProf: xhprof.profiler=0 refuses to profile and warns
--INI--
xhprof.profiler = 0
--FILE--
<?php

/* With the profiler disabled no instrumentation is installed at all; both
 * enable functions must fail loudly instead of returning a profile that only
 * contains the fictitious "main()" frame */
set_error_handler(function ($errno, $errstr) {
  echo "warning: {$errstr}\n";
  return true;
});

var_dump(xhprof_enable());
var_dump(xhprof_sample_enable());

/* Nothing was started, so there is nothing to stop either */
var_dump(xhprof_disable());
var_dump(xhprof_sample_disable());

?>
--EXPECT--
warning: xhprof_enable(): profiling is disabled (xhprof.profiler=0)
bool(false)
warning: xhprof_sample_enable(): profiling is disabled (xhprof.profiler=0)
bool(false)
NULL
NULL
