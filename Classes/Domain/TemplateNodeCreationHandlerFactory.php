<?php

namespace Flowpack\NodeTemplates\Domain;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\Neos\Ui\Domain\NodeCreation\NodeCreationHandlerFactoryInterface;
use Neos\Neos\Ui\Domain\NodeCreation\NodeCreationHandlerInterface;

final class TemplateNodeCreationHandlerFactory implements NodeCreationHandlerFactoryInterface
{
    public function build(ContentRepository $contentRepository): NodeCreationHandlerInterface
    {
        return new TemplateNodeCreationHandler($contentRepository);
    }
}
