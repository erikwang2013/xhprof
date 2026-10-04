--TEST--
XHProf: memory-triggered sampling (xhprof.sampling_memory_interval)
--INI--
xhprof.sampling_interval = 1000000
xhprof.sampling_memory_interval = 1048576
--FILE--
<?php

function grow(&$keep, $bytes = 1048576) {
  $keep[] = str_repeat('x', $bytes);
}
function nested_alloc(&$keep) {
  $keep[] = str_repeat('x', 1048576);
  boundary(); /* a call boundary inside the function, after the growth */
}
function boundary() { return 1; }

/* one ~1MB step that stays allocated: usage crosses one bucket per call,
 * and the sample_disable() call itself is the final call boundary */
$keep = array();
xhprof_sample_enable();
for ($i = 0; $i < 10; $i++) {
  grow($keep);
}
$out = xhprof_sample_disable();
echo "samples for 10 steps: ", count($out), "\n";

$shaped = 0;
$stacked = 0;
foreach ($out as $key => $stack) {
  if (preg_match('/^\d+\.\d{6}$/', (string)$key)) {
    $shaped++;
  }
  if (is_string($stack) && strlen($stack) > 0
      && strpos($stack, 'main()') === 0) {
    $stacked++;
  }
}
echo "timestamp-shaped keys: ", $shaped, "\n";
echo "main()-rooted stacks: ", $stacked, "\n";
unset($keep);

/* one big jump records once (not once per bucket); a step below the
 * interval adds nothing; the next crossing records again */
$keep = array();
xhprof_sample_enable();
grow($keep, 3 * 1048576);
boundary();
$n1 = count(xhprof_sample_disable());
unset($keep);

$keep = array();
xhprof_sample_enable();
grow($keep, 3 * 1048576);
boundary();
grow($keep, 100 * 1024);
boundary();
$n2 = count(xhprof_sample_disable());
unset($keep);

$keep = array();
xhprof_sample_enable();
grow($keep, 3 * 1048576);
boundary();
grow($keep, 100 * 1024);
boundary();
grow($keep, 1048576);
boundary();
$n3 = count(xhprof_sample_disable());
unset($keep);
echo "jump/small/jump: ", $n1, "/", $n2, "/", $n3, "\n";

/* the sample fires at the next call boundary after the growth: inside
 * nested_alloc() the recorded stack is "main()==>nested_alloc" */
$keep = array();
xhprof_sample_enable();
nested_alloc($keep);
$out = xhprof_sample_disable();
$stacks = array_values($out);
echo "nested stack: ",
     (count($stacks) === 1 && $stacks[0] === 'main()==>nested_alloc')
     ? "ok" : "wrong", "\n";
unset($keep);

/* off by default: no samples; flipping the interval back on at runtime
 * starts sampling again (no latched threshold) */
ini_set('xhprof.sampling_memory_interval', '0');
$keep = array();
xhprof_sample_enable();
for ($i = 0; $i < 5; $i++) {
  grow($keep);
}
echo "samples with interval 0: ", count(xhprof_sample_disable()), "\n";
unset($keep);

ini_set('xhprof.sampling_memory_interval', '1048576');
$keep = array();
xhprof_sample_enable();
for ($i = 0; $i < 5; $i++) {
  grow($keep);
}
echo "samples after flipping back on: ", count(xhprof_sample_disable()), "\n";

?>
--EXPECT--
samples for 10 steps: 10
timestamp-shaped keys: 10
main()-rooted stacks: 10
jump/small/jump: 1/1/2
nested stack: ok
samples with interval 0: 0
samples after flipping back on: 5
