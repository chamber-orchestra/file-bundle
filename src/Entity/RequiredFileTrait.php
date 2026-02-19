<?php

declare(strict_types=1);

namespace Dev\FileBundle\Entity;

use Dev\FileBundle\Mapping\Annotation as Dev;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Serializer\Annotation\Groups;

trait RequiredFileTrait
{
    #[Dev\UploadableProperty(mappedBy: 'filePath')]
    protected File|null $file = null;
    #[ORM\Column(type: 'string', length: 255, nullable: false)]
    protected string $filePath;

    /**
     * @return File|\Dev\FileBundle\Model\File
     */
    public function getFile(): File|null
    {
        return $this->file;
    }
}