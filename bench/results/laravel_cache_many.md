## Laravel Cache::many() benchmark

_TOTAL_KEYS=1000, ITERATIONS=200, RUNS=5, median reported_

| client | reads/s | seconds (per 200 calls) |
| --- | ---: | ---: |
| predis (pure-PHP) | 1,275,770 | 0.157 |
| resp3 (ext-resp3) | 1,626,267 | 0.123 |

**Delta**: +27.5%
