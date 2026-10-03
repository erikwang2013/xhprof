--TEST--
XHProf: Test Curl Additional Info
Author: longxinhui
--SKIPIF--
<?php if (!extension_loaded("curl")) print 'skip'; ?>
--INI--
xhprof.collect_additional_info = 1
--FILE--
<?php

include_once dirname(__FILE__).'/common.php';

xhprof_enable();
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://www.php.net/");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_exec($ch);
@curl_close($ch); /* deprecated since PHP 8.5, the "@" keeps the notice out of the output */
$output = xhprof_disable();
/* PHP 8.5+ constructs a Deprecated object for the notice suppressed above;
 * it is not part of what this test checks (the curl_exec trace name) */
unset($output['main()==>Deprecated::__construct']);
print_canonical($output);
echo "\n";

?>
--EXPECTF--
main()                                  : ct=       1; wt=*;
main()==>curl_close                     : ct=       1; wt=*;
main()==>curl_exec#https://www.php.net/ : ct=       1; wt=*;
main()==>curl_getinfo                   : ct=       1; wt=*;
main()==>curl_init                      : ct=       1; wt=*;
main()==>curl_setopt                    : ct=       2; wt=*;
main()==>xhprof_disable                 : ct=       1; wt=*;
