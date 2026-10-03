# xhprof：PHP7 与 PHP8 的分层分析器
**[English](README.md) | [简体中文](README.zh-CN.md)**
[![CI](https://github.com/longxinH/xhprof/actions/workflows/ci.yml/badge.svg)](https://github.com/longxinH/xhprof/actions/workflows/ci.yml) [![Build status](https://ci.appveyor.com/api/projects/status/dornfeel5yutaxte/branch/master?svg=true)](https://ci.appveyor.com/project/longxinH/xhprof/branch/master)

<img src="resource/xhpy-blink.svg" alt="Xhpy — xhprof 项目宠物" width="140" align="right">

XHProf 是 PHP 的函数级分层分析器，带有简洁的、基于 HTML 的导航界面。原始数据采集组件用 C 实现（作为 PHP 扩展），报表/UI 层则完全使用 PHP 实现。它可以报告每个函数的 inclusive 与 exclusive wall time、内存占用、CPU 时间和调用次数。此外，它还支持对比两次运行（分层 DIFF 报表），或聚合多次运行的结果。

本版本支持 PHP7 与 PHP8。

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

## 通过 PECL 安装
```sh
pecl install xhprof
```

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
- `mu` 函数/方法使用的内存。通过调用 zend_memory_usage 获取内存占用
- `pmu` 函数/方法使用的峰值内存。通过调用 zend_memory_peak_usage 获取内存

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

# 注意事项
- xhprof 扩展只要在 php.ini 中加载即产生约 2 倍的函数调用开销（即使从不调用 `xhprof_enable()` 开启剖析）。不剖析的生产环境不建议常驻加载。
- 解析大 run 报表需要 `memory_limit >= 512M`。

## PECL 仓库
[![pecl](resource/pecl.png)](https://pecl.php.net/package/xhprof)
