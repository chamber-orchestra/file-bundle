<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ChamberOrchestra\FileBundle\Model;

class File extends \Symfony\Component\HttpFoundation\File\File implements FileInterface
{
    use ImageTrait;

    public function __construct(string $path, public readonly ?string $uri = null)
    {
        parent::__construct($path, false);
    }

    public function getUri(): ?string
    {
        return $this->uri;
    }
}
