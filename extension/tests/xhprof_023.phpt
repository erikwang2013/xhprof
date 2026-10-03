--TEST--
XHProf: functions called before xhprof_enable() are still profiled
--FILE--
<?php

include_once dirname(__FILE__).'/common.php';

function warm($x) {
  return $x + 1;
}

/* Pre-warm the function: the fcall observer handlers are installed and
 * cached the first time a function runs. A handler that is only returned for
 * not-yet-profiled functions would leave this one out of the profile (the
 * "sticky function" bug). */
for ($i = 0; $i < 100; $i++) {
  warm($i);
}

xhprof_enable();
warm(1);
$output = xhprof_disable();
print_canonical($output);

?>
--EXPECT--
main()                                  : ct=       1; wt=*;
main()==>warm                           : ct=       1; wt=*;
main()==>xhprof_disable                 : ct=       1; wt=*;
