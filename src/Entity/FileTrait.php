<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ChamberOrchestra\FileBundle\Entity;

use ChamberOrchestra\FileBundle\Mapping\Attribute as Dev;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;

trait FileTrait
{
    #[Dev\UploadableProperty(mappedBy: 'filePath')]
    protected ?File $file = null;
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    protected ?string $filePath = null;

    /**
     * @return File|\ChamberOrchestra\FileBundle\Model\File
     */
    public function getFile(): ?File
    {
        return $this->file;
    }
}
