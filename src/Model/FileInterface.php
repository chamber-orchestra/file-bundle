<?php

declare(strict_types=1);

namespace Dev\FileBundle\Model;

interface FileInterface
{
    public function getUri(): string|null;
}
