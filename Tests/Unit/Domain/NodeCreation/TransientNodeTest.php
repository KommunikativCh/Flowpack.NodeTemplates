<?php

declare(strict_types=1);

namespace Flowpack\NodeTemplates\Tests\Unit\Domain\NodeCreation;

use Flowpack\NodeTemplates\Domain\NodeCreation\NodeConstraintException;
use Flowpack\NodeTemplates\Domain\NodeCreation\TransientNode;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeCreation\Dto\NodeAggregateIdsByNodePaths;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeName;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class TransientNodeTest extends TestCase
{
    private const NODE_TYPE_FIXTURES = /** @lang yaml */ <<<'YAML'
    'A:Collection.Allowed':
      constraints:
        nodeTypes:
          '*': false
          'A:Content1': true

    'A:Collection.Disallowed':
      constraints:
        nodeTypes:
          '*': false

    'A:WithDisallowedCollectionAsChildNode':
      childNodes:
        collection:
          type: 'A:Collection.Disallowed'

    'A:WithContent1AllowedCollectionAsChildNode':
      childNodes:
        collection:
          type: 'A:Collection.Allowed'

    'A:WithContent1AllowedCollectionAsChildNodeViaOverride':
      childNodes:
        collection:
          type: 'A:Collection.Disallowed'
          constraints:
            nodeTypes:
              'A:Content1': true

    'A:Content1': {}

    'A:Content2': {}

    'A:Content3': {}

    'A:ContentWithProperties':
      properties:
        property-string:
          type: string
        property-integer:
          type: integer
        property-reference:
          type: reference
        property-references:
          type: references
    YAML;

    private NodeTypeManager $nodeTypeManager;

    public function setUp(): void
    {
        parent::setUp();
        $this->nodeTypeManager = new NodeTypeManager(fn () => Yaml::parse(self::NODE_TYPE_FIXTURES));
    }

    /** @test */
    public function fromRegularAllowedChildNode(): void
    {
        $parentNode = $this->createFakeRegularTransientNode('A:Content1');
        self::assertSame($this->nodeTypeManager->getNodeType('A:Content1'), $parentNode->nodeType);
        $parentNode->requireConstraintsImposedByAncestorsToBeMet($this->nodeTypeManager->getNodeType('A:Content2'));
    }

    /** @test */
    public function forTetheredChildNodeAllowedChildNode(): void
    {
        $grandParentNode = $this->createFakeRegularTransientNode('A:WithContent1AllowedCollectionAsChildNode');

        $parentNode = $grandParentNode->forTetheredChildNode(NodeName::fromString('collection'), []);
        self::assertSame($this->nodeTypeManager->getNodeType('A:Collection.Allowed'), $parentNode->nodeType);

        $parentNode->requireConstraintsImposedByAncestorsToBeMet($this->nodeTypeManager->getNodeType('A:Content1'));
    }

    /** @test */
    public function forTetheredChildNodeAllowedChildNodeBecauseConstraintOverride(): void
    {
        $grandParentNode = $this->createFakeRegularTransientNode('A:WithContent1AllowedCollectionAsChildNodeViaOverride');

        $parentNode = $grandParentNode->forTetheredChildNode(NodeName::fromString('collection'), []);
        self::assertSame($this->nodeTypeManager->getNodeType('A:Collection.Disallowed'), $parentNode->nodeType);

        $parentNode->requireConstraintsImposedByAncestorsToBeMet($this->nodeTypeManager->getNodeType('A:Content1'));
    }

    /** @test */
    public function forRegularChildNodeAllowedChildNode(): void
    {
        $grandParentNode = $this->createFakeRegularTransientNode('A:Content1');

        $parentNode = $grandParentNode->forRegularChildNode(NodeAggregateId::fromString('child'), $this->nodeTypeManager->getNodeType('A:Content2'), []);
        self::assertSame($this->nodeTypeManager->getNodeType('A:Content2'), $parentNode->nodeType);

        $parentNode->requireConstraintsImposedByAncestorsToBeMet($this->nodeTypeManager->getNodeType('A:Content3'));
    }

    /** @test */
    public function fromRegularDisallowedChildNode(): void
    {
        $this->expectException(NodeConstraintException::class);
        $this->expectExceptionMessage('Node type "A:Content1" is not allowed for child nodes of type A:Collection.Disallowed');

        $parentNode = $this->createFakeRegularTransientNode('A:Collection.Disallowed');
        self::assertSame($this->nodeTypeManager->getNodeType('A:Collection.Disallowed'), $parentNode->nodeType);

        $parentNode->requireConstraintsImposedByAncestorsToBeMet($this->nodeTypeManager->getNodeType('A:Content1'));
    }

    /** @test */
    public function forTetheredChildNodeDisallowedChildNode(): void
    {
        $this->expectException(NodeConstraintException::class);
        $this->expectExceptionMessage('Node type "A:Content1" is not allowed below tethered child nodes "collection" of nodes of type "A:WithDisallowedCollectionAsChildNode"');

        $grandParentNode = $this->createFakeRegularTransientNode('A:WithDisallowedCollectionAsChildNode');

        $parentNode = $grandParentNode->forTetheredChildNode(NodeName::fromString('collection'), []);
        self::assertSame($this->nodeTypeManager->getNodeType('A:Collection.Disallowed'), $parentNode->nodeType);

        $parentNode->requireConstraintsImposedByAncestorsToBeMet($this->nodeTypeManager->getNodeType('A:Content1'));
    }

    /** @test */
    public function forRegularChildNodeDisallowedChildNode(): void
    {
        $this->expectException(NodeConstraintException::class);
        $this->expectExceptionMessage('Node type "A:Content1" is not allowed for child nodes of type A:Collection.Disallowed');

        $grandParentNode = $this->createFakeRegularTransientNode('A:Content2');

        $parentNode = $grandParentNode->forRegularChildNode(NodeAggregateId::fromString('child'), $this->nodeTypeManager->getNodeType('A:Collection.Disallowed'), []);
        self::assertSame($this->nodeTypeManager->getNodeType('A:Collection.Disallowed'), $parentNode->nodeType);

        $parentNode->requireConstraintsImposedByAncestorsToBeMet($this->nodeTypeManager->getNodeType('A:Content1'));
    }

    /** @test */
    public function splitPropertiesAndReferencesByTypeDeclaration(): void
    {
        $node = TransientNode::forRegular(
            NodeAggregateId::fromString('na'),
            WorkspaceName::fromString('ws'),
            OriginDimensionSpacePoint::fromArray([]),
            $this->nodeTypeManager->getNodeType('A:ContentWithProperties'),
            NodeAggregateIdsByNodePaths::createEmpty(),
            new NodeTypeManager(fn () => []),
            $this->getMockBuilder(ContentSubgraphInterface::class)->disableOriginalConstructor()->getMock(),
            [
                'property-string' => '',
                'property-integer' => '',
                'property-reference' => '',
                'property-references' => '',
                'undeclared-property' => ''
            ]
        );

        self::assertSame(
            [
                'property-string' => '',
                'property-integer' => '',
                'undeclared-property' => ''
            ],
            $node->properties
        );

        self::assertSame(
            [
                'property-reference' => '',
                'property-references' => '',
            ],
            $node->references
        );
    }

    private function createFakeRegularTransientNode(string $nodeTypeName): TransientNode
    {
        $nodeType = $this->nodeTypeManager->getNodeType($nodeTypeName);

        return TransientNode::forRegular(
            NodeAggregateId::fromString('na'),
            WorkspaceName::fromString('ws'),
            OriginDimensionSpacePoint::fromArray([]),
            $nodeType,
            NodeAggregateIdsByNodePaths::createForNodeType($nodeType->name, $this->nodeTypeManager),
            $this->nodeTypeManager,
            $this->getMockBuilder(ContentSubgraphInterface::class)->disableOriginalConstructor()->getMock(),
            []
        );
    }
}
