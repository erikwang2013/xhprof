--TEST--
XHProf: repeated xhprof_enable() calls are idempotent
--FILE--
<?php

include_once dirname(__FILE__).'/common.php';

function foo() {
  return 1;
}

xhprof_enable();

/* Both of these must be no-ops: no extra "main()" frame, no counters reset
 * and, in particular, the flags of a later call are ignored */
xhprof_enable();
xhprof_enable(XHPROF_FLAGS_CPU);

foo();
$output = xhprof_disable();
print_canonical($output);

?>
--EXPECT--
main()                                  : ct=       1; wt=*;
main()==>foo                            : ct=       1; wt=*;
main()==>xhprof_disable                 : ct=       1; wt=*;
main()==>xhprof_enable                  : ct=       2; wt=*;
