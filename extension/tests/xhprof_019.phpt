--TEST--
XHProf: xhprof_disable() returns NULL when profiling was never started
--FILE--
<?php

/* Both disable functions return the stats array while profiling, but NULL
 * when there is nothing to return (never enabled, or already disabled) */
var_dump(xhprof_disable());
var_dump(xhprof_sample_disable());

xhprof_enable();
$output = xhprof_disable();
echo is_array($output) ? "array\n" : "not an array\n";
var_dump(xhprof_disable());

?>
--EXPECT--
NULL
NULL
array
NULL
