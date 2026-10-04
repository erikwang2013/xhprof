# xhprof：PHP7 与 PHP8 的分层分析器
**[English](README.md) | [简体中文](README.zh-CN.md)**
[![CI](https://github.com/longxinH/xhprof/actions/workflows/ci.yml/badge.svg)](https://github.com/longxinH/xhprof/actions/workflows/ci.yml) [![Build status](https://ci.appveyor.com/api/projects/status/dornfeel5yutaxte/branch/master?svg=true)](https://ci.appveyor.com/project/longxinH/xhprof/branch/master)

<img src="resource/xhpy-blink.svg" alt="Xhpy — xhprof 项目宠物" width="140" align="right">

XHProf 是 PHP 的函数级分层分析器：原始数据采集组件用 C 实现（作为 PHP 扩展），报表/UI 层则完全使用 PHP 实现。它可以报告每个函数的 inclusive 与 exclusive wall time、内存占用、CPU 时间和调用次数，并支持对比两次运行（分层 DIFF 报表）或聚合多次运行的结果。

围绕这个内核，本仓库提供完整工具链：带运行列表与一键对比的 Web UI（flat / parent-child / DIFF / 聚合报表）、调用图与火焰图视图（分层 run 为**近似**、采样 run 为精确），以及时间线视图；callgrind、JSON、CSV 与 folded 栈导出；CLI 工具（`bin/xhprofile`、`bin/xhprof-report`，以及可挂进 CI 的回归门禁 `bin/xhprof-diff`）；`docker compose up` 一键演示；以及 `xhprof.profiler=0` 门控——扩展保持加载，但空闲开销回落到未加载扩展的水平。支持 PHP 7.2–8.6。

# 为什么选择 xhprof
- **数据不出本机。** 剖析结果只写入你自己的 `xhprof.output_dir` 并留在那里——无服务、无上传、无遥测。
- **精确，而非采样。** 每次调用都被计数，wall/CPU/内存数值由扩展钩子实测——这正是 DIFF 与聚合报表可信的前提。
- **延续的血统。** 已发布在 PECL：原 Facebook 项目与 Tideways 的 `php-xhprof-extension` 分支均已归档并指向本仓库，PHP 手册也链接到本分支。

# 架构设计 / Architecture

<p align="center"><img src="resource/xhprof-architecture.svg" alt="XHProf 架构设计：PHP 运行时、C 扩展、数据契约与存储、报表层四层结构" width="900"></p>

自顶向下四层。用户代码运行在 Zend Engine 上，`xhprof.so` 在函数调用处挂载观察者钩子；扩展按 caller/callee 配对（递归调用记为 `foo@n`），累加 `wt`、`ct`、`cpu`、`mu`、`pmu`（配 `XHPROF_FLAGS_CPU_SPLIT` 则为 `ut`/`st`/`minflt`/`majflt`），也可以按间隔采样而不是逐次追踪。`xhprof_disable()` 把扁平的 `"caller==>callee"` 数组交回 PHP，`save_run()` 将其序列化到 `xhprof.output_dir`，报表层再从这份数据重建调用层次。设置 `xhprof.profiler=0` 时，上述钩子一个都不会注册。

# 项目设计 / Design

<p align="center"><img src="resource/xhprof-design.svg" alt="XHProf 项目设计：extension 生产数据，xhprof_lib 计算，xhprof_html 渲染，bin 与 scripts 复用库" width="900"></p>

谁生产数据、谁消费数据。`extension/`（C）生产扁平调用数组；`xhprof_lib/` 负责读取与计算——`utils/` 管 run 读写、调用图数据与 callgrind 导出，`display/` 是渲染层，负责 flat、父子与 DIFF 视图；`xhprof_html/` 是 Web 入口，`bin/` 与 `scripts/` 则在命令行复用同一套库。`extension/tests`、`package.xml` 与 `docker/` 作为测试与分发面旁挂在外围。

# 项目功能 / Features

<p align="center"><img src="resource/xhprof-features.svg" alt="XHProf 功能矩阵：剖析、采样、五项指标、对比、DIFF、聚合、调用图、火焰图、导出、CLI、Docker、XHGui" width="900"></p>

一格一项能力：精确分层剖析与采样模式、五项指标、运行列表与一键对比、分层 DIFF、聚合、调用图、火焰图（分层 run 近似、采样 run 精确）、四种导出格式、CLI 回归门禁、Docker 一键演示与 XHGui 对接——另外两项属性适用于以上全部：PHP 7.2–8.6 兼容性与 `xhprof.profiler` 门控。

其中两种视图的实际界面（截图取自 Docker 演示数据）：

<p align="center">
  <img src="xhprof_html/docs/sample-diff-report-flat-view.jpg" alt="XHProf DIFF 报表：整体差异汇总与按 inclusive wall time 排序的回归/改进列表" width="900"><br>
  <em>DIFF 报表 —— 对比两次 run：整体差异汇总，以及按 inclusive wall time 差异排序的 Top 回归/改进。</em>
</p>

<p align="center">
  <img src="xhprof_html/docs/sample-flamegraph.jpg" alt="XHProf 近似火焰图" width="900"><br>
  <em>近似火焰图（分层 run）—— 火焰块宽度可信，但聚合边之下的拆分只是估算。</em>
</p>

# 生命周期 / Lifecycle

<p align="center"><img src="resource/xhprof-lifecycle.svg" alt="XHProf 生命周期：加载、启用、采集、落盘、读回、计算、渲染与导出" width="900"></p>

一次请求，从加载到报表。扩展在模块初始化时注册观察者（除非 `xhprof.profiler=0`）；剖析由 `xhprof.auto_enable` 或显式调用 `xhprof_enable()`（配合 `XHPROF_FLAGS_*`）开启，请求运行期间钩子按 caller/callee 累加指标。`xhprof_disable()` 返回原始数组，`save_run()` 写入 `output_dir`，PHP 侧再用 `get_run()` 读回，计算 flat / parent-child / DIFF / 聚合结果、渲染 UI、导出或运行 CLI——运行之间的对比与聚合会回流到同一个计算层。

# 项目结构 / Project structure

```
xhprof/
├── extension/            # PHP 扩展（C 实现）：xhprof.c、trace.h、php_xhprof.h
│   └── tests/            # 25 个 .phpt 测试（指标、门控、输出）
├── xhprof_lib/
│   ├── utils/            # run 读写与计算：xhprof_runs、xhprof_lib、
│   │                     #   callgraph_utils、xhprof_callgrind
│   └── display/          # 报表渲染层（xhprof.php）
├── xhprof_html/          # Web UI：index.php、report.php、callgraph.php、flamegraph.php
│   ├── css/ js/          # 样式表与原生报表 / 火焰图脚本
│   └── docs/             # 用户指南（index.html、index-fr.html）与截图
├── bin/                  # CLI：xhprofile（剖析脚本）、xhprof-report、xhprof-diff
├── scripts/              # 发布脚本与采样封装（xhprofile.php）
├── docker/               # Dockerfile；根目录的 docker-compose.yml 即一键演示
├── examples/             # sample.php，Docker 演示所剖析的示例脚本
├── resource/             # 吉祥物与架构 / 设计 / 功能 / 生命周期四张图
├── .github/workflows/    # CI（PHP 版本矩阵）；.appveyor.yml 与 travis/ 为历史遗留
├── package.xml           # PECL 打包描述
├── composer.json         # Composer 元数据（PHP >= 7.2 + ext-xhprof；自动加载 xhprof_lib 并暴露 CLI）
├── CHANGELOG · CREDITS · LICENSE
└── README.md · README.zh-CN.md
```

# PHP 版本
- 7.2
- 7.4
- 8.0
- 8.1
- 8.2
- 8.3
- 8.4
- 8.5
- 8.6（预发布期；已用 8.6.0RC2 完成构建并通过全量测试）

# 安装

## Docker 快速开始
无需本地 PHP 工具链，一条命令完成扩展编译并启动报表 UI：
```sh
docker compose up
```
随后打开 <http://localhost:8080>。容器启动时会自动预跑一条示例 run（`examples/sample.php`），run 数据存放在 `/tmp/xhprof`。

界面用法（run 列表与对比、flat / parent-child / diff 报表、聚合、callgraph、火焰图、导出）见 [xhprof_html/docs/index.html](xhprof_html/docs/index.html)。

## 通过 PECL 安装
```sh
pecl install xhprof
```

> **渠道说明：** PECL 上 xhprof 的最新发布仍是 2.3.10（2024-07）。2.3.11 起的安全修复与功能只在本仓库中，需要按下文从源码编译安装才能获得。

## 从源码编译安装
```
git clone https://github.com/longxinH/xhprof.git ./xhprof
cd xhprof/extension/
/path/to/php7/bin/phpize
./configure --with-php-config=/path/to/php7/bin/php-config
make && sudo make install
```

#### 在 php.ini 中添加配置
```
[xhprof]
extension = xhprof.so
xhprof.output_dir = /tmp/xhprof
```

### php.ini 配置
|      选项       |  默认值  |  版本  |  说明  |
| --------------- |:-------------:|:-------------:|:---------|
|xhprof.output_dir  | "" | 全部 |输出目录|
|xhprof.sampling_interval  | 100000 | >= v2.* |采样分析器使用的采样间隔，单位为微秒|
|xhprof.sampling_depth  | INT_MAX | >= v2.* |采样分析器追踪调用链的最大深度|
|xhprof.collect_additional_info  | 0 | >= v2.1 |采集 mysql_query、curl_exec 的内部信息。默认值为 0，开启值为 1|
|xhprof.collect_files  | 0 | >= v2.3.16 |记录每个被剖析用户函数的定义文件与行号（内部函数没有）。设为 1 时 run 文件带 `"__files__"` 映射：报告在函数名旁显示 `file:line`，`format=json` 导出为 `"files"`。代价：每次被剖析调用一次哈希查找（约 30ns，约为剖析自身开销的一成），run 文件每函数约增 100 字节|
|xhprof.collect_callsites  | 0 | >= v2.3.17 |记录每个 `caller==>callee` 对的调用点（`file:line`，取该对首次调用处）。父-子视图在 caller 旁显示，`format=json` 导出为 `"callsites"`。深层递归级别共用首次调用的注解，`foo@2` 等递归变体归到基名对|
|xhprof.collect_timeline  | 0 | >= v2.3.17 |记录每个函数首次与末次调用的开始时刻（相对剖析起点的微秒）。`format=json` 导出为 `"timeline"`（暂仅数据，无 HTML 视图）|
|xhprof.sampling_memory_interval  | 0 | >= v2.3.17 |仅采样模式（字节，0=关）。除按时间间隔采样外，堆用量自上次内存采样增长超过该字节数时也记录一个栈样本。样本的折叠、导出与火焰图与时间采样完全一致；采样点=跨过阈值后的首个调用边界，函数内部分配归因给该次调用。低于 4KB 会被钳位；运行中途才开启该项可能立即多出 1 条样本|
|xhprof.profiler  | 1 | >= v2.3.12 |System（只能写 php.ini / `-d`）。设为 0 时扩展仍加载但不注册任何 observer/proxy：空闲开销回落到未加载扩展的水平；此时 `xhprof_enable()` / `xhprof_sample_enable()` 返回 false 并抛出 `E_WARNING`|
|xhprof.auto_enable  | 0 | >= v2.3.12 |System。请求启动即自动开启分层剖析，无需调用 `xhprof_enable()`（需 `xhprof.profiler=1`；为 0 时静默不生效）|
|xhprof.auto_enable_flags  | 0 | >= v2.3.12 |System。`xhprof.auto_enable` 使用的 flags，如 `XHPROF_FLAGS_CPU \| XHPROF_FLAGS_MEMORY`|

# 开启额外采集
#### php.ini 中添加 xhprof.collect_additional_info
```sh
xhprof.collect_additional_info = 1
````
# 选项
```php
xhprof_enable(XHPROF_FLAGS_NO_BUILTINS | XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY);
```
- `XHPROF_FLAGS_NO_BUILTINS` 不分析内置函数
- `XHPROF_FLAGS_CPU` 采集函数的 CPU 时间
- `XHPROF_FLAGS_MEMORY` 采集函数的内存占用
- `XHPROF_FLAGS_CPU_SPLIT` 分别采集用户态（`ut`）与内核态（`st`）CPU 时间，外加次要/主要页错误（`minflt`/`majflt`），替代合并的 `cpu`

示例
```php
<?php

// 开启剖析
xhprof_enable(XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY);

// ... 需要剖析的代码 ...

// 结束剖析并获取原始数据
$xhprof_data = xhprof_disable();

// $xhprof_data 以 "caller==>callee" 为键，例如：
// array(
//     "main()" => array(
//         "wt" => 237,
//         "ct" => 1,
//         "cpu" => 100,
//     )
// )
print_r($xhprof_data);
```

- `wt` 函数/方法执行的耗时
- `ct` 函数被调用的次数
- `cpu` 函数/方法执行消耗的 CPU 时间
- `ut` / `st` 用户态/内核态 CPU 时间，配 `XHPROF_FLAGS_CPU_SPLIT` 使用（替代合并的 `cpu`）
- `minflt` / `majflt` 次要/主要页错误，配 `XHPROF_FLAGS_CPU_SPLIT` 使用
- `mu` 函数/方法使用的内存。通过调用 zend_memory_usage 获取内存占用
- `pmu` 函数/方法使用的峰值内存。通过调用 zend_memory_peak_usage 获取内存

`load::<file>` 条目（include/require）也值得读：它的 `wt` 就是该文件的编译耗时，且只有真正发生编译时条目才存在——由 opcache 命中的文件在外层短路、编译钩子根本不进入，因此 opcache 命中没有 `load::` 条目。

读 profile 时还有一条引擎侧效应要记住（PHP 8.4/8.5 且开启 OPcache，即生产默认）：优化器会整条删除"被调方是纯函数（如 `function f() { return 1; }`）且结果未使用"的调用，这类调用合法地不会出现在 profile 里——需要它出现就给函数加一个副作用。（JIT 不在测试套件覆盖范围内。）

### PDO::exec
### PDO::query
### mysqli_query
```php
$mysqli = new mysqli("localhost", "my_user", "my_password", "user");
$result = $mysqli->query("SELECT * FROM user LIMIT 10");
```
##### 输出数据
```
mysqli::query#SELECT * FROM user LIMIT 10
```

### PDO::prepare
将预处理占位符替换为实际参数，使性能分析更直观（不改变 zend 的执行流程）
```php
$_sth = $db->prepare("SELECT * FROM user where userid = :id and username = :name");
$_sth->execute([':id' => '1', ':name' => 'admin']);
$data1 = $_sth->fetch();

$_sth = $db->prepare("SELECT * FROM user where userid = ?");
$_sth->execute([1]);
$data2 = $_sth->fetch();
```
##### 输出数据
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
##### 输出数据
```
curl_exec#http://www.baidu.com
```

### 文件函数
```php
$config = file_get_contents("/etc/myapp/config.ini");
$fh = fopen("/var/log/myapp.log", "a");
```
##### 输出数据
```
file_get_contents#/etc/myapp/config.ini
fopen#/var/log/myapp.log
```
`file_put_contents` 与此相同。

### Redis
`Redis::get`、`set`、`del`、`expire`、`incr`、`hget`、`hgetall`、`lpush`、`rpush`、`sadd`、`smembers` 会带各自的 key 参数记录：
```php
$user = $redis->get("user:42");
```
##### 输出数据
```
Redis::get#user:42
```

# 数据导出与可视化

除了 HTML 报表，run 数据还可以导出到浏览器之外：

- **火焰图** — `xhprof_html/flamegraph.php` 为一次 run 渲染火焰图，页面会标明当前是哪种视图。**分层 run 为近似视图**（横幅：*Approximate*）：xhprof 存储的是聚合后的 `caller==>callee` 边，而不是逐次调用帧，因此每个函数的 inclusive 指标会按各出边的占比分摊到它的各个调用上，剩余部分计为自身耗时 —— 火焰块宽度是可信的，聚合边之下的拆分只是估算。**采样 run 为精确视图**（横幅：*Sampled flame graph (exact)*）：每个采样点就是一条完整调用栈，火焰块宽度是真实携带它的采样数，只有采样真正走过的路径才会出现。窄于整条 run 的 `?threshold=<0..1>`（默认 0.01）的火焰块会折叠进 `(others)` 帧。
- **Callgrind** — 将 run 导出为 callgrind 格式，用 [KCachegrind](https://apps.kde.org/kcachegrind/) 或 QCachegrind 打开，做源码级/被调方分析。当 run 带文件映射（`xhprof.collect_files=1`）时，导出会带上 `fl=`/`cfl=`，KCachegrind 里每个函数直接关联到源文件。
- **时间线** — `xhprof_html/timeline.php`（run 带 timeline 数据时，报表页会出现入口链接）展示每个函数首次与末次调用的时刻：每函数一条横条，从首次调用起点画到末次调用起点——这是时间窗、不是连续执行——相对剖析起点。由 `xhprof.collect_timeline=1` 采集。
- **JSON / CSV** — flat 报表的机器可读导出，便于脚本、看板或自建 diff。

所有导出都能从报表页面的 **Export** 链接进入（**Export**：Flame Graph (approximate) | JSON | CSV | callgrind）—— 火焰图链接保留 *approximate* 标签，但采样 run 打开的是精确视图。直接 URL 形如 `report.php?format=json`、`report.php?format=csv`、`report.php?format=callgrind`；`report.php?format=folded`（仅限采样 run）按标准火焰图工具格式输出，每个不同的栈一行 `frame;frame;... <sample count>`，非采样 run 会返回 400。

## 从报表跳到源码

采到文件映射（`xhprof.collect_files=1`）后，函数名与调用点旁会出现 `文件:行` 标注。在 UI 运行前定义一个编辑器 URL 模板，它们就变成可点的链接（`%s`=路径、`%d`=行号）：

```php
define('XHPROF_EDITOR_URL', 'vscode://file%s:%d');
// 其他常见模板：
// define('XHPROF_EDITOR_URL', 'phpstorm://open?file=%s&line=%d');
// define('XHPROF_EDITOR_URL', 'subl://open?url=file://%s&line=%d');
```

# XHGui recipe

[XHGui](https://github.com/perftools/xhgui) 在 xhprof run 之上提供长期聚合的 UI。这套对接由持续维护的 [perftools/php-profiler](https://github.com/perftools/php-profiler) 包负责，不在本仓库内 —— xhprof 只需要提供扩展：

```sh
pecl install xhprof                    # 本扩展；PECL 上是 2.3.10，2.3.11+ 需从源码安装
composer require perftools/php-profiler
```

## 上传方案（推荐）: upload saver

php-profiler 以 JSON 形式把每次剖析 POST 到 XHGui 的 `/run/import` 端点：

```php
<?php
// config/config.php —— perftools/php-profiler 的最小配置。
// php-profiler 会自动探测已加载的剖析扩展；完整选项见其 README
// （https://github.com/perftools/php-profiler）。
return [
    'profiler.enable'     => function () { return true; },
    'profiler.flags'      => [XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY],
    'save.handler'        => 'upload',
    'save.handler.upload' => [
        'url'   => 'https://xhgui.example.com/run/import',
        // 必须与 XHGui 的 'upload.token' 配置一致；以 ?token= 查询参数发送。
        // 仅当 XHGui 未配置 upload.token 时才可以省略。
        'token' => 'change-me',
    ],
];
```

URL 应指向带 IP 白名单的 HTTPS 端点：能访问该地址并持有 token 的人都可以注入剖析数据。

## 文件方案 + 离线导入: file saver

被剖析的应用无法直连 XHGui 时，先把 jsonlines 写到本地文件再导入 —— 同样只需这一个 composer 包：

```php
    'save.handler'      => 'file',
    'save.handler.file' => ['filename' => '/tmp/xhgui.data.jsonl'],
```

```sh
# 在 XHGui 代码库中执行
php external/import.php -f /tmp/xhgui.data.jsonl
```

同一个文件导入两次会产生重复的 profile，只需导入一次。

## PHP 8 下不再支持直连 MongoDB

旧的 `save.handler => 'mongodb'` 配方（配合 `perftools/xhgui-collector`）在 PHP 8 下不可用：它依赖经已废弃的 `alcaeus/mongo-php-adapter` 提供的 legacy `MongoClient`，而 `xhgui-collector` 本身已归档（上游也已把 MongoDB saver 标记为 "discouraged"）。请使用上面两种方案之一。

# CLI 报表与 diff 门禁

脚本与 CI 场景无需启动 Web 服务：

```sh
bin/xhprof-report --source=xhprof_foo <run_id>          # 在终端输出 flat 报表
bin/xhprof-diff --threshold=5% <baseline> <candidate>   # 超过阈值时以非零状态退出
```

同一份代码在同一台机器上跑两次也可能相差数十个百分点，因此请比较在相同条件下采集的 run，并把阈值定得高于实测的抖动范围。

# 注意事项
- xhprof 扩展只要在 php.ini 中加载即产生约 2 倍的函数调用开销（即使从不调用 `xhprof_enable()` 开启剖析）。不剖析的生产环境不建议常驻加载。
- 如果必须常驻加载但永不剖析，`xhprof.profiler=0` 会使扩展完全不注册 observer：空闲开销回落到与未加载扩展相当的水平；此后 `xhprof_enable()` / `xhprof_sample_enable()` 返回 false 并告警，而不是静默无数据。需要运行时剖析的机器不要设置它。
- 解析大 run 报表需要 `memory_limit >= 512M`。

## PECL 仓库
[![pecl](resource/pecl.png)](https://pecl.php.net/package/xhprof)

由 [erik.xyz](https://erik.xyz) 维护
