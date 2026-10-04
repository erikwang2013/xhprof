--TEST--
XHProf: xhprof.collect_files records each function's definition file (__files__)
--INI--
xhprof.collect_files = 1
--FILE--
<?php

/* the helpers below are declared in this file: their map entries must point
 * back into it */
class FilesC {
  public function m() {
    return 42;
  }
}

function files_fn($x) {
  return $x + 1;
}

function files_recur($depth) {
  if ($depth > 0) {
    files_recur($depth - 1);
  }
}

$closure = function ($x) {
  return $x * 2;
};

xhprof_enable();
files_fn(1);
files_recur(2);
(new FilesC())->m();
$closure(3);
strlen("abc");            /* internal function: must not be in the map */
$output = xhprof_disable();

$files = (isset($output['__files__']) && is_array($output['__files__']))
         ? $output['__files__'] : array();
echo "map present: ", $files ? "yes" : "no", "\n";

$here = __FILE__;

/* functions and methods map to "definition file:line" */
foreach (array('files_fn', 'files_recur', 'FilesC::m') as $fn) {
  $ok = isset($files[$fn])
        && preg_match('/^' . preg_quote($here, '/') . ':\d+$/', $files[$fn]);
  echo $fn, ' => ', $ok ? "ok\n" : "WRONG\n";
}

/* recursion shares one entry: keys are base names, without @n */
$recur_keys = 0;
foreach (array_keys($files) as $key) {
  if (strncmp($key, 'files_recur', 11) === 0) {
    $recur_keys++;
  }
}
echo "recursion entries: ", $recur_keys, "\n";

/* closures are plain user functions and get a map entry too (their name
 * format depends on the PHP version, so match any "{closure..." key) */
$closure_entries = 0;
foreach ($files as $key => $value) {
  if (strncmp($key, '{closure', 8) === 0
      && preg_match('/^' . preg_quote($here, '/') . ':\d+$/', $value)) {
    $closure_entries++;
  }
}
echo "closure entries: ", $closure_entries, "\n";

/* internals have no definition file to record */
echo "strlen in map: ", isset($files['strlen']) ? "yes" : "no", "\n";

/* collection is opt-in: with the INI back at 0 the key is absent */
ini_set('xhprof.collect_files', '0');
xhprof_enable();
files_fn(1);
$output = xhprof_disable();
echo "map without collect_files: ",
     array_key_exists('__files__', $output) ? "yes" : "no", "\n";

?>
--EXPECT--
map present: yes
files_fn => ok
files_recur => ok
FilesC::m => ok
recursion entries: 1
closure entries: 1
strlen in map: no
map without collect_files: no
