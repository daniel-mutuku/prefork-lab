<?php
// Shared helpers for the prefork lab: app boot, memory readings, fork safety.

// ---------- app ----------

function boot_app(): array
{
    foreach (glob(__DIR__ . '/app/*.php') as $file) {
        require_once $file;
    }
    $config = [];
    for ($i = 0; $i < 200000; $i++) {
        $config["key.$i"] = str_repeat('x', 32) . $i;
    }

    // Test hook: simulate a service provider that connects during boot.
    if (getenv('LEAK_SOCKET_AT_BOOT')) {
        $GLOBALS['__leaked_socket'] = stream_socket_server('tcp://127.0.0.1:0');
    }
    return $config;
}

// ---------- memory readings (kB) ----------

/**
 * RSS / PSS / USS / dirty of a process from /proc/<pid>/smaps_rollup.
 * 'dirty' (Private_Dirty) = pages this process actually wrote: the honest per-worker cost.
 * 'uss' also counts Private_Clean, which can be inflated after fork: if the master has read
 * its own /proc/self/maps or smaps before forking, each child reports ~20 MB Private_Clean
 * here that never shows up as used RAM. Don't recycle on it.
 */
function smaps(int|string $pid = 'self'): array
{
    $out = ['rss' => 0, 'pss' => 0, 'uss' => 0, 'dirty' => 0];
    $raw = @file_get_contents("/proc/$pid/smaps_rollup");
    if ($raw === false) {
        return $out;
    }
    preg_match_all('/^(\w+):\s+(\d+) kB/m', $raw, $m, PREG_SET_ORDER);
    $f = [];
    foreach ($m as [, $k, $v]) {
        $f[$k] = (int) $v;
    }
    $out['rss'] = $f['Rss'] ?? 0;
    $out['pss'] = $f['Pss'] ?? 0;
    $out['uss'] = ($f['Private_Clean'] ?? 0) + ($f['Private_Dirty'] ?? 0);
    $out['dirty'] = $f['Private_Dirty'] ?? 0;
    return $out;
}

/** Memory the box can still give out, in MB. Uses the cgroup limit inside a container. */
function available_mb(): int
{
    $max = @trim((string) file_get_contents('/sys/fs/cgroup/memory.max'));
    $cur = @trim((string) file_get_contents('/sys/fs/cgroup/memory.current'));
    if ($max !== '' && $max !== 'max' && is_numeric($max) && is_numeric($cur)) {
        return intdiv((int) $max - (int) $cur, 1024 * 1024);
    }
    preg_match('/^MemAvailable:\s+(\d+) kB/m', (string) file_get_contents('/proc/meminfo'), $m);
    return intdiv((int) ($m[1] ?? 0), 1024);
}

// ---------- fork safety ----------

/**
 * Lists this process's open descriptors and returns anything that must not cross a fork:
 * sockets, and files outside the allowlist.
 */
function fd_audit(array $allow = []): array
{
    $bad = [];
    foreach (scandir('/proc/self/fd') as $fd) {
        if (!ctype_digit($fd) || (int) $fd <= 2) {
            continue; // stdin/stdout/stderr
        }
        $target = @readlink("/proc/self/fd/$fd");
        if ($target === false || str_starts_with($target, '/proc/')) {
            continue; // the scandir handle itself
        }
        $isOk = str_starts_with($target, 'anon_inode:')      // epoll/eventfd/timerfd
            || str_starts_with($target, '/dev/')
            || str_contains($target, 'opcache')
            || str_starts_with($target, 'pipe:');              // our control pipes
        foreach ($allow as $prefix) {
            $isOk = $isOk || str_starts_with($target, $prefix);
        }
        if (str_starts_with($target, 'socket:') || !$isOk) {
            $bad[] = "fd $fd -> $target";
        }
    }
    return $bad;
}

/** Return freed memory before forking so it isn't copied into every child. */
function compact_heap(): void
{
    gc_collect_cycles();
    gc_mem_caches();
}

/** Everything a child must redo right after fork(). */
function post_fork_reset(string $title): void
{
    mt_srand(random_int(PHP_INT_MIN, PHP_INT_MAX)); // otherwise every worker gets the same "random" sequence
    pcntl_signal(SIGTERM, SIG_DFL);
    pcntl_signal(SIGINT, SIG_DFL);
    cli_set_process_title($title);
    // Make the kernel's OOM killer pick a worker before the master.
    @file_put_contents('/proc/self/oom_score_adj', '500');
}
