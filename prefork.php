<?php
// Prefork pool: boot once, fork workers, scale on queue depth AND available memory,
// recycle workers on a private-memory (USS) budget, refuse to fork with live sockets.
//
// php -d opcache.enable_cli=1 prefork.php --max=8 [options]
//   --min=1               --max=8
//   --fixed=N             ignore the queue and keep exactly N workers (for benchmarks)
//   --queue-file=PATH     file holding the current queue depth (simulated queue)
//   --job-peak-mb=64      expected peak memory of one job, used by admission
//   --min-available-mb=256  never fork if this much memory would no longer be free
//   --uss-budget-mb=0     recycle a worker when its private dirty memory passes this (0 = off)
//   --max-jobs=0          recycle after N jobs (0 = off)
//   --max-seconds=0       recycle after N seconds (0 = off)

require __DIR__ . '/lib.php';

const EXIT_MAX_JOBS = 10, EXIT_MAX_SECONDS = 11, EXIT_USS = 12, EXIT_ORPHAN = 13;
const REASONS = [0 => 'stopped', EXIT_MAX_JOBS => 'max_jobs', EXIT_MAX_SECONDS => 'max_seconds', EXIT_USS => 'uss_budget', EXIT_ORPHAN => 'orphaned'];

$o = getopt('', ['min:', 'max:', 'fixed:', 'queue-file:', 'job-peak-mb:', 'min-available-mb:', 'uss-budget-mb:', 'max-jobs:', 'max-seconds:']);
$cfg = [
    'min'           => (int) ($o['min'] ?? 1),
    'max'           => (int) ($o['max'] ?? 8),
    'fixed'         => isset($o['fixed']) ? (int) $o['fixed'] : null,
    'queue_file'    => $o['queue-file'] ?? null,
    'job_peak_mb'   => (int) ($o['job-peak-mb'] ?? 64),
    'min_avail_mb'  => (int) ($o['min-available-mb'] ?? 256),
    'uss_budget_mb' => (int) ($o['uss-budget-mb'] ?? 0),
    'max_jobs'      => (int) ($o['max-jobs'] ?? 0),
    'max_seconds'   => (int) ($o['max-seconds'] ?? 0),
];

function logline(string $msg): void
{
    fwrite(STDERR, date('H:i:s') . " [master] $msg\n");
}

// ---------- boot once ----------
$config = boot_app();
compact_heap();
cli_set_process_title('lab-prefork-master');
$masterPid = getmypid();
logline(sprintf('booted; opcache.enable_cli=%s', ini_get('opcache.enable_cli') ? 'on' : 'off'));

// ---------- worker ----------
function run_worker(array $config, array $cfg, int $masterPid): never
{
    post_fork_reset('lab-prefork-worker');

    $stop = false;
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function () use (&$stop) { $stop = true; }); // finish the current job, then exit

    // Connections would be opened HERE, lazily, never in the master.
    $born = time();
    $jobs = 0;
    static $leak = [];
    $leakKb = (int) getenv('LEAK_KB_PER_JOB');

    while (!$stop) {
        if (posix_getppid() !== $masterPid) {
            exit(EXIT_ORPHAN); // master is gone: don't keep running unsupervised
        }

        // --- one "job" ---
        $value = $config['key.' . mt_rand(0, 199999)];
        $class = 'App\\Service' . mt_rand(0, 2999);
        (new $class())->{'method' . mt_rand(0, 9)}([$value]); // runs app code, like a real job
        if ($leakKb) {
            $leak[] = str_repeat('j', $leakKb * 1024); // simulates per-job memory growth
        }
        $jobs++;

        // --- recycle checks, after the job like Laravel's --memory ---
        if ($cfg['max_jobs'] && $jobs >= $cfg['max_jobs']) {
            exit(EXIT_MAX_JOBS);
        }
        if ($cfg['max_seconds'] && time() - $born >= $cfg['max_seconds']) {
            exit(EXIT_MAX_SECONDS);
        }
        if ($cfg['uss_budget_mb'] && smaps()['dirty'] / 1024 > $cfg['uss_budget_mb']) {
            exit(EXIT_USS);
        }
        usleep(200_000);
    }
    exit(0);
}

// ---------- master loop ----------
$children = [];   // pid => true
$reasons = [];    // exit reason => count
$draining = false;
$lastStatus = $lastRefusal = 0;

pcntl_async_signals(true);
$drain = function () use (&$draining, &$children) {
    $draining = true;
    logline('draining: asking workers to finish their current job');
    foreach (array_keys($children) as $pid) {
        posix_kill($pid, SIGTERM);
    }
};
pcntl_signal(SIGTERM, $drain);
pcntl_signal(SIGINT, $drain);

$target = function () use ($cfg): int {
    if ($cfg['fixed'] !== null) {
        return $cfg['fixed'];
    }
    $depth = $cfg['queue_file'] ? (int) @file_get_contents($cfg['queue_file']) : $cfg['max'];
    return max($cfg['min'], min($cfg['max'], $depth)); // one worker per waiting job, within bounds
};

while (true) {
    // Reap exited workers and record why they left.
    while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
        unset($children[$pid]);
        $reason = REASONS[pcntl_wexitstatus($status)] ?? 'crashed';
        $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
        if ($reason !== 'stopped') {
            logline("worker $pid exited: $reason");
        }
    }

    if ($draining) {
        if (!$children) {
            logline('all workers stopped; exiting');
            exit(0);
        }
        usleep(100_000);
        continue;
    }

    $want = $target();
    $alive = count($children);

    // Scale down: ask the surplus to finish and exit.
    foreach (array_slice(array_keys($children), $want) as $pid) {
        posix_kill($pid, SIGTERM);
    }

    // Scale up, one fork per tick, only if fork-safe and the box can afford it.
    if ($alive < $want) {
        $bad = fd_audit([realpath($_SERVER['SCRIPT_FILENAME'])]); // PHP keeps the entry script open
        $free = available_mb();
        if ($bad) {
            if (time() - $lastRefusal >= 2) {
                logline('REFUSING to fork, live descriptors in master: ' . implode(', ', $bad));
                $lastRefusal = time();
            }
        } elseif ($free - $cfg['min_avail_mb'] < $cfg['job_peak_mb']) {
            $reasons['admission_refused'] = ($reasons['admission_refused'] ?? 0) + 1;
            if (time() - $lastRefusal >= 2) {
                logline("admission refused: {$free} MB available, need {$cfg['job_peak_mb']} MB + {$cfg['min_avail_mb']} MB reserve");
                $lastRefusal = time();
            }
        } else {
            $pid = pcntl_fork();
            if ($pid === 0) {
                run_worker($config, $cfg, $masterPid);
            } elseif ($pid > 0) {
                $children[$pid] = true;
            } else {
                logline('fork failed');
            }
        }
    }

    // Status line every 2 seconds. Memory per process is measured from outside (measure.sh):
    // the master never reads its own /proc/self/smaps, which skews children's numbers.
    if (time() - $lastStatus >= 2) {
        logline(sprintf('workers %d/%d | available %d MB | %s',
            count($children), $want, available_mb(), json_encode($reasons)));
        $lastStatus = time();
    }

    usleep(50_000);
}
