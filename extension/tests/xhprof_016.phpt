--TEST--
XHProf: Recursion levels with ignored functions
--FILE--
<?php

include_once dirname(__FILE__).'/common.php';

/* The dummy entry pushed for an ignored function must not decrement the
 * recursion counter of its parent, otherwise the "@<level>" suffixes
 * collapse into a single stack entry */
function rec($n) {
  str_repeat("x", 10);
  if ($n > 0) {
    rec($n - 1);
  }
}

xhprof_enable(0, array('ignored_functions' => array('str_repeat')));
rec(2);
$output = xhprof_disable();
print_canonical($output);

?>
--EXPECT--
main()                                  : ct=       1; wt=*;
main()==>rec                            : ct=       1; wt=*;
main()==>xhprof_disable                 : ct=       1; wt=*;
rec==>rec@1                             : ct=       1; wt=*;
rec@1==>rec@2                           : ct=       1; wt=*;
