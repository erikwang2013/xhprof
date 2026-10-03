--TEST--
XHProf: Invalid options argument to xhprof_enable() must not crash
--FILE--
<?php

/* A non-array second argument used to be dereferenced as an array and
 * segfaulted in hp_get_ignored_functions_from_arg() */
foreach (array('x', 42, true) as $bad) {
    try {
        xhprof_enable(0, $bad);
        echo "no error\n";
    } catch (TypeError $e) {
        echo "TypeError\n";
    }
}

/* null stays a valid "no options" argument */
xhprof_enable(0, null);
echo "null ok: ", is_array(xhprof_disable()) ? "yes" : "no", "\n";

?>
--EXPECT--
TypeError
TypeError
TypeError
null ok: yes
