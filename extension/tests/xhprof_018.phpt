--TEST--
XHProf: Sampling with xhprof.sampling_interval=0 must not divide by zero
--INI--
xhprof.sampling_interval = 0
--SKIPIF--
<?php
if (substr(PHP_OS, 0, 3) == 'WIN') {
    print 'skip';
}
?>
--FILE--
<?php

include_once dirname(__FILE__).'/common.php';

/* A zero interval used to overflow the truncated sample time in
 * hp_trunc_time() (SIGFPE) and to spin forever in hp_sample_check() */
function foo() {
  usleep(50000);
}

xhprof_sample_enable();
foo();
$output = xhprof_sample_disable();

echo (is_array($output) && count($output) > 0) ? "sampled ok\n" : "no samples\n";

?>
--EXPECT--
sampled ok
