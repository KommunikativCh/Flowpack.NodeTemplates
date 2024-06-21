<?php

namespace Flowpack\NodeTemplates\Domain;

use Flowpack\NodeTemplates\Domain\ErrorHandling\ProcessingErrors;
use Flowpack\NodeTemplates\Domain\ErrorHandling\ProcessingErrorHandler;
use Flowpack\NodeTemplates\Domain\NodeCreation\NodeCreationService;
use Flowpack\NodeTemplates\Domain\TemplateConfiguration\TemplateConfigurationProcessor;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Ui\Domain\NodeCreation\NodeCreationCommands;
use Neos\Neos\Ui\Domain\NodeCreation\NodeCreationElements;
use Neos\Neos\Ui\Domain\NodeCreation\NodeCreationHandlerInterface;

class TemplateNodeCreationHandler implements NodeCreationHandlerInterface
{
    /**
     * @var NodeCreationService
     * @Flow\Inject
     */
    protected $nodeCreationService;

    /**
     * @var TemplateConfigurationProcessor
     * @Flow\Inject
     */
    protected $templateConfigurationProcessor;

    /**
     * @var ProcessingErrorHandler
     * @Flow\Inject
     */
    protected $processingErrorHandler;

    public function __construct(private readonly ContentRepository $contentRepository)
    {
    }

    /**
     * Create child nodes and change properties upon node creation
     */
    public function handle(
        NodeCreationCommands $commands,
        NodeCreationElements $elements
    ): NodeCreationCommands {
        $nodeType = $this->contentRepository->getNodeTypeManager()
            ->getNodeType($commands->first->nodeTypeName);

        if (!$nodeType) {
            throw new \RuntimeException(sprintf('Initial NodeType "%s" does not exist anymore.', $commands->first->nodeTypeName->value), 1718950358);
        }

        $templateConfiguration = $nodeType->getOptions()['template'] ?? null;
        if (!$templateConfiguration) {
            return $commands;
        }

        $subgraph = $this->contentRepository->getContentGraph($commands->first->workspaceName)->getSubgraph(
            $commands->first->originDimensionSpacePoint->toDimensionSpacePoint(),
            VisibilityConstraints::frontend()
        );

        $evaluationContext = [
            // todo internal and legacy
            'data' => iterator_to_array($elements->serialized()),
            // todo evaluate which context variables
            'parentNode' => $subgraph->findNodeById($commands->first->parentNodeAggregateId),
            'subgraph' => $subgraph
        ];

        $processingErrors = ProcessingErrors::create();
        $template = $this->templateConfigurationProcessor->processTemplateConfiguration($templateConfiguration, $evaluationContext, $processingErrors);
        $shouldContinue = $this->processingErrorHandler->handleAfterTemplateConfigurationProcessing($processingErrors, $nodeType, $commands->first->nodeAggregateId);

        if (!$shouldContinue) {
            return $commands;
        }

        $additionalCommands = $this->nodeCreationService->apply($template, $commands, $this->contentRepository->getNodeTypeManager(), $subgraph, $nodeType, $processingErrors);
        $shouldContinue = $this->processingErrorHandler->handleAfterNodeCreation($processingErrors, $nodeType, $commands->first->nodeAggregateId);

        if (!$shouldContinue) {
            return $commands;
        }

        return $additionalCommands;
    }
}
