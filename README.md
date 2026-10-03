# xhprof for PHP7 and PHP8
**[English](README.md) | [简体中文](README.zh-CN.md)**
[![CI](https://github.com/longxinH/xhprof/actions/workflows/ci.yml/badge.svg)](https://github.com/longxinH/xhprof/actions/workflows/ci.yml) [![Build status](https://ci.appveyor.com/api/projects/status/dornfeel5yutaxte/branch/master?svg=true)](https://ci.appveyor.com/project/longxinH/xhprof/branch/master)

<img src="resource/xhpy-blink.svg" alt="Xhpy — the xhprof mascot" width="140" align="right">

XHProf is a function-level hierarchical profiler for PHP and has a simple HTML based navigational interface. The raw data collection component is implemented in C (as a PHP extension). The reporting/UI layer is all in PHP. It is capable of reporting function-level inclusive and exclusive wall times, memory usage, CPU times and number of calls for each function. Additionally, it supports ability to compare two runs (hierarchical DIFF reports), or aggregate results from multiple runs.

This version supports PHP7 and PHP8

# Why xhprof
- Published on PECL, with the reporting UI, hierarchical DIFF reports and aggregation tooling kept in-tree.
- The original Facebook project and the Tideways `php-xhprof-extension` fork are archived and both point users here; the PHP manual links to this fork as well.
- Runs entirely on your own machine — profiles are written to your `xhprof.output_dir` and never leave it — and it records exact call counts plus deterministic wall/CPU/memory numbers, with diff and aggregate reports that sampling profilers cannot produce.

# PHP Version
- 7.2
- 7.4
- 8.0
- 8.1
- 8.2
- 8.3
- 8.4
- 8.5
- 8.6 (pre-release; builds and passes the full test suite against 8.6.0RC2)

# Installation

## Quick start with Docker
No PHP toolchain required — one command builds the extension and serves the UI:
```sh
docker compose up
```
Then open <http://localhost:8080>. The container seeds one example run (`examples/sample.php`) on startup and stores runs in `/tmp/xhprof`.

## Install via PECL
```sh
pecl install xhprof
```

## Build from source
```
git clone https://github.com/longxinH/xhprof.git ./xhprof
cd xhprof/extension/
/path/to/php7/bin/phpize
./configure --with-php-config=/path/to/php7/bin/php-config
make && sudo make install
```

#### configuration add to your php.ini
```
[xhprof]
extension = xhprof.so
xhprof.output_dir = /tmp/xhprof
```

### php.ini configuration
|      Options        |  Defaults  |  Version  |  Explain  |
| --------------- |:-------------:|:-------------:|:---------|
|xhprof.output_dir  | "" | All |Output directory|
|xhprof.sampling_interval  | 100000 | >= v2.* | Sampling interval to be used by the sampling profiler, in microseconds|
|xhprof.sampling_depth  | INT_MAX | >= v2.* | Depth to trace call-chain by the sampling profiler|
|xhprof.collect_additional_info  | 0 | >= v2.1 | Collect mysql_query, curl_exec internal info. The default is 0. Open value is 1|
|xhprof.profiler  | 1 | >= v2.3.12 | System (php.ini / `-d` only). Set to 0 to load the extension without registering any observer/proxy: idle overhead drops back to non-extension levels, but `xhprof_enable()` / `xhprof_sample_enable()` then return false with an `E_WARNING`|
|xhprof.auto_enable  | 0 | >= v2.3.12 | System. Start hierarchical profiling at request start without calling `xhprof_enable()` (requires `xhprof.profiler=1`; silently inert when it is 0)|
|xhprof.auto_enable_flags  | 0 | >= v2.3.12 | System. Flags used by `xhprof.auto_enable`, e.g. `XHPROF_FLAGS_CPU \| XHPROF_FLAGS_MEMORY`|

# Turn on extra collection
#### php.ini adds xhprof.collect_additional_info
```sh
xhprof.collect_additional_info = 1
````
# Options
```php
xhprof_enable(XHPROF_FLAGS_NO_BUILTINS | XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY);
```
- `XHPROF_FLAGS_NO_BUILTINS` do not profile builtins
- `XHPROF_FLAGS_CPU` gather CPU times for funcs
- `XHPROF_FLAGS_MEMORY` gather memory usage for funcs

Example
```php
<?php

// start profiling
xhprof_enable(XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY);

// ... the code you want to profile ...

// stop profiling and fetch the raw data
$xhprof_data = xhprof_disable();

// $xhprof_data is keyed by "caller==>callee", for example:
// array(
//     "main()" => array(
//         "wt" => 237,
//         "ct" => 1,
//         "cpu" => 100,
//     )
// )
print_r($xhprof_data);
```

- `wt` The execution time of the function method is time consuming
- `ct` The number of times the function was called
- `cpu` The CPU time consumed by the function method execution
- `mu` Memory used by function methods. The call is zend_memory_usage to get the memory usage
- `pmu` Peak memory used by the function method. The call is zend_memory_peak_usage to get the memory

### PDO::exec
### PDO::query
### mysqli_query
```php
$mysqli = new mysqli("localhost", "my_user", "my_password", "user");
$result = $mysqli->query("SELECT * FROM user LIMIT 10");
```
##### Output data
```
mysqli::query#SELECT * FROM user LIMIT 10
```

### PDO::prepare
Convert preprocessing placeholders for actual parameters, more intuitive analytic performance (does not change the zend execution process)
```php
$_sth = $db->prepare("SELECT * FROM user where userid = :id and username = :name");
$_sth->execute([':id' => '1', ':name' => 'admin']);
$data1 = $_sth->fetch();

$_sth = $db->prepare("SELECT * FROM user where userid = ?");
$_sth->execute([1]);
$data2 = $_sth->fetch();
```
##### Output data
```
PDOStatement::execute#SELECT * FROM user where userid = 1 and username = admin
PDOStatement::execute#SELECT * FROM user where userid = 1
```

### Curl
```php
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "http://www.baidu.com");
$output = curl_exec($ch);
curl_close($ch);
```
##### Output data
```
curl_exec#http://www.baidu.com
```

# Data export and visualization

Besides the HTML report, a run can leave the browser:

- **Flame graph** — `xhprof_html/flamegraph.php` renders a flame graph for a run. This is an **approximate view**: xhprof stores aggregated `caller==>callee` edges, not individual call frames, so each function's inclusive metric is apportioned over its outgoing calls by each edge's share, and the remainder becomes its self time. Frame widths are sound; the split below an aggregated edge is an estimate. Frames narrower than `?threshold=<0..1>` of the run (default 0.01) are folded into an `(others)` frame.
- **Callgrind** — export a run in callgrind format and open it in [KCachegrind](https://apps.kde.org/kcachegrind/) or QCachegrind for source/callee-level analysis.
- **JSON / CSV** — machine-readable exports of the flat report, for scripts, dashboards or your own diffing.

Every export is linked from the report page (**Export**: Flame Graph (approximate) | JSON | CSV | callgrind); direct URLs look like `report.php?format=json`, `report.php?format=csv` and `report.php?format=callgrind`.

# XHGui recipe

[XHGui](https://github.com/perftools/xhgui) keeps xhprof runs in MongoDB and adds a long-term, aggregating UI. The glue is maintained by the perftools project, not in this repository — xhprof only has to supply the extension:

```sh
pecl install xhprof                        # this extension
composer require perftools/php-profiler perftools/xhgui-collector
```

```php
<?php
// config/config.php — minimal perftools/php-profiler setup.
// php-profiler auto-detects the loaded profiler extension; see its README
// (https://github.com/perftools/php-profiler) for the full option list.
return [
    'profiler.enable'      => function () { return true; },
    'profiler.flags'       => [XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY],
    'save.handler'         => 'mongodb',
    'save.handler.mongodb' => [
        'dsn'      => 'mongodb://127.0.0.1:27017',
        'database' => 'xhprof',
    ],
];
```

# CLI reports and diff gate

For scripting and CI there is no need to run a web server:

```sh
bin/xhprof-report --source=xhprof_foo <run_id>          # flat report on the terminal
bin/xhprof-diff --threshold=5% <baseline> <candidate>   # exits non-zero past the threshold
```

Two runs of identical code on a busy machine can still differ by tens of percent, so compare runs taken under the same conditions and pick a threshold above the run-to-run variance you observe.

# Notes
- Loading the xhprof extension in php.ini adds roughly 2x function-call overhead, even if profiling is never enabled (`xhprof_enable()` is never called). It is not recommended to load it permanently on production systems that do not profile.
- If it does have to stay loaded but never profiles, `xhprof.profiler=0` makes the extension register no observer at all: idle overhead drops back to roughly the no-extension level, and `xhprof_enable()` / `xhprof_sample_enable()` return false with a warning rather than silently producing nothing. Do not set it on hosts that profile at runtime.
- Analyzing large run reports requires `memory_limit >= 512M`.

## PECL Repository
[![pecl](resource/pecl.png)](https://pecl.php.net/package/xhprof)
