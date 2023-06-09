<?php

namespace Flowpack\NodeTemplates\Tests\Unit\Domain\NodeCreation;

use Flowpack\NodeTemplates\Domain\NodeCreation\ReferenceType;
use Flowpack\NodeTemplates\Tests\Unit\NodeMockTrait;
use GuzzleHttp\Psr7\Uri;
use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\Flow\ResourceManagement\PersistentResource;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Model\Image;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

class ReferenceTypeTest extends TestCase
{
    use NodeMockTrait;

    private const VALID_NODE_ID_1 = '123';
    private const VALID_NODE_ID_2 = '456';

    /**
     * @dataProvider declarationAndValueProvider
     */
    public function testIsMatchedBy(string $declarationType, array $validValues, array $invalidValues): void
    {
        // subgraph that knows the nodes 123 and 456
        $subgraphMock = $this->getMockBuilder(ContentSubgraphInterface::class)->getMock();
        $subgraphMock->expects(self::any())->method('findNodeById')->willReturnCallback(function (NodeAggregateId $nodeId) {
            if ($nodeId->value === self::VALID_NODE_ID_1 || $nodeId->value === self::VALID_NODE_ID_2) {
                return $this->createNodeMock();
            }
            return null;
        });
        $nodeTypeMock = $this->getMockBuilder(NodeType::class)->disableOriginalConstructor()->getMock();
        $nodeTypeMock->expects(self::once())->method('getPropertyType')->with('test')->willReturn($declarationType);
        $subject = ReferenceType::fromPropertyOfNodeType(
            'test',
            $nodeTypeMock,
        );
        foreach ($validValues as $validValue) {
            Assert::assertTrue($subject->isMatchedBy($validValue, $subgraphMock), sprintf('Value %s should match.', get_debug_type($validValue)));
        }
        foreach ($invalidValues as $invalidValue) {
            Assert::assertFalse($subject->isMatchedBy($invalidValue, $subgraphMock), sprintf('Value %s should not match.', get_debug_type($validValue)));
        }
    }

    public function declarationAndValueProvider(): array
    {
        $int = 13;
        $float = 4.2;
        $string = 'It\'s a graph!';
        $stringArray = [$string];
        $image = new Image(new PersistentResource());
        $asset = new Asset(new PersistentResource());
        $date = \DateTimeImmutable::createFromFormat(\DateTimeInterface::W3C, '2020-08-20T18:56:15+00:00');
        $uri = new Uri('https://www.neos.io');

        $nodeMock1 = $this->createNodeMock();
        $nodeMock2 = $this->createNodeMock();

        return [
            [
                'reference',
                [null, $nodeMock1, $nodeMock2, self::VALID_NODE_ID_1, self::VALID_NODE_ID_2],
                [0, $int, 0.0, $float, '', $string, [], $stringArray, $date, $uri, $image, $asset, [$asset]]
            ],
            [
                'references',
                [[], null, [self::VALID_NODE_ID_1], [$nodeMock1], [self::VALID_NODE_ID_2, $nodeMock2]],
                [true, $float, $string, $stringArray, $date, $uri, $image, $asset, [$asset]]
            ],
        ];
    }
}
