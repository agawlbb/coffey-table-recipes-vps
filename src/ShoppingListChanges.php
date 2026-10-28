<?php
declare(strict_types=1);

/** Merge only user-edited fields; retry time must never make an old edit newer. */
final class ShoppingListChanges
{
    public static function newer(array $stamp, ?array $previous): bool
    {
        return $previous === null || $stamp[0] > $previous[0]
            || ($stamp[0] === $previous[0] && strcmp($stamp[1], $previous[1]) > 0);
    }

    public static function merge(array $current, array $base, array $wanted, array $sync, array $stamp): array
    {
        $before = array_column($base, null, 'id');
        $after = array_column($wanted, null, 'id');
        $items = array_column($current, null, 'id');
        $order = array_column($current, 'id');
        $fields = $sync['fields'] ?? [];
        $deleted = $sync['deleted'] ?? [];
        foreach ($before as $id => $_) {
            $id=(string)$id;
            if (isset($after[$id])) continue;
            $latest = $deleted[$id] ?? null;
            foreach ($fields[$id] ?? [] as $version) {
                if (self::newer($version, $latest)) $latest = $version;
            }
            if (self::newer($stamp, $latest)) {
                unset($items[$id]);
                $deleted[$id] = $stamp;
            }
        }
        $baseOrder = array_values(array_filter(array_column($base, 'id'), fn($id) => isset($after[$id])));
        $wantedOrder = array_values(array_filter(array_column($wanted, 'id'), fn($id) => isset($before[$id])));
        foreach ($after as $id => $item) {
            $id=(string)$id;
            if (!self::newer($stamp, $deleted[$id] ?? null)) continue;
            $original = $before[$id] ?? null;
            foreach (['name', 'note', 'department', 'checked'] as $field) {
                // An omitted note in an older client is not an explicit clear.
                if ($field === 'note' && !array_key_exists($field, $item)) continue;
                if ($original !== null && ($item[$field] ?? '') === ($original[$field] ?? '')) continue;
                if (!self::newer($stamp, $fields[$id][$field] ?? null)) continue;
                if (!isset($items[$id])) $items[$id] = $item;
                $items[$id][$field] = $item[$field];
                $fields[$id][$field] = $stamp;
            }
            $moved = $original === null || array_search($id, $baseOrder, true) !== array_search($id, $wantedOrder, true);
            if ($moved && isset($items[$id]) && self::newer($stamp, $fields[$id]['position'] ?? null)) {
                $order = array_values(array_filter($order, fn($other) => $other !== $id));
                $position = array_search($id, array_column($wanted, 'id'), true);
                $anchor = $position > 0 ? $wanted[$position - 1]['id'] : null;
                $at = $anchor === null ? 0 : array_search($anchor, $order, true);
                array_splice($order, $at === false ? count($order) : ($anchor === null ? 0 : $at + 1), 0, [$id]);
                $fields[$id]['position'] = $stamp;
            }
        }
        $result = [];
        foreach ($order as $id) if (isset($items[$id])) $result[] = $items[$id];
        return [$result, ['fields' => $fields, 'deleted' => $deleted] + $sync];
    }
}
