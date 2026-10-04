<?php
/* Companion include for xhprof_028.phpt: the call site of acc_inc_fn must
 * point into THIS file, not into the main test script.
 *
 * The static counter keeps the body side-effectful: OPcache's optimizer
 * (PHP 8.4/8.5) deletes calls to pure return-constant functions whose
 * result is unused, which would make the call unprofileable whenever the
 * suite runs with opcache enabled. */
function acc_inc_fn() { static $n = 0; return ++$n; }
acc_inc_fn();
