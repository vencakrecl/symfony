<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Symfony\Component\Config\ConfigCache;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\CacheWarmer\WarmableInterface;
use Symfony\Component\HttpKernel\Kernel;

$options = getopt('', ['worker', 'baseline:', 'source:', 'samples:', 'services:', 'warmup-mib:', 'scenario:', 'cache-dir:', 'output:']);
$root = dirname(__DIR__, 2);
if (!isset($options['source'])) {
    throw new InvalidArgumentException('Provide --source=/path/to/patched/symfony. The benchmark checkout need not contain the patch.');
}
$source = realpath($options['source'] ?? $root);
if (!$source || !is_file($source.'/src/Symfony/Component/DependencyInjection/Kernel/KernelTrait.php')) {
    throw new InvalidArgumentException('The source must be a Symfony monorepo checkout.');
}
$loader = require $root.'/vendor/autoload.php';
$loader->addPsr4('Symfony\\Component\\', $source.'/src/Symfony/Component/', true);

class MemoryBenchmarkService
{
    public function __construct(public stdClass $dependency, public string $key)
    {
    }
}

class MemoryBenchmarkKernel extends Kernel implements WarmableInterface
{
    public array $phases = [];
    public ?WeakReference $compilerReference = null;
    public ?bool $compilerAliveAtWarmup = null;

    public function __construct(
        private string $directory,
        private int $serviceCount,
        private int $warmupBytes,
        private bool $retainingPass,
    ) {
        parent::__construct('prod', false);
    }

    public function registerBundles(): iterable
    {
        return [];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
    }

    public function getProjectDir(): string
    {
        return $this->directory;
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        $this->compilerAliveAtWarmup = null !== $this->compilerReference?->get();
        $this->record('before_warmup');
        // A controlled working set models metadata/template warmers. It is not retained after warmup.
        $workingSet = $this->warmupBytes ? str_repeat('w', $this->warmupBytes) : '';
        $this->record('during_warmup');
        unset($workingSet);

        return [];
    }

    public function record(string $phase): void
    {
        $this->phases[$phase] = [
            'used_bytes' => memory_get_usage(),
            'allocated_bytes' => memory_get_usage(true),
            'peak_bytes' => memory_get_peak_usage(true),
            'gc_runs' => gc_status()['runs'],
            'gc_collected' => gc_status()['collected'],
        ];
    }

    protected function getContainerBuilder(): ContainerBuilder
    {
        $container = parent::getContainerBuilder();
        $this->compilerReference = WeakReference::create($container);

        return $container;
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->setParameter('.container.dumper.inline_factories', true);
        for ($i = 0; $i < 128; ++$i) {
            $container->register('helper.'.$i, stdClass::class);
        }
        for ($i = 0; $i < $this->serviceCount; ++$i) {
            $container->register('bench.'.$i, MemoryBenchmarkService::class)
                ->setArguments([new Reference('helper.'.($i % 128)), (string) $i])
                ->setPublic(true);
        }
        if ($this->retainingPass) {
            $container->addCompilerPass(new class implements CompilerPassInterface {
                private ContainerBuilder $container;

                public function process(ContainerBuilder $container): void
                {
                    $this->container = $container;
                }
            });
        }
    }

    protected function dumpContainer(ConfigCache $cache, ContainerBuilder $container, string $class, string $baseClass): void
    {
        $this->record('before_dump');
        parent::dumpContainer($cache, $container, $class, $baseClass);
        $this->record('after_dump');
    }
}

function runWorker(array $options): array
{
    $count = (int) ($options['services'] ?? 5000);
    $warmupMib = (int) ($options['warmup-mib'] ?? 64);
    $scenario = $options['scenario'] ?? 'native';
    if ($count < 1 || $warmupMib < 0 || !in_array($scenario, ['native', 'retaining-pass'], true)) {
        throw new InvalidArgumentException('Invalid service count, warmup size or scenario.');
    }
    $kernel = new MemoryBenchmarkKernel($options['cache-dir'], $count, $warmupMib * 1024 * 1024, 'retaining-pass' === $scenario);
    gc_collect_cycles();
    $start = hrtime(true);
    $kernel->boot();
    $kernel->record('after_boot');
    $elapsed = (hrtime(true) - $start) / 1e6;
    $service = $kernel->getContainer()->get('bench.0');
    if (!$service instanceof MemoryBenchmarkService || '0' !== $service->key) {
        throw new RuntimeException('The generated service did not resolve correctly.');
    }
    $usage = function_exists('getrusage') ? getrusage() : [];
    $rss = isset($usage['ru_maxrss']) ? $usage['ru_maxrss'] * ('Darwin' === \PHP_OS_FAMILY ? 1 : 1024) : null;

    return [
        'php' => \PHP_VERSION,
        'os' => \PHP_OS_FAMILY,
        'opcache_cli' => ini_get('opcache.enable_cli'),
        'services' => $count,
        'warmup_mib' => $warmupMib,
        'scenario' => $scenario,
        'compiled' => null !== $kernel->compilerReference,
        'compiler_alive_at_warmup' => $kernel->compilerAliveAtWarmup,
        'elapsed_ms' => round($elapsed, 3),
        'peak_allocated_bytes' => memory_get_peak_usage(true),
        'rss_peak_bytes' => $rss,
        'phases' => $kernel->phases,
    ];
}

function runProcess(string $source, string $directory, array $options): array
{
    $command = [\PHP_BINARY, '-d', 'memory_limit=-1', '-d', 'opcache.enable_cli=0', '-d', 'opcache.jit_buffer_size=0', '-d', 'xdebug.mode=off', __FILE__, '--worker', '--source='.$source, '--cache-dir='.$directory];
    foreach (['services', 'warmup-mib', 'scenario'] as $name) {
        if (isset($options[$name])) {
            $command[] = '--'.$name.'='.$options[$name];
        }
    }
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start benchmark worker.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (0 !== proc_close($process)) {
        throw new RuntimeException('Benchmark worker failed: '.$stderr.$stdout);
    }
    if ('' !== $stderr) {
        throw new RuntimeException('Benchmark worker emitted diagnostics: '.$stderr);
    }

    return json_decode($stdout, true, flags: \JSON_THROW_ON_ERROR);
}

if (isset($options['worker'])) {
    echo json_encode(runWorker($options), \JSON_THROW_ON_ERROR)."\n";
    exit;
}

$baseline = isset($options['baseline']) ? realpath($options['baseline']) : false;
$samples = (int) ($options['samples'] ?? 5);
if (!$baseline || !is_file($baseline.'/src/Symfony/Component/DependencyInjection/Kernel/KernelTrait.php') || $samples < 1) {
    throw new InvalidArgumentException('Provide --baseline=/path/to/unpatched/symfony and a positive --samples count.');
}
$results = [];
$filesystem = new Filesystem();
for ($sample = 0; $sample < $samples; ++$sample) {
    // Alternate order to reduce systematic filesystem-cache and thermal bias.
    $variants = $sample % 2 ? ['patched' => $source, 'baseline' => $baseline] : ['baseline' => $baseline, 'patched' => $source];
    foreach ($variants as $variant => $checkout) {
        $directory = sys_get_temp_dir().'/sf-container-memory-'.bin2hex(random_bytes(8));
        try {
            $cold = runProcess($checkout, $directory, $options);
            $warm = runProcess($checkout, $directory, $options);
            if (!$cold['compiled'] || $warm['compiled']) {
                throw new RuntimeException('The cold/warm cache control did not behave as expected.');
            }
            $results[] = ['variant' => $variant, 'sample' => $sample + 1, 'cold' => $cold, 'warm' => $warm];
        } finally {
            $filesystem->remove($directory);
        }
    }
}
$output = json_encode($results, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR)."\n";
if (isset($options['output'])) {
    file_put_contents($options['output'], $output);
}
if (!isset($options['output'])) {
    echo $output;
    exit;
}
$summary = [];
foreach (['baseline', 'patched'] as $variant) {
    foreach (['cold', 'warm'] as $mode) {
        $rows = array_column(array_values(array_filter($results, static fn (array $row) => $variant === $row['variant'])), $mode);
        $median = static function (array $values): float {
            sort($values);
            $middle = intdiv(count($values), 2);

            return count($values) % 2 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
        };
        $peaks = array_column($rows, 'peak_allocated_bytes');
        $rss = array_filter(array_column($rows, 'rss_peak_bytes'), static fn ($value) => null !== $value);
        $summary[$variant][$mode] = [
            'samples' => count($rows),
            'median_peak_mib' => round($median($peaks) / 1048576, 2),
            'min_peak_mib' => round(min($peaks) / 1048576, 2),
            'max_peak_mib' => round(max($peaks) / 1048576, 2),
            'median_rss_mib' => $rss ? round($median($rss) / 1048576, 2) : null,
            'median_ms' => round($median(array_column($rows, 'elapsed_ms')), 2),
        ];
    }
}
echo json_encode($summary, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR)."\n";
