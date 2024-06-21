<?php

declare(strict_types=1);

namespace Flowpack\NodeTemplates\Tests\Functional;

use Flowpack\NodeTemplates\Domain\NodeTemplateDumper\NodeTemplateDumper;
use Flowpack\NodeTemplates\Domain\Template\RootTemplate;
use Flowpack\NodeTemplates\Domain\TemplateConfiguration\TemplateConfigurationProcessor;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNode;
use Neos\ContentRepository\Core\Feature\RootNodeCreation\Command\CreateRootNodeAggregateWithNode;
use Neos\ContentRepository\Core\Feature\WorkspaceCreation\Command\CreateRootWorkspace;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindSubtreeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeName;
use Neos\ContentRepository\Core\SharedModel\User\UserId;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceDescription;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceTitle;
use Neos\ContentRepository\TestSuite\Behavior\Features\Bootstrap\Helpers\FakeUserIdProvider;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Neos\FrontendRouting\NodeAddressFactory;
use Neos\Neos\Ui\Domain\Model\ChangeCollection;
use Neos\Neos\Ui\Domain\Model\FeedbackCollection;
use Neos\Neos\Ui\TypeConverter\ChangeCollectionConverter;
use PHPUnit\Framework\TestCase;

abstract class AbstractNodeTemplateTestCase extends TestCase // we don't use Flows functional test case as it would reset the database afterwards
{
    use SnapshotTrait;
    use FeedbackCollectionMessagesTrait;
    use JsonSerializeNodeTreeTrait;
    use WithConfigurationTrait;
    use FakeNodeTypeManagerTrait;

    use ContentRepositoryTestTrait;

    protected Node $homePageNode;

    protected Node $homePageMainContentCollectionNode;

    private ContentSubgraphInterface $subgraph;

    private NodeTemplateDumper $nodeTemplateDumper;

    private RootTemplate $lastCreatedRootTemplate;

    private NodeTypeManager $nodeTypeManager;

    private string $fixturesDir;

    protected ObjectManagerInterface $objectManager;

    public function setUp(): void
    {
        $this->objectManager = Bootstrap::$staticObjectManager;

        $this->setupContentRepository();
        $this->nodeTemplateDumper = $this->objectManager->get(NodeTemplateDumper::class);

        $templateFactory = $this->objectManager->get(TemplateConfigurationProcessor::class);

        $templateFactoryMock = $this->getMockBuilder(TemplateConfigurationProcessor::class)->disableOriginalConstructor()->getMock();
        $templateFactoryMock->expects(self::once())->method('processTemplateConfiguration')->willReturnCallback(function (...$args) use($templateFactory) {
            $rootTemplate = $templateFactory->processTemplateConfiguration(...$args);
            $this->lastCreatedRootTemplate = $rootTemplate;
            return $rootTemplate;
        });
        $this->objectManager->setInstance(TemplateConfigurationProcessor::class, $templateFactoryMock);

        $ref = new \ReflectionClass($this);
        $this->fixturesDir = dirname($ref->getFileName()) . '/Snapshots';
    }

    public function tearDown(): void
    {
        $this->objectManager->get(FeedbackCollection::class)->reset();
        $this->objectManager->forgetInstance(TemplateConfigurationProcessor::class);
    }

    private function setupContentRepository(): void
    {
        $this->initCleanContentRepository(ContentRepositoryId::fromString('node_templates'));

        $this->nodeTypeManager = $this->contentRepository->getNodeTypeManager();
        $this->loadFakeNodeTypes();

        $liveWorkspaceCommand = CreateRootWorkspace::create(
            $workspaceName = WorkspaceName::fromString('live'),
            new WorkspaceTitle('Live'),
            new WorkspaceDescription('The live workspace'),
            ContentStreamId::fromString('cs-identifier')
        );

        $this->contentRepository->handle($liveWorkspaceCommand);

        FakeUserIdProvider::setUserId(UserId::fromString('initiating-user-identifier'));

        $rootNodeCommand = CreateRootNodeAggregateWithNode::create(
            $workspaceName,
            $sitesId = NodeAggregateId::fromString('sites'),
            NodeTypeName::fromString('Neos.Neos:Sites')
        );

        $this->contentRepository->handle($rootNodeCommand);

        $siteNodeCommand = CreateNodeAggregateWithNode::create(
            $workspaceName,
            $testSiteId = NodeAggregateId::fromString('test-site'),
            NodeTypeName::fromString('Flowpack.NodeTemplates:Document.HomePage'),
            OriginDimensionSpacePoint::fromDimensionSpacePoint(
                $dimensionSpacePoint = DimensionSpacePoint::fromArray([])
            ),
            $sitesId,
            nodeName: NodeName::fromString('test-site')
        );

        $this->contentRepository->handle($siteNodeCommand);

        $this->subgraph = $this->contentRepository->getContentGraph($workspaceName)->getSubgraph($dimensionSpacePoint, VisibilityConstraints::withoutRestrictions());

        $this->homePageNode = $this->subgraph->findNodeById($testSiteId);

        $this->homePageMainContentCollectionNode = $this->subgraph->findNodeByPath(
            NodeName::fromString('main'),
            $testSiteId
        );

        // For the case you the Neos Site is expected to return the correct site node you can use:

        // $siteRepositoryMock = $this->getMockBuilder(SiteRepository::class)->disableOriginalConstructor()->getMock();
        // $siteRepositoryMock->expects(self::once())->method('findOneByNodeName')->willReturnCallback(function (string|SiteNodeName $nodeName) use ($testSite) {
        //     $nodeName = is_string($nodeName) ? SiteNodeName::fromString($nodeName) : $nodeName;
        //     return $nodeName->toNodeName()->equals($testSite->nodeName)
        //         ? $testSite
        //         : null;
        // });

        // or

        // $testSite = new Site($testSite->nodeName->value);
        // $testSite->setSiteResourcesPackageKey('Test.Site');
        // $siteRepository = $this->objectManager->get(SiteRepository::class);
        // $siteRepository->add($testSite);
        // $this->persistenceManager->persistAll();
    }

    /**
     * @param array<string, mixed> $nodeCreationDialogValues
     */
    protected function createNodeInto(Node $targetNode, string $nodeTypeName, array $nodeCreationDialogValues): Node
    {
        $targetNodeAddress = NodeAddressFactory::create($this->contentRepository)->createFromNode($targetNode);
        $serializedTargetNodeAddress = $targetNodeAddress->serializeForUri();

        $changeCollectionSerialized = [[
            'type' => 'Neos.Neos.Ui:CreateInto',
            'subject' => $serializedTargetNodeAddress,
            'payload' => [
                'parentContextPath' => $serializedTargetNodeAddress,
                'parentDomAddress' => [
                    'contextPath' => $serializedTargetNodeAddress,
                ],
                'nodeType' => $nodeTypeName,
                'name' => 'new-node',
                'nodeAggregateId' => '186b511b-b807-6208-9e1c-593e7c1a63d3',
                'data' => $nodeCreationDialogValues,
                'baseNodeType' => '',
            ],
        ]];

        $changeCollection = (new ChangeCollectionConverter())->convert($changeCollectionSerialized, $this->contentRepositoryId);
        assert($changeCollection instanceof ChangeCollection);
        $changeCollection->apply();

        return $this->subgraph->findNodeByPath(
            NodeName::fromString('new-node'),
            $targetNode->aggregateId
        );
    }

    protected function createFakeNode(string $nodeAggregateId): Node
    {
        $this->contentRepository->handle(
            CreateNodeAggregateWithNode::create(
                $this->homePageNode->workspaceName,
                $someNodeId = NodeAggregateId::fromString($nodeAggregateId),
                NodeTypeName::fromString('unstructured'),
                $this->homePageNode->originDimensionSpacePoint,
                $this->homePageNode->aggregateId,
                nodeName: NodeName::fromString(uniqid('node-'))
            )
        )->block();

        return $this->subgraph->findNodeById($someNodeId);
    }

    protected function assertLastCreatedTemplateMatchesSnapshot(string $snapShotName): void
    {
        $lastCreatedTemplate = $this->serializeValuesInArray(
            $this->lastCreatedRootTemplate->jsonSerialize()
        );
        $this->assertJsonStringEqualsJsonFileOrCreateSnapshot($this->fixturesDir . '/' . $snapShotName . '.template.json', json_encode($lastCreatedTemplate, JSON_PRETTY_PRINT));
    }

    protected function assertCaughtExceptionsMatchesSnapshot(string $snapShotName): void
    {
        $this->assertJsonStringEqualsJsonFileOrCreateSnapshot($this->fixturesDir . '/' . $snapShotName . '.messages.json', json_encode($this->getMessagesOfFeedbackCollection(), JSON_PRETTY_PRINT));
    }

    protected function assertNoExceptionsWereCaught(): void
    {
        self::assertSame([], $this->getMessagesOfFeedbackCollection());
    }

    protected function assertNodeDumpAndTemplateDumpMatchSnapshot(string $snapShotName, Node $node): void
    {
        $serializedNodes = $this->jsonSerializeNodeAndDescendents(
            $this->subgraph->findSubtree(
                $node->aggregateId,
                FindSubtreeFilter::create(
                    nodeTypes: 'Neos.Neos:Node'
                )
            )
        );
        unset($serializedNodes['nodeTypeName']);
        $this->assertJsonStringEqualsJsonFileOrCreateSnapshot($this->fixturesDir . '/' . $snapShotName . '.nodes.json', json_encode($serializedNodes, JSON_PRETTY_PRINT));

        $dumpedYamlTemplate = $this->nodeTemplateDumper->createNodeTemplateYamlDumpFromSubtree($node, $this->contentRepository);

        $yamlTemplateWithoutOriginNodeTypeName = '\'{nodeTypeName}\'' . substr($dumpedYamlTemplate, strlen($node->nodeTypeName->value) + 2);

        $this->assertStringEqualsFileOrCreateSnapshot($this->fixturesDir . '/' . $snapShotName . '.yaml', $yamlTemplateWithoutOriginNodeTypeName);
    }
}
