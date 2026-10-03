--TEST--
XHProf: PDO::exec() with a non-string argument must not crash
--INI--
xhprof.collect_additional_info = 1
--SKIPIF--
<?php if (!extension_loaded("pdo_sqlite")) print 'skip'; ?>
--FILE--
<?php

include_once dirname(__FILE__).'/common.php';

/* The SQL trace callback used to read the query argument as a string
 * without checking its type */
$pdo = new PDO('sqlite::memory:');

xhprof_enable();
$pdo->exec('CREATE TABLE t (a)');
try {
  $pdo->exec(123);
} catch (PDOException $e) {
  echo "pdo error ok\n";
}
$output = xhprof_disable();
print_canonical($output);

?>
--EXPECT--
pdo error ok
main()                                  : ct=       1; wt=*;
main()==>PDO::exec                      : ct=       1; wt=*;
main()==>PDO::exec#CREATE TABLE t (a)   : ct=       1; wt=*;
main()==>xhprof_disable                 : ct=       1; wt=*;
