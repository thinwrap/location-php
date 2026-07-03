<?php

declare(strict_types=1);

namespace Thinwrap\Location\Util;

/**
 * Static merge helper for the per-request `_passthrough` shape.
 *
 * Body is deep-merged (associative arrays merge recursively, scalars and lists
 * follow last-write-wins). Headers and query are shallow-merged with the user
 * winning. Mirrors the TS `mergePassthrough` utility per Architecture
 * /.
 *
 * This utility class deliberately coexists with `Thinwrap\Location\DTO\Passthrough`
 * the DTO is the typed value object consumed by facades, while this helper
 * normalizes three separate primitive buckets at the BaseConnector boundary.
 */
final class Passthrough
{
    /**
     * @param array<string,mixed> $connectorBody
     * @param array<string,string> $connectorHeaders
     * @param array<string,string|int|float|bool> $connectorQuery
     * @param array{body?:array<string,mixed>,headers?:array<string,string>,query?:array<string,string|int|float|bool>}|null $passthrough
     * @return array{body:array<string,mixed>,headers:array<string,string>,query:array<string,string|int|float|bool>}
     */
    public static function merge(
        array $connectorBody,
        array $connectorHeaders = [],
        array $connectorQuery = [],
        ?array $passthrough = null,
    ): array {
        return [
            'body'    => self::deepMerge($connectorBody, $passthrough['body'] ?? []),
            'headers' => array_merge($connectorHeaders, $passthrough['headers'] ?? []),
            'query'   => array_merge($connectorQuery, $passthrough['query'] ?? []),
        ];
    }

    /**
     * @param array<string,mixed> $target
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private static function deepMerge(array $target, array $source): array
    {
        foreach ($source as $key => $value) {
            if (
                is_array($value)
                && isset($target[$key])
                && is_array($target[$key])
                && self::isAssoc($value)
                && self::isAssoc($target[$key])
            ) {
                /** @var array<string,mixed> $sourceMap */
                $sourceMap = $value;
                /** @var array<string,mixed> $targetMap */
                $targetMap = $target[$key];
                $target[$key] = self::deepMerge($targetMap, $sourceMap);
            } else {
                $target[$key] = $value;
            }
        }

        return $target;
    }

    /**
     * @param array<int|string,mixed> $arr
     */
    private static function isAssoc(array $arr): bool
    {
        if ($arr === []) {
            return false;
        }

        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
