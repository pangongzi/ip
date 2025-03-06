### 项目描述


### 安装

使用 Composer 安装：

```bash
# 安装
composer require pangongzi/ip

# 更新
composer update pangongzi/ip

# 删除
composer remove pangongzi/ip

```

### 完全基于文件的查询

```php
$dbFile = "ip2region.xdb file path";
try {
    $searcher = XdbSearcher::newWithFileOnly($dbFile);
} catch (Exception $e) {
    printf("failed to create searcher with '%s': %s\n", $dbFile, $e);
    return;
}

$ip = '1.2.3.4';
$sTime = XdbSearcher::now();
$region = $searcher->search($ip);
if ($region === null) {
    // something is wrong
    printf("failed search(%s)\n", $ip);
    return;
}

printf("{region: %s, took: %.5f ms}\n", $region, XdbSearcher::now() - $sTime);

// 备注：并发使用，每个线程或者协程需要创建一个独立的 searcher 对象。
```

### 缓存 `VectorIndex` 索引

如果你的 php 母环境支持，可以预先加载 vectorIndex 缓存，然后做成全局变量，每次创建 Searcher 的时候使用全局的 vectorIndex，可以减少一次固定的 IO 操作从而加速查询，减少 io 压力。

```php
// 1、从 dbPath 加载 VectorIndex 缓存，把下述的 vIndex 变量缓存到内存里面。
$vIndex = XdbSearcher::loadVectorIndexFromFile($dbPath);
if ($vIndex === null) {
    printf("failed to load vector index from '%s'\n", $dbPath);
    return;
}

// 2、使用全局的 vIndex 创建带 VectorIndex 缓存的查询对象。
try {
    $searcher = XdbSearcher::newWithVectorIndex($dbFile, $vIndex);
} catch (Exception $e) {
    printf("failed to create vectorIndex cached searcher with '%s': %s\n", $dbFile, $e);
    return;
}

// 3、查询
$sTime = XdbSearcher::now();
$region = $searcher->search('1.2.3.4');
if ($region === null) {
    printf("failed search(1.2.3.4)\n");
    return;
}

printf("{region: %s, took: %.5f ms}\n", $region, XdbSearcher::now() - $sTime);

// 备注：并发使用，每个线程或者协程需要创建一个独立的 searcher 对象，但是都共享统一的只读 vectorIndex。
```

### 缓存整个 `xdb` 数据

如果你的 PHP 母环境支持，可以预先加载整个 `xdb` 的数据到内存，这样可以实现完全基于内存的查询，类似之前的 memory search 查询。

```php
// 1、从 dbPath 加载整个 xdb 到内存。
$cBuff = XdbSearcher::loadContentFromFile($dbPath);
if ($cBuff === null) {
    printf("failed to load content buffer from '%s'\n", $dbPath);
    return;
}

// 2、使用全局的 cBuff 创建带完全基于内存的查询对象。
try {
    $searcher = XdbSearcher::newWithBuffer($cBuff);
} catch (Exception $e) {
    printf("failed to create buffer cached searcher: %s\n", $dbFile, $e);
    return;
}

// 3、查询
$sTime = XdbSearcher::now();
$region = $searcher->search('1.2.3.4');
if ($region === null) {
    printf("failed search(1.2.3.4)\n");
    return;
}

printf("{region: %s, took: %.5f ms}\n", $region, XdbSearcher::now() - $sTime);

// 备注：并发使用，用整个 xdb 缓存创建的 searcher 对象可以安全用于并发。
```




欢迎补充





### 交流
email | 邮件　pangongzi1989@gmail.com  

wechat | 微信  
[![wechat.jpg](https://i.postimg.cc/hvvW2WWw/wechat.jpg)](https://postimg.cc/S2BvKPK7)