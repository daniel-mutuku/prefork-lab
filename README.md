# prefork-lab

A small lab for measuring how much memory PHP workers save when they are forked from one booted master instead of started as separate processes, and for testing the safety and scaling rules a real prefork pool needs.

It runs outside any framework, so nothing else can explain the numbers. A generated "app" stands in for what a framework loads at boot, and workers run a tiny job every 200 ms, like queue workers on a quiet queue.

Inspired by Ando Hakobyan's [Your Laravel queue workers keep 8 copies of your app in memory](https://dev.to/tevkert/your-laravel-queue-workers-keep-8-copies-of-your-app-in-memory-g4d) and his package [queue-prefork](https://github.com/tevkert/queue-prefork). If you run Laravel queues in production, use that package; this repo is for understanding and measuring the mechanism.

## Requirements

- Linux (reads `/proc/<pid>/smaps_rollup`, `/proc/meminfo`, `/proc/self/fd`)
- PHP 8.2+ CLI with `pcntl` and `posix` (tested on PHP 8.4)
- Bash, `pgrep`/`pkill`

Check the extensions:

```bash
php -m | grep -E 'pcntl|posix'
```

## Quick start

```bash
php generate_app.php 3000    # create the fake app in ./app (once)
scripts/bench.sh 8           # bare vs preforked, OPcache CLI off and on
scripts/decay.sh 8 60 10     # how fast sharing decays while workers run jobs
```

Example output from `scripts/bench.sh` (8 workers, PHP 8.4):

```
setup (8 workers)          processes  total RSS MB  total PSS MB     RAM used MB*
bare, opcache.enable_cli=0         8           815           650              612
prefork, opcache.enable_cli=0         9           755           108               40
bare, opcache.enable_cli=1         8           917           751              690
prefork, opcache.enable_cli=1         9           599           119              -58
```

Compare the **PSS** column. RSS counts shared pages once per process and hides the saving. The "RAM used" column is the drop in `MemAvailable`; it's a useful ground truth for bare workers but too noisy to read for small preforked totals.


## Running the pool

```bash
php -d opcache.enable_cli=1 prefork.php --max=8
```

| Option | Default | Meaning |
| --- | --- | --- |
| `--min` | 1 | Minimum workers |
| `--max` | 8 | Maximum workers |
| `--fixed=N` | off | Keep exactly N workers and ignore the queue (for benchmarks) |
| `--queue-file=PATH` | none | File holding the current queue depth; the pool runs one worker per waiting job, within min/max |
| `--job-peak-mb` | 64 | Expected peak memory of one job, used by admission |
| `--min-available-mb` | 256 | Memory that must stay free after admitting a worker |
| `--uss-budget-mb` | 0 (off) | Recycle a worker when its private dirty memory passes this |
| `--max-jobs` | 0 (off) | Recycle a worker after N jobs |
| `--max-seconds` | 0 (off) | Recycle a worker after N seconds |

Process titles are `lab-prefork-master` and `lab-prefork-worker` (bare mode: `lab-bare-worker`), so you can watch them with `scripts/measure.sh lab-prefork` or `smem -P lab-`.

Stop the pool with `SIGTERM` or Ctrl+C: it stops forking, lets each worker finish its current job, then exits.

```bash
pkill -TERM -f '^lab-prefork-master'
```

Use the `^` anchor in `pkill -f` patterns. Without it, the pattern can also match the shell running the command.

## What the pool does

**Before every fork, the master:**

1. Compares the current workers with the target (queue depth, or `--fixed`).
2. Runs a descriptor audit on `/proc/self/fd` and refuses to fork if it holds a socket or a file outside the allowlist. PHP keeps its own entry script open, so that file is allowlisted.
3. Runs the admission check: `MemAvailable - min_available_mb >= job_peak_mb`. Inside a container it uses the cgroup v2 limit (`memory.max` minus `memory.current`). If the check fails, it doesn't fork; the job waits.

**Right after fork, each worker:**

- reseeds `mt_rand`, so workers don't share a "random" sequence
- installs its own signal handlers and process title
- sets `oom_score_adj` to 500, so the kernel kills a worker before the master
- would open its own connections here, lazily (the lab has none)

**After every job, each worker checks:**

- private dirty memory against `--uss-budget-mb`
- job count against `--max-jobs`, and age against `--max-seconds`
- that its parent is still the master; if not, it exits instead of running unsupervised

The master reaps every exit, logs the reason (`uss_budget`, `max_jobs`, `max_seconds`, `orphaned`, `stopped`, `crashed`) and forks a replacement.

## Test hooks

| Variable | Effect |
| --- | --- |
| `LEAK_KB_PER_JOB=512` | Each job keeps 512 KB, to test recycling on the memory budget |
| `LEAK_SOCKET_AT_BOOT=1` | Opens a socket during boot, to test the descriptor audit |

```bash
# recycling: workers should be replaced every ~8-10 s
LEAK_KB_PER_JOB=512 php prefork.php --fixed=2 --uss-budget-mb=20

# descriptor audit: the master should refuse to fork and log the socket
LEAK_SOCKET_AT_BOOT=1 php prefork.php --fixed=2

# admission: the master should refuse to fork on a box with less than ~9 GB free
php prefork.php --fixed=4 --job-peak-mb=9000

# scaling: change the file while the pool runs
echo 0 > /tmp/q && php prefork.php --min=1 --max=6 --queue-file=/tmp/q
echo 10 > /tmp/q
```

## Measurement notes

- **Use PSS for totals.** RSS counts shared pages once per process, so summing it overstates memory. PSS splits shared pages between the processes sharing them.
- **Don't budget workers on USS.** If the master has read its own `/proc/self/maps` or `smaps` before forking, each child reports about 20 MB of `Private_Clean` memory that doesn't show up as used RAM. Recycling on USS then kills new workers at birth. The pool budgets on `Private_Dirty`, and the master never reads its own memory stats; measure from outside instead.
- **`memory_get_usage()` doesn't work in forked workers.** It counts the heap inherited from the master, so every worker looks large from birth.
- **OPcache CLI only helps forked workers.** Separately started processes each get their own shared-memory segment, which made bare workers larger in this lab (650 MB to 751 MB). Forked workers share the master's segment, and immutable compiled code slows how fast sharing decays.

## Limitations

- Linux only, because of `/proc`.
- Synthetic app and jobs. Real frameworks touch more memory per job, so sharing decays faster than here.
- No real queue or database. Connections are where fork bugs happen in practice; the pool only enforces that the master holds none.
- Single machine, short runs. Long-running decay and production load aren't covered.

## Further reading

- [proc_pid_smaps(5)](https://man7.org/linux/man-pages/man5/proc_pid_smaps.5.html): every field in `smaps` and `smaps_rollup`
- [queue-prefork](https://github.com/tevkert/queue-prefork): a production package for Laravel queues