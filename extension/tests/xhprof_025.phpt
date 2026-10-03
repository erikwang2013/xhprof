--TEST--
XHProf: xhprof.auto_enable profiles without calling xhprof_enable()
--INI--
xhprof.auto_enable = 1
--FILE--
<?php

include_once dirname(__FILE__).'/common.php';

/* There is no xhprof_enable() call anywhere in this script */
function foo() {
  return 1;
}

foo();
$output = xhprof_disable();

/* Profiling starts at request startup, so the compilation of the script
 * itself (and of common.php) is profiled as well; drop those entries to keep
 * the expectation independent of the test paths */
foreach (array_keys($output) as $key) {
  if (strpos($key, 'load::') !== false) {
    unset($output[$key]);
  }
}

print_canonical($output);

?>
--EXPECT--
main()                                  : ct=       1; wt=*;
main()==>dirname                        : ct=       1; wt=*;
main()==>foo                            : ct=       1; wt=*;
main()==>xhprof_disable                 : ct=       1; wt=*;
