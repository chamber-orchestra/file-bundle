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

trait RequiredImageTrait
{
    #[Dev\UploadableProperty(mappedBy: 'imagePath')]
    protected ?File $image = null;
    #[ORM\Column(type: 'string', length: 255, nullable: false)]
    protected string $imagePath = '';

    /**
     * @return File|\ChamberOrchestra\FileBundle\Model\File
     */
    public function getImage(): ?File
    {
        return $this->image;
    }
}
