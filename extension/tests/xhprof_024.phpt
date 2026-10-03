--TEST--
XHProf: distinct long function names must not be merged by truncation
--INI--
xhprof.sampling_interval = 100
--FILE--
<?php

/* Two names sharing their first 600 bytes: with a fixed size symbol buffer
 * they were truncated to the same key and their profile entries merged */
$prefix = str_repeat('a', 600);
$long1 = $prefix . 'x';
$long2 = $prefix . 'y';
eval("function {$long1}() { return 1; } function {$long2}() { return 2; }");

xhprof_enable();
$long1();
$long2();
$output = xhprof_disable();

echo isset($output["main()==>{$long1}"]) ? "long1 ok\n" : "long1 MISSING\n";
echo isset($output["main()==>{$long2}"]) ? "long2 ok\n" : "long2 MISSING\n";
echo $output["main()==>{$long1}"]["ct"] === 1 ? "no merge\n" : "MERGED\n";

/* Symbols larger than the stack buffer use the heap path */
$huge_prefix = str_repeat('z', 3000);
$huge1 = $huge_prefix . 'A';
$huge2 = $huge_prefix . 'B';
eval("function {$huge1}() { return 1; } function {$huge2}() { return 2; }");

xhprof_enable();
$huge1();
$huge2();
$output = xhprof_disable();

echo isset($output["main()==>{$huge1}"]) ? "huge1 ok\n" : "huge1 MISSING\n";
echo isset($output["main()==>{$huge2}"]) ? "huge2 ok\n" : "huge2 MISSING\n";

/* The sampling profiler builds its symbols in a per request buffer */
$sampled = str_repeat('s', 600) . 'S';
eval("function {$sampled}() { \$t = 0; for (\$i = 0; \$i < 100000; \$i++) { \$t += \$i; } return \$t; }");

xhprof_sample_enable();
$sampled();
$samples = xhprof_sample_disable();

$found = 0;
foreach ($samples as $symbol) {
  if (strpos($symbol, $sampled) !== false) {
    $found++;
  }
}

echo $found > 0 ? "sampled long name ok\n" : "sampled long name MISSING\n";

?>
--EXPECT--
long1 ok
long2 ok
no merge
huge1 ok
huge2 ok
sampled long name ok
