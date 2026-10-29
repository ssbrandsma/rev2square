<?php

namespace App\Services\Binary\Adapters;

/** Adapter registry: one place listing every deterministic backend. */
final class AdapterRegistry
{
    /** @return list<BinaryAdapter> */
    public static function all(): array
    {
        return [new PhpNativeAdapter, new GhidraAdapter, new JadxAdapter, new IlspyAdapter];
    }

    public static function get(string $id): ?BinaryAdapter
    {
        foreach (self::all() as $adapter) {
            if ($adapter->id() === $id) {
                return $adapter;
            }
        }

        return null;
    }
}
