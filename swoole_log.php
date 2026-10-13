<?php
use Swoole\Server;
use Swoole\Timer;

//初始化内存日期
const LOG_DIR_FORMAT = 'Y-m';
const LOG_FIX_FORMAT = 'd_H';
$currentDir = date(LOG_DIR_FORMAT);
$currentFix = date(LOG_FIX_FORMAT);


// 初始化全局缓冲区
$buffers = [];
$logConfig = [];
$lastFlushTime = time();
$maxBufferSize = 200;
$flushInterval = 1;
$fileNameCache = [];

// 定义核心刷盘逻辑
$flushLogs = function($targetProject = null, $targetLevel = null) use (&$buffers, &$lastFlushTime, &$currentDir, &$currentFix, &$fileNameCache, &$logConfig) {
    $projectsToFlush = $targetProject ? [$targetProject] : array_keys($buffers);

    foreach ($projectsToFlush as $proj) {
        if (empty($buffers[$proj])) continue;

        $levelsToFlush = $targetLevel ? [$targetLevel] : array_keys($buffers[$proj]);

        foreach ($levelsToFlush as $lvl) {
            if (!empty($buffers[$proj][$lvl])) {
                $cacheKey = $proj . '_' . $lvl;
                // 缓存文件名
                if (!isset($fileNameCache[$cacheKey])) {
                    // 规范化目录路径，确保末尾有斜杠
                    $baseDir = rtrim($logConfig[$proj] ?? $logConfig['__default__'], '/') . '/' . $currentDir . '/';

                    // 如果目录不存在，动态创建
                    if (!is_dir($baseDir)) {
                        mkdir($baseDir, 0777, true);
                    }

                    $fileNameCache[$cacheKey] = $baseDir . $currentFix . "_" . $lvl . ".log";
                }

                // 将该级别缓冲区内的多条日志用换行符连成大文本，批量追加写入
                file_put_contents($fileNameCache[$cacheKey], implode("\n", $buffers[$proj][$lvl]) . "\n", FILE_APPEND);

                // 清空缓存
                $buffers[$proj][$lvl] = [];
            }
        }
    }

    if (!$targetProject && !$targetLevel) {
        $lastFlushTime = time();
    }
};

// 3. 创建标准的 Swoole TCP 服务器 (使用 9503 端口)
$server = new Server('127.0.0.1', 9503, SWOOLE_PROCESS, SWOOLE_SOCK_TCP);

$server->set([
    'worker_num' => 1,             // 专门处理写盘，单进程即可，天然避免多进程抢占文件锁
    'enable_coroutine' => true,    // 开启协程支持，提升网络 I/O 吞吐
]);

// 4. 当 Worker 进程启动时，设置定时器和信号监听
$server->on('WorkerStart', function(Server $server, int $workerId) use (&$currentDir, &$currentFix, &$fileNameCache, &$lastFlushTime, $flushInterval, &$logConfig, $flushLogs) {
    echo "Swoole Log Worker started.\n";

    // 启动时动态加载配置文件
    if (file_exists(__DIR__ . '/swoole_log_config.php')) {
        $logConfig = include __DIR__ . '/swoole_log_config.php';
    } else {
        $logConfig = [];
    }

    if (empty($logConfig['__default__'])) {
        $logConfig['__default__'] = __DIR__ . '/storage/log/';
    }

    // 自动按天切分日期（每秒检查一次）
    Timer::tick(1000, function() use (&$currentDir, &$currentFix, &$fileNameCache, &$lastFlushTime, $flushInterval, $flushLogs) {
        $dir = date(LOG_DIR_FORMAT);
        if ($dir !== $currentDir) {
            // 切换目录
            $currentDir = $dir;
        }

        $fix = date(LOG_FIX_FORMAT);
        if ($fix !== $currentFix) {
            // 切换时间
            $currentFix = $fix;
            // 清空文件名缓存
            $fileNameCache = [];
        }


        // 全局时间兜底：如果距离上一次刷盘超过 1 秒，将所有级别的缓存统一落地一次
        if ((time() - $lastFlushTime) >= $flushInterval) {
            $flushLogs();
        }
    });

    // 监听进程终止信号，平滑退场
    pcntl_signal(SIGTERM, function() use ($server, $flushLogs) {
        echo "[SHUTDOWN] Received SIGTERM. Cleaning buffer...\n";
        $flushLogs();
        echo "[SHUTDOWN] All logs saved. Shutting down server.\n";
        $server->shutdown();
    });
});

// 接收并解开你的 JSON 数据
$server->on('Receive', function (Server $server, int $fd, int $reactorId, string $data) use (&$buffers, &$logConfig, $maxBufferSize, $flushLogs) {
    // 依靠 \n 分割拆包，解决 TCP 粘包
    $rawPackets = explode("\n", $data);

    foreach ($rawPackets as $packet) {
        $packet = trim($packet);
        if (empty($packet)) {
            continue;
        }

        $logObj = json_decode($packet, true);

        if (isset($logObj['level']) && isset($logObj['msg'])) {
            $projKey = $logObj['project'] ?? '__default__';
            if (empty($logConfig[$projKey])) {
                continue;
            }

            // 安全过滤并转小写
            $levelKey = preg_replace('/[^a-zA-Z0-9_\-]/', '', $logObj['level']);
            $levelKey = strtolower($levelKey);

            // 存入纯文本 msg
            if (!isset($buffers[$projKey])) {
                $buffers[$projKey] = [];
            }
            if (!isset($buffers[$projKey][$levelKey])) {
                $buffers[$projKey][$levelKey] = [];
            }
            $buffers[$projKey][$levelKey][] = $logObj['msg'];

            if (count($buffers[$projKey][$levelKey]) >= $maxBufferSize) {
                $flushLogs($projKey, $levelKey);
            }
        }
    }
});


$server->on('Close', function (Server $server, int $fd) {});

echo "Swoole TCP Log Server running on 127.0.0.1:9503...\n";
$server->start();

/*

swoole_log_config.php
```
<?php
return [
    '__default__' => __DIR__ . '/storage/log/',
];

```

手动启动
```
php swoole_log.php
```

Supervisor启动
```
[program:swoole-log-server]
; 1. 你的 Swoole 脚本绝对路径（根据你的实际存放位置修改）
command=php /var/www/logs/swoole_log.php

; 2. 进程启动与故障自愈配置
autostart=true                ; 随着 Supervisor 服务的启动而自动启动
autorestart=true               ; 如果 Swoole 进程意外崩溃（如内存溢出、被系统杀掉），秒级自动拉起
startsecs=3                   ; 进程持续运行 3 秒以上才认为启动成功，防止死循环无限重启

; 3. 运行权限
; 必须确保该用户对你的日志根目录（如 /var/log/app/）具备读写和创建子目录的权限
user=www

; 4. 核心安全配置：平滑重启与热重载的“保险锁”
stopsignal=TERM               ; 当执行 stop 命令时，向 Swoole 发送 TERM 信号，触发代码中的安全刷盘
stopwaitsecs=10               ; 给 Swoole 留出充裕的收尾时间（10秒），防止未写盘的缓存被强杀

; 5. 日志与标准输出配置（方便排查 Swoole 本身的报错）
redirect_stderr=true          ; 把错误输出和标准输出合并
stdout_logfile_maxbytes=50MB  ; 单个 Supervisor 日志文件最大 50MB
stdout_logfile_backups=5      ; 保留最近 5 个日志备份
stdout_logfile=/var/log/supervisor/swoole_log_server.log ; Supervisor 记录的 Swoole 运行日志
```

平滑启动
```
```

更新
```
# 读取新配置并更新进程
sudo supervisorctl update

# 查看当前服务的运行状态
sudo supervisorctl status

# 热重载配置
sudo supervisorctl signal USR1 swoole-log-server

# 重启服务
sudo supervisorctl restart swoole-log-server

# 查看日志
tail -f /var/log/supervisor/swoole_log_server.log
```
*/
