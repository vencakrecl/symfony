# Container build memory investigation

This branch contains only the benchmark harness and results. The Symfony patch and regression
tests are on [`perf/release-compiler-before-container-load`](https://github.com/vencakrecl/symfony/tree/perf/release-compiler-before-container-load).

The investigated change releases container compilation memory in `KernelTrait::initializeContainer()`
after the compiled container is written and the build lock is released, before the generated
runtime container is loaded:

```php
unset($container);
gc_collect_cycles();
gc_mem_caches();
```

The cached-container return paths precede this code. No cache warmer or application service is
required. The baseline is Symfony `8.1`, commit `2372eac803700619794c8980ba7cfe5c39d86145`.

## Reproduce

Install the normal Symfony development dependencies first (see `CONTRIBUTING.md`). Use the same
PHP executable, extensions and dependencies for both source checkouts. The runner loads third-party
dependencies from this checkout and directs Symfony component autoloading to the selected source.
`--source` is required: the benchmark branch itself deliberately contains unpatched Symfony code.

```sh
git worktree add --detach /tmp/symfony-memory-baseline 2372eac803700619794c8980ba7cfe5c39d86145
git fetch https://github.com/vencakrecl/symfony.git perf/release-compiler-before-container-load
git worktree add --detach /tmp/symfony-memory-patched FETCH_HEAD

php -d xdebug.mode=off tools/container-build-memory/benchmark.php \
    --baseline=/tmp/symfony-memory-baseline \
    --source=/tmp/symfony-memory-patched \
    --samples=5 --services=10000 --warmup-mib=0 \
    --output=/tmp/symfony-memory-no-working-set.json

php -d xdebug.mode=off tools/container-build-memory/benchmark.php \
    --baseline=/tmp/symfony-memory-baseline \
    --source=/tmp/symfony-memory-patched \
    --samples=5 --services=10000 --warmup-mib=64 \
    --output=/tmp/symfony-memory-with-working-set.json
```

Each sample runs four fresh PHP processes: baseline cold/warm and patched cold/warm. The runner
alternates baseline/patched order between samples. A cold process starts with an empty generated
container cache; the following warm process reuses that cache. This does not flush the OS page
cache. The runner verifies that cold boot actually compiled and warm boot did not.

Worker memory limits are disabled so the full peak can be measured. CLI OPcache, JIT and
Xdebug mode are disabled in worker processes. Raw JSON records PHP/OS versions,
PHP allocated peak memory, phase-specific used/allocated memory, elapsed time and process maximum
RSS. `ru_maxrss` is normalized from bytes on Darwin and KiB on Linux. It is a fresh-process
high-water mark, not an end-of-run RSS snapshot.

The workload registers 10,000 public services and 128 private dependencies, dumps the real
container, loads it and resolves a generated service. No compiler pass is added in the default
`native` scenario. `--warmup-mib=64` adds a temporary 64 MiB working set in the kernel's warmup
hook to model overlapping metadata/template work. This is explicitly synthetic; zero exercises
the container lifecycle without that allocation.

The optional `--scenario=retaining-pass` adds a pass retaining a back-reference to the builder.
That is a controlled cycle-collection case, separate from the native scenario. It is also the
case used by the lifecycle regression test.

## Results

Five samples per variant, PHP 8.5.12, ARM64, CLI OPcache disabled. The numbers below are medians.
All five allocated-memory peaks were identical within each variant. Raw samples are in `results/`.

| Linux workload | Baseline PHP peak | Patched PHP peak | Baseline process RSS peak | Patched process RSS peak |
| --- | ---: | ---: | ---: | ---: |
| Native, no synthetic warmup working set | 76.19 MiB | 51.39 MiB | 112.85 MiB | 88.66 MiB |
| Native, 64 MiB warmup working set | 136.50 MiB | 102.50 MiB | 173.20 MiB | 139.76 MiB |
| Retaining pass, 64 MiB warmup working set | 136.50 MiB | 102.50 MiB | 173.08 MiB | 139.76 MiB |

Warm cached boot had the same 37.69 MiB PHP peak for both Linux variants in all scenarios.
There is no statistically established speed improvement: the native 64 MiB Linux run measured
1148 ms versus 1162 ms, and timing varies with machine load. This change targets memory.

The native case does not require the entire `ContainerBuilder` to remain alive at warmup.
Weak-reference observations showed it already released on the baseline, while other discarded
compiler objects still awaited collection. In the native Linux run, GC's cumulative collected counter rose from 9 after dumping to 80,649 by warmup after the patch. Both cyclic garbage and
allocator-retained memory matter; checking only the builder's lifetime is not enough to measure
all the memory benefit.

macOS controls with the same 10,000 services and 64 MiB working set:

| Cleanup before loading runtime container | Median PHP peak | Median RSS peak |
| --- | ---: | ---: |
| Baseline | 136.52 MiB | approximately 166.8 MiB |
| `unset($container)` only | 134.52 MiB | 165.14 MiB |
| `unset` + `gc_collect_cycles()` | 134.52 MiB | 164.19 MiB |
| `unset` + collection + `gc_mem_caches()` | 102.52 MiB | 133.63 MiB |

These controls show that collection alone does not return allocator-retained pages. The explicit
retaining-pass regression test separately establishes why cycle collection is needed.

## Regression tests and limitations

`KernelTest::testCompilerGraphIsReleasedBeforeCacheWarmup` observes a weak reference to a builder
retained in a compiler-pass cycle from the warmup hook. It fails on the unpatched baseline and
passes with the patch. `testCachedBootDoesNotCollectUnrelatedCycles` verifies that cached boot
skips the forced collection. Tests assert lifecycle behavior, not machine-dependent byte counts.

Validation on PHP 8.5.12:

- DependencyInjection suite: 1,814 tests, 3,941 assertions, 10 skips.
- HttpKernel suite: 1,790 tests, 4,160 assertions.
- FrameworkBundle kernel and cache-clear tests: 24 tests, 49 assertions.
- Changed PHP files checked using the repository's PHP CS Fixer configuration.

This is an exploratory result, not a universal percentage saving for Symfony applications.
Actual gains depend on the service graph, warmers, PHP version, allocator and extensions. PHP 8.4,
other architectures and the full Symfony test matrix remain unverified. Forced collection also
changes when destructors of unreachable compiler objects run; that lifecycle choice needs
maintainer review before proposing this as a supported upstream fix.
