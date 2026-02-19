<?php

declare(strict_types=1);

namespace Dev\FileBundle\NamingStrategy;

use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class HashingNamingStrategy implements NamingStrategyInterface
{
    public function name(File $file): string
    {
        return \md5(($file instanceof UploadedFile ? $file->getClientOriginalName() : $file->getBasename()).\time()).'.'.$file->guessExtension();
    }
}
