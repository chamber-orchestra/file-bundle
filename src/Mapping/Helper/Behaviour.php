<?php

declare(strict_types=1);

namespace Dev\FileBundle\Mapping\Helper;

enum Behaviour: string
{
    public const KEEP = 'KEEP';
    public const REMOVE = 'REMOVE';
}
