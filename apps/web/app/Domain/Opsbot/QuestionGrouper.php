<?php

declare(strict_types=1);

namespace App\Domain\Opsbot;

use Illuminate\Support\Facades\DB;

final class QuestionGrouper
{
    public const float THRESHOLD = 0.6;

    /**
     * @param  list<array{id: int, normalized: string, text: string, at: string}>  $items
     * @return list<array{representative: string, count: int, ids: list<int>, last_asked_at: string}>
     */
    public function group(array $items): array
    {
        $items = array_values(array_filter($items, fn (array $item): bool => trim($item['normalized']) !== ''));

        usort($items, fn (array $a, array $b): int => [$a['normalized'], $a['id']] <=> [$b['normalized'], $b['id']]);

        $similar = $this->similarPairs(array_values(array_unique(array_column($items, 'normalized'))));
        $groups = [];

        foreach ($items as $item) {
            $placed = false;

            foreach ($groups as $index => $group) {
                $rep = $group['normalized'];

                if ($rep === $item['normalized'] || isset($similar[$rep][$item['normalized']])) {
                    $groups[$index]['ids'][] = $item['id'];
                    $groups[$index]['count']++;
                    $groups[$index]['last_asked_at'] = max($groups[$index]['last_asked_at'], $item['at']);
                    $placed = true;

                    break;
                }
            }

            if (! $placed) {
                $groups[] = [
                    'normalized' => $item['normalized'],
                    'representative' => $item['text'],
                    'count' => 1,
                    'ids' => [$item['id']],
                    'last_asked_at' => $item['at'],
                ];
            }
        }

        usort($groups, fn (array $a, array $b): int => [$b['count'], $a['representative']] <=> [$a['count'], $b['representative']]);

        return array_map(fn (array $group): array => [
            'representative' => $group['representative'],
            'count' => $group['count'],
            'ids' => $group['ids'],
            'last_asked_at' => $group['last_asked_at'],
        ], $groups);
    }

    /**
     * @param  list<string>  $texts
     * @return array<string, array<string, true>>
     */
    private function similarPairs(array $texts): array
    {
        if (count($texts) < 2) {
            return [];
        }

        $rows = DB::select(
            'SELECT a.t AS a, b.t AS b
             FROM unnest(?::text[]) AS a(t)
             JOIN unnest(?::text[]) AS b(t) ON a.t < b.t AND similarity(a.t, b.t) >= ?',
            [self::pgArray($texts), self::pgArray($texts), self::THRESHOLD],
        );

        $pairs = [];

        foreach ($rows as $row) {
            $pairs[(string) $row->a][(string) $row->b] = true;
            $pairs[(string) $row->b][(string) $row->a] = true;
        }

        return $pairs;
    }

    /** @param  list<string>  $values */
    private static function pgArray(array $values): string
    {
        return '{'.implode(',', array_map(
            fn (string $value): string => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"',
            $values,
        )).'}';
    }
}
