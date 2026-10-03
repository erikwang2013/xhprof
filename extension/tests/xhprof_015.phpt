--TEST--
XHProf: Ignoring the root symbol "main()" as a string must not crash
--FILE--
<?php

include_once dirname(__FILE__).'/common.php';

/* "main()" is the fictitious root of the call tree and must never be
 * ignored; passing it as a plain string used to crash the profiler */
xhprof_enable(0, array('ignored_functions' => 'main()'));

function foo() {
  return 1;
}
foo();

$output = xhprof_disable();
print_canonical($output);

?>
--EXPECT--
main()                                  : ct=       1; wt=*;
main()==>foo                            : ct=       1; wt=*;
main()==>xhprof_disable                 : ct=       1; wt=*;
