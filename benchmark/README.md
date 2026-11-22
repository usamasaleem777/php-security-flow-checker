# Finite-state benchmarks

Run the suite from the repository root with PHP 8.2+ and `proc_open` available.

```sh
php -n benchmark/run.php --seed=20261122 --repetitions=5 --output=tmp/benchmark-reproduction
php -n tests/benchmark.php
```

The runner writes `results.json` and `summary.md` to a new directory. It prints help with `--help`. Choose sizes with `--sizes=10,500,5000` and workloads with `--workloads=chain,partitioned`.

Both workloads use PHP's seeded MT19937 generator. `chain` reaches every configured state and places a false safety assertion at the end of a forward chain. `partitioned` isolates part of the graph and adds guarded shortcuts within each component. Every successful sample records the SHA-256 of its actual model input.

Each repetition launches a fresh PHP CLI worker with `-n`. The default memory limit is 128 MiB and the parent uses a 10-second wall deadline for each worker. Model generation and JSON validation precede the timed interval. Measurements include verifier observations, property checks, and witness construction. PHP allocator peaks include the loaded model. The reports include both allocator reservations and used bytes, neither of which measures OS resident memory. [PHP timing](https://www.php.net/manual/en/function.hrtime.php) · [PHP peak memory](https://www.php.net/manual/en/function.memory-get-peak-usage.php)

Timeouts and PHP memory exhaustion produce incomplete measurements with a reason. Other worker failures record errors. Only complete samples enter minimum, median and maximum calculations. The runner exits `0` for complete measurements, `2` when only resource limits prevented completion, and `3` if an error occurred. See the [recorded results](results/windows-php-8.2/summary.md) and [methodology](../docs/RESEARCH_METHODOLOGY.md).
