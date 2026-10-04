--TEST--
XHProf: metric batch - CPU split (ut/st/minflt/majflt), timeline, callsites, file/Redis trace callbacks
--FILE--
<?php

function cpu_burn($n) {
  $x = 0;
  for ($i = 0; $i < $n; $i++) {
    $x += $i % 7;
  }
  return $x;
}

function timeline_fn($depth) {
  if ($depth > 0) {
    timeline_fn($depth - 1);
  }
}

function site_fn()  { return 2; }
class SiteC {
  public function m() { return 1; }
}

class Redis {
  public function get($key)     { return false; }
  public function set($key, $v) { return true; }
}

/* --- XHPROF_FLAGS_CPU_SPLIT: ut/st/minflt/majflt, no merged cpu --- */

xhprof_enable(XHPROF_FLAGS_CPU_SPLIT);
cpu_burn(1000);
$out = xhprof_disable();

$main = $out['main()'];
ksort($main);
echo "split main metrics: ", implode(',', array_keys($main)), "\n";
echo "burn has ut/st/minflt/majflt: ",
     (isset($out['main()==>cpu_burn']['ut'])
      && isset($out['main()==>cpu_burn']['st'])
      && isset($out['main()==>cpu_burn']['minflt'])
      && isset($out['main()==>cpu_burn']['majflt'])) ? "yes" : "no", "\n";

/* the plain CPU flag is unchanged: merged cpu, no ut/st */
xhprof_enable(XHPROF_FLAGS_CPU);
cpu_burn(1000);
$out = xhprof_disable();
$main = $out['main()'];
ksort($main);
echo "plain cpu main metrics: ", implode(',', array_keys($main)), "\n";

/* --- timeline: opt-in, first <= last, recursion shares one key --- */

ini_set('xhprof.collect_timeline', '0');
xhprof_enable();
timeline_fn(2);
$out = xhprof_disable();
echo "timeline off: ",
     array_key_exists('__timeline__', $out) ? "yes" : "no", "\n";

ini_set('xhprof.collect_timeline', '1');
xhprof_enable();
timeline_fn(2);
$out = xhprof_disable();
$tl = $out['__timeline__'];

$recursions = 0;
foreach (array_keys($tl) as $key) {
  if (strncmp($key, 'timeline_fn', 11) === 0) {
    $recursions++;
  }
}
$t = isset($tl['timeline_fn']) ? $tl['timeline_fn'] : null;
echo "timeline recursion keys: ", $recursions, "\n";
echo "timeline ints, first <= last: ",
     (is_array($t) && is_int($t[0]) && is_int($t[1]) && $t[0] <= $t[1])
     ? "yes" : "no", "\n";
echo "timeline has main(): ",
     isset($tl['main()']) ? "yes" : "no", "\n";
ini_set('xhprof.collect_timeline', '0');

/* --- callsites: opt-in, "file:line" of the first call per pair --- */

ini_set('xhprof.collect_callsites', '0');
xhprof_enable();
site_fn();
$out = xhprof_disable();
echo "callsites off: ",
     array_key_exists('__callsites__', $out) ? "yes" : "no", "\n";

ini_set('xhprof.collect_callsites', '1');
xhprof_enable();
$site_c = new SiteC();
$l1 = __LINE__ + 1;
site_fn();
$l2 = __LINE__ + 1;
$site_c->m();
$cl = function () { return 3; };
$l3 = __LINE__ + 1;
$cl();
$out = xhprof_disable();
$cs = $out['__callsites__'];

echo "callsite function: ",
     (isset($cs['main()==>site_fn'])
      && $cs['main()==>site_fn'] === __FILE__ . ':' . $l1)
     ? "ok" : "mismatch", "\n";
echo "callsite method: ",
     (isset($cs['main()==>SiteC::m'])
      && $cs['main()==>SiteC::m'] === __FILE__ . ':' . $l2)
     ? "ok" : "mismatch", "\n";

$closure_site = null;
foreach ($cs as $pair => $site) {
  if (strncmp($pair, 'main()==>{closure', 17) === 0) {
    $closure_site = $site;
  }
}
echo "callsite closure: ",
     ($closure_site === __FILE__ . ':' . $l3) ? "ok" : "mismatch", "\n";

/* a callback invoked by an internal function has no user call site */
xhprof_enable();
array_map('site_fn', array(1));
$out = xhprof_disable();
$cs = isset($out['__callsites__']) ? $out['__callsites__'] : array();
echo "internal callback pair has no site: ",
     isset($cs['array_map==>site_fn']) ? "no" : "yes", "\n";
ini_set('xhprof.collect_callsites', '0');

/* --- trace callbacks for file/cache calls (collect_additional_info) --- */

ini_set('xhprof.collect_additional_info', '0');
xhprof_enable();
@file_get_contents('/nonexistent-xhprof-027');
(new Redis())->get('plain-key');
$out = xhprof_disable();
echo "trace off file: ",
     (isset($out['main()==>file_get_contents'])
      && !isset($out['main()==>file_get_contents#/nonexistent-xhprof-027']))
     ? "clean" : "dirty", "\n";

ini_set('xhprof.collect_additional_info', '1');
xhprof_enable();
@file_get_contents('/nonexistent-xhprof-027');
(new Redis())->get('plain-key');
(new Redis())->set('plain-key', 'v');
$out = xhprof_disable();
echo "file trace: ",
     isset($out['main()==>file_get_contents#/nonexistent-xhprof-027'])
     ? "yes" : "no", "\n";
echo "redis get trace: ",
     isset($out['main()==>Redis::get#plain-key']) ? "yes" : "no", "\n";
echo "redis set trace: ",
     isset($out['main()==>Redis::set#plain-key']) ? "yes" : "no", "\n";
ini_set('xhprof.collect_additional_info', '0');

?>
--EXPECT--
split main metrics: ct,majflt,minflt,st,ut,wt
burn has ut/st/minflt/majflt: yes
plain cpu main metrics: cpu,ct,wt
timeline off: no
timeline recursion keys: 1
timeline ints, first <= last: yes
timeline has main(): yes
callsites off: no
callsite function: ok
callsite method: ok
callsite closure: ok
internal callback pair has no site: yes
trace off file: clean
file trace: yes
redis get trace: yes
redis set trace: yes
