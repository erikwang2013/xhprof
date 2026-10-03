--TEST--
XHProf: collect_additional_info=0 must not append information to symbols
--INI--
xhprof.collect_additional_info = 0
--SKIPIF--
<?php if (!extension_loaded("pdo_sqlite")) print 'skip'; ?>
--FILE--
<?php

include_once dirname(__FILE__).'/common.php';

/* With additional info collection disabled the SQL trace callbacks are not
 * registered, so the symbol must stay plain "PDO::exec" (no "#<query>") */
$pdo = new PDO('sqlite::memory:');

xhprof_enable();
$pdo->exec('CREATE TABLE t (a)');
$output = xhprof_disable();
print_canonical($output);

?>
--EXPECT--
main()                                  : ct=       1; wt=*;
main()==>PDO::exec                      : ct=       1; wt=*;
main()==>xhprof_disable                 : ct=       1; wt=*;
