# Benchmark results

The recorded run used PHP 8.2.12 with `-n` on Windows NT 10.0 AMD64, seed `20261122` and five fresh workers per case. All **80 of 80** measurements completed across 16 cases. The worker limit was 128 MiB of PHP memory and the parent deadline was 10 seconds per repetition. [Raw measurements](../benchmark/results/windows-php-8.2/results.json) · [Complete table](../benchmark/results/windows-php-8.2/summary.md)

| Workload | Configured states | Reached states | Transitions evaluated | Time min / median / max (ms) | Maximum allocator peak |
| --- | ---: | ---: | ---: | ---: | ---: |
| Chain | 1,000 | 1,000 | 1,331 | 4.664 / 4.829 / 8.326 | 6 MiB |
| Partitioned | 1,000 | 750 | 1,359 | 4.042 / 4.419 / 4.815 | 6 MiB |
| Chain | 5,000 | 5,000 | 6,639 | 25.586 / 26.964 / 27.646 | 22 MiB |
| Partitioned | 5,000 | 3,750 | 6,854 | 22.459 / 22.901 / 24.339 | 24 MiB |

The largest chain produced two findings with a shortest witness of 4,999 transitions. Its partitioned counterpart reached 3,750 states and verified all three properties. Generator size and observed reachability are recorded separately.

```sh
php -n benchmark/run.php --seed=20261122 --repetitions=5 --output=tmp/benchmark-reproduction
```

The results measure instrumented verification with model generation and parsing outside the timed interval. They include observed property cases and witness construction. Peak memory reports the PHP allocator with the loaded graph. Scheduler load, machine configuration, PHP build, and allocator behavior can change values. Compare samples only with equivalent workload and measurement settings. [Measurement procedure](../benchmark/README.md)
