<?php

declare(strict_types=1);

namespace Rector\Naming\Rector\FileWithoutNamespace;

use PhpParser\Node\Stmt;

interface FileWithNodesInterface
{
    /**
     * @return Stmt[]
     */
    public function getNodes(): array;
}
