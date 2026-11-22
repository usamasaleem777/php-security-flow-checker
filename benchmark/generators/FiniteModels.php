<?php

declare(strict_types=1);

namespace SecurityFlow\Benchmark;

use InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class FiniteModels
{
    public const WORKLOADS = ['chain', 'partitioned'];

    /** Generate two disjoint components for the partitioned workload and a single chain otherwise. */
    public static function generate(string $workload, int $size, int $seed): array
    {
        if (!in_array($workload, self::WORKLOADS, true) || $size < 1 || $size > 5000 || $seed < 0 || $seed > 2147483647) {
            throw new InvalidArgumentException('Unsupported workload, size or seed.');
        }

        $random = new Randomizer(new Mt19937($seed));
        $reachable = $workload === 'chain' ? $size : max(1, intdiv(3 * $size, 4));
        $states = $transitions = [];
        for ($i = 0; $i < $size; ++$i) {
            $id = "s$i";
            $states[] = ['id' => $id, 'facts' => ['safe' => $i !== $size - 1]];
            $componentStart = $i < $reachable ? 0 : $reachable;
            $componentEnd = $i < $reachable ? $reachable : $size;
            if ($i + 1 < $componentEnd) {
                $transitions[] = ['from' => $id, 'to' => 's' . ($i + 1), 'action' => 'advance'];
            }
            if ($random->getInt(0, 2) === 0) {
                $back = $random->getInt($componentStart, $i);
                $transitions[] = ['from' => $id, 'to' => "s$back", 'action' => 'revisit'];
            }
            if ($workload === 'partitioned' && $i + 2 < $componentEnd && $random->getInt(0, 1) === 1) {
                $ahead = $random->getInt($i + 2, min($componentEnd - 1, $i + 8));
                $transitions[] = ['from' => $id, 'to' => "s$ahead", 'action' => 'shortcut', 'guard' => 'safe'];
            }
        }

        $properties = [
            ['id' => 'safe-state', 'scope' => 'state', 'assert' => 'safe'],
            ['id' => 'preservation', 'scope' => 'transition', 'assert' => ['implies', 'safe', ['post', 'safe']]],
        ];
        if ($workload === 'partitioned') {
            $properties[] = ['id' => 'boolean-totality', 'scope' => 'state', 'assert' => ['or', 'safe', ['not', 'safe']]];
        }
        return [
            'name' => "$workload-$size",
            'domains' => ['safe' => 'boolean'],
            'initial' => ['s0'],
            'states' => $states,
            'transitions' => $transitions,
            'properties' => $properties,
        ];
    }
}
