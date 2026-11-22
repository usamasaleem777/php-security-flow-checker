# Finite-state benchmark results

PHP 8.2.12 on Windows NT 10.0 (AMD64).
Seed 20261122, 5 repetitions per case, 128 MiB PHP memory limit and 10000 ms worker deadline.

| Workload | Configured | Reached | Edge evaluations | Properties evaluated | Violations | Shortest witness | Completed | Time min / median / max (ms) | Peak allocator max (MiB) |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| chain | 10 | 10 | 11 | 2 | 2 | 9 | 5/5 | 0.111 / 0.116 / 0.169 | 2.00 |
| partitioned | 10 | 7 | 10 | 3 | 0 | — | 5/5 | 0.081 / 0.093 / 0.146 | 2.00 |
| chain | 50 | 50 | 66 | 2 | 2 | 49 | 5/5 | 0.292 / 0.297 / 0.348 | 2.00 |
| partitioned | 50 | 37 | 65 | 3 | 0 | — | 5/5 | 0.235 / 0.263 / 0.701 | 2.00 |
| chain | 100 | 100 | 141 | 2 | 2 | 99 | 5/5 | 0.514 / 0.527 / 0.622 | 2.00 |
| partitioned | 100 | 75 | 136 | 3 | 0 | — | 5/5 | 0.422 / 0.429 / 0.513 | 2.00 |
| chain | 250 | 250 | 345 | 2 | 2 | 249 | 5/5 | 1.154 / 1.163 / 1.192 | 2.00 |
| partitioned | 250 | 187 | 332 | 3 | 0 | — | 5/5 | 0.951 / 0.966 / 1.414 | 2.00 |
| chain | 500 | 500 | 676 | 2 | 2 | 499 | 5/5 | 2.241 / 2.326 / 2.453 | 4.00 |
| partitioned | 500 | 375 | 686 | 3 | 0 | — | 5/5 | 2.013 / 2.163 / 2.765 | 4.00 |
| chain | 1000 | 1000 | 1331 | 2 | 2 | 999 | 5/5 | 4.664 / 4.829 / 8.326 | 6.00 |
| partitioned | 1000 | 750 | 1359 | 3 | 0 | — | 5/5 | 4.042 / 4.419 / 4.815 | 6.00 |
| chain | 2500 | 2500 | 3311 | 2 | 2 | 2499 | 5/5 | 13.215 / 13.349 / 15.933 | 12.00 |
| partitioned | 2500 | 1875 | 3399 | 3 | 0 | — | 5/5 | 10.552 / 12.119 / 14.594 | 14.00 |
| chain | 5000 | 5000 | 6639 | 2 | 2 | 4999 | 5/5 | 25.586 / 26.964 / 27.646 | 22.00 |
| partitioned | 5000 | 3750 | 6854 | 3 | 0 | — | 5/5 | 22.459 / 22.901 / 24.339 | 24.00 |

Only complete measurements enter the distributions. The JSON records every worker outcome and its reason when available.

Time covers instrumented verification and witness construction. The PHP allocator peak includes the loaded model and is not an OS RSS measurement.
