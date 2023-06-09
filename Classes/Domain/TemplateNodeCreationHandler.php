<?php

namespace Flowpack\NodeTemplates\Domain;

use Flowpack\NodeTemplates\Domain\ExceptionHandling\CaughtExceptions;
use Flowpack\NodeTemplates\Domain\ExceptionHandling\ExceptionHandler;
use Flowpack\NodeTemplates\Domain\ExceptionHandling\TemplateNotCreatedException;
use Flowpack\NodeTemplates\Domain\ExceptionHandling\TemplatePartiallyCreatedException;
use Flowpack\NodeTemplates\Domain\NodeCreation\NodeCreationService;
use Flowpack\NodeTemplates\Domain\TemplateConfiguration\TemplateConfigurationProcessor;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Ui\NodeCreationHandler\NodeCreationCommands;
use Neos\Neos\Ui\NodeCreationHandler\NodeCreationHandlerInterface;

class TemplateNodeCreationHandler implements NodeCreationHandlerInterface
{
    /**
     * @var TemplateConfigurationProcessor
     * @Flow\Inject
     */
    protected $templateConfigurationProcessor;

    /**
     * @var ExceptionHandler
     * @Flow\Inject
     */
    protected $exceptionHandler;

    /**
     * Create child nodes and change properties upon node creation
     *
     * @param array $data incoming data from the creationDialog
     */
    public function handle(
        NodeCreationCommands $commands,
        array $data,
        ContentRepository $contentRepository
    ): NodeCreationCommands {
        $nodeType = $contentRepository->getNodeTypeManager()
            ->getNodeType($commands->initialCreateCommand->nodeTypeName);
        $templateConfiguration = $nodeType->getOptions()['template'] ?? null;
        if (!$templateConfiguration) {
            return $commands;
        }

        $evaluationContext = [
            'data' => $data,
            // todo evaluate which context variables
            'subgraph' => $contentRepository->getContentGraph()->getSubgraph(
                $commands->initialCreateCommand->contentStreamId,
                $commands->initialCreateCommand->originDimensionSpacePoint->toDimensionSpacePoint(),
                VisibilityConstraints::frontend()
            ),
        ];

        $caughtExceptions = CaughtExceptions::create();
        try {
            $template = $this->templateConfigurationProcessor->processTemplateConfiguration($templateConfiguration, $evaluationContext, $caughtExceptions);
            // $this->exceptionHandler->handleAfterTemplateConfigurationProcessing($caughtExceptions, $node);

            return (new NodeCreationService($contentRepository, $contentRepository->getNodeTypeManager()))->apply($template, $commands, $caughtExceptions);
            // $this->exceptionHandler->handleAfterNodeCreation($caughtExceptions, $node);
        } catch (TemplateNotCreatedException|TemplatePartiallyCreatedException $templateCreationException) {
            throw $templateCreationException;
        }
    }
}
