--TEST--
XHProf: metric batch acceptance - everything on at once, cross-feature invariants
--INI--
xhprof.collect_files = 1
xhprof.collect_callsites = 1
xhprof.collect_timeline = 1
--FILE--
<?php

/* One run with CPU_SPLIT and all three collectors at once; the maps and
 * metrics must coexist and stay internally consistent. */

function acc_fn($x) { return $x + 1; }
class AccC { public function m() { return 1; } }
function acc_recur($d) {
  if ($d > 0) {
    acc_recur($d - 1);
  }
}

xhprof_enable(XHPROF_FLAGS_CPU_SPLIT);

require dirname(__FILE__) . '/xhprof_029_inc.php';

$c = new AccC();
acc_fn(1);
$c->m();
acc_recur(2);
getmypid();

$data = xhprof_disable();

/* 1) the three maps coexist alongside the split metrics */
$have = array();
foreach (array('__files__', '__callsites__', '__timeline__') as $k) {
  $have[] = array_key_exists($k, $data) ? 'y' : 'n';
}
echo "maps: ", implode(',', $have), "\n";

/* 2) split metrics replace the merged cpu; ut+st never exceeds the wall
 * time of the same entry beyond timer skew */
$main = $data['main()'];
$split_ok = isset($main['ut']) && isset($main['st'])
            && isset($main['minflt']) && isset($main['majflt'])
            && !isset($main['cpu']);
echo "split: ", $split_ok ? 'ok' : 'bad', "\n";

$inv_ok = true;
foreach ($data as $sym => $info) {
  if (strpos($sym, '__') === 0 || !is_array($info) || !isset($info['wt'])) {
    continue;
  }
  if (isset($info['ut'])
      && ($info['ut'] + $info['st']) > $info['wt'] * 1.1 + 2000) {
    $inv_ok = false;
  }
}
echo "ut+st<=wt: ", $inv_ok ? 'ok' : 'bad', "\n";
echo "minflt>0: ", $main['minflt'] > 0 ? 'yes' : 'no', "\n";

/* 3) call sites: the call made inside the include points into the include
 * file; an internal callee called from userland gets a site too */
$cs = $data['__callsites__'];
echo "site include: ",
     (isset($cs['main()==>acc_inc_fn'])
      && strpos($cs['main()==>acc_inc_fn'], 'xhprof_029_inc.php:') !== false)
     ? 'ok' : 'bad', "\n";
echo "site internal: ",
     isset($cs['main()==>getmypid']) ? 'ok' : 'bad', "\n";

/* 4) timeline: ints, first <= last, nothing beyond the run's wall time,
 * recursion shares one base-name key */
$tl = $data['__timeline__'];
$max_last = 0;
$tl_ok = true;
foreach ($tl as $tt) {
  if (!is_array($tt) || count($tt) !== 2 || !is_int($tt[0]) || !is_int($tt[1])
      || $tt[0] > $tt[1] || $tt[0] < 0) {
    $tl_ok = false;
    continue;
  }
  if ($tt[1] > $max_last) { $max_last = $tt[1]; }
}
if ($max_last > $main['wt'] + 5000) { $tl_ok = false; }
echo "timeline: ", $tl_ok ? 'ok' : 'bad', "\n";

$recur_keys = 0;
foreach (array_keys($tl) as $k) {
  if (strncmp($k, 'acc_recur', 9) === 0) { $recur_keys++; }
}
echo "timeline recursion keys: ", $recur_keys, "\n";

?>
--EXPECT--
maps: y,y,y
split: ok
ut+st<=wt: ok
minflt>0: yes
site include: ok
site internal: ok
timeline: ok
timeline recursion keys: 1
