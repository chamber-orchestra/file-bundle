<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ChamberOrchestra\FileBundle\Mapping\Helper;

enum Behaviour: int
{
    case Keep = 0;
    case Remove = 1;
    case Archive = 2;
}
