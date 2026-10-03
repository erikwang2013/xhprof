--TEST--
XHProf: Invalid options argument to xhprof_enable() must not crash
--FILE--
<?php

/* A non-array second argument used to be dereferenced as an array and
 * segfaulted in hp_get_ignored_functions_from_arg().
 * PHP >= 8 rejects it with a TypeError; PHP 7 emits a warning and
 * continues.  Either way, the point of this test is that the process
 * survives - so assert exactly that, portably. */
foreach (array('x', 42, true) as $bad) {
    try {
        @xhprof_enable(0, $bad);
    } catch (Throwable $e) {
        /* PHP >= 8: TypeError - expected, and handled. */
    }
    echo "survived\n";
}

/* null stays a valid "no options" argument */
xhprof_enable(0, null);
echo "null ok: ", is_array(xhprof_disable()) ? "yes" : "no", "\n";

?>
--EXPECT--
survived
survived
survived
null ok: yes
