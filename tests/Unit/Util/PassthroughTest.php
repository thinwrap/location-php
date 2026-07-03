<?php

declare(strict_types=1);

namespace Thinwrap\Location\Tests\Unit\Util;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Thinwrap\Location\Util\Passthrough;

final class PassthroughTest extends TestCase
{
    #[Test]
    public function returnsConnectorInputsUnchangedWhenPassthroughIsNull(): void
    {
        $result = Passthrough::merge(
            connectorBody: ['foo' => 'bar'],
            connectorHeaders: ['X-Conn' => '1'],
            connectorQuery: ['k' => 'v'],
            passthrough: null,
        );

        $this->assertSame(['foo' => 'bar'], $result['body']);
        $this->assertSame(['X-Conn' => '1'], $result['headers']);
        $this->assertSame(['k' => 'v'], $result['query']);
    }

    #[Test]
    public function returnsConnectorInputsUnchangedWhenPassthroughIsEmpty(): void
    {
        $result = Passthrough::merge(
            connectorBody: ['foo' => 'bar'],
            passthrough: [],
        );

        $this->assertSame(['foo' => 'bar'], $result['body']);
        $this->assertSame([], $result['headers']);
        $this->assertSame([], $result['query']);
    }

    #[Test]
    public function headersAreShallowMergedLastWriteWins(): void
    {
        $result = Passthrough::merge(
            connectorBody: [],
            connectorHeaders: ['X-Foo' => 'a', 'X-Bar' => 'b'],
            passthrough: ['headers' => ['X-Foo' => 'override', 'X-Extra' => 'added']],
        );

        $this->assertSame(
            ['X-Foo' => 'override', 'X-Bar' => 'b', 'X-Extra' => 'added'],
            $result['headers'],
        );
    }

    #[Test]
    public function queryParamsAreShallowMergedLastWriteWins(): void
    {
        $result = Passthrough::merge(
            connectorBody: [],
            connectorQuery: ['limit' => '10', 'offset' => '0'],
            passthrough: ['query' => ['limit' => '50', 'fields' => 'id,name']],
        );

        $this->assertSame(
            ['limit' => '50', 'offset' => '0', 'fields' => 'id,name'],
            $result['query'],
        );
    }

    #[Test]
    public function bodyIsDeepMergedForNestedAssociativeMaps(): void
    {
        $result = Passthrough::merge(
            connectorBody: [
                'options' => [
                    'language' => 'en',
                    'units' => 'metric',
                ],
                'count' => 5,
            ],
            passthrough: [
                'body' => [
                    'options' => [
                        'language' => 'fr',
                        'region' => 'EU',
                    ],
                ],
            ],
        );

        $this->assertSame(
            [
                'options' => [
                    'language' => 'fr',
                    'units' => 'metric',
                    'region' => 'EU',
                ],
                'count' => 5,
            ],
            $result['body'],
        );
    }

    #[Test]
    public function listsInBodyAreReplacedLastWriteWinsNotConcatenated(): void
    {
        $result = Passthrough::merge(
            connectorBody: ['waypoints' => [1, 2, 3]],
            passthrough: ['body' => ['waypoints' => [4, 5]]],
        );

        $this->assertSame(['waypoints' => [4, 5]], $result['body']);
    }

    #[Test]
    public function scalarConflictInBodyIsReplacedNotMerged(): void
    {
        $result = Passthrough::merge(
            connectorBody: ['flag' => false, 'count' => 1],
            passthrough: ['body' => ['flag' => true]],
        );

        $this->assertSame(['flag' => true, 'count' => 1], $result['body']);
    }

    #[Test]
    public function passthroughAddsKeysAbsentFromConnectorBody(): void
    {
        $result = Passthrough::merge(
            connectorBody: ['a' => 1],
            passthrough: ['body' => ['b' => 2]],
        );

        $this->assertSame(['a' => 1, 'b' => 2], $result['body']);
    }

    #[Test]
    public function deeplyNestedAssociativeStructuresMergeRecursively(): void
    {
        $result = Passthrough::merge(
            connectorBody: [
                'level1' => [
                    'level2' => [
                        'level3' => ['keep' => 'connector'],
                    ],
                ],
            ],
            passthrough: [
                'body' => [
                    'level1' => [
                        'level2' => [
                            'level3' => ['add' => 'user'],
                        ],
                    ],
                ],
            ],
        );

        $this->assertSame(
            [
                'level1' => [
                    'level2' => [
                        'level3' => [
                            'keep' => 'connector',
                            'add' => 'user',
                        ],
                    ],
                ],
            ],
            $result['body'],
        );
    }

    #[Test]
    public function binaryStringsPassThroughUntouched(): void
    {
        $binary = "\x00\x01\x02BINARY";
        $result = Passthrough::merge(
            connectorBody: ['payload' => $binary],
            passthrough: null,
        );

        $this->assertSame($binary, $result['body']['payload']);
    }
}
