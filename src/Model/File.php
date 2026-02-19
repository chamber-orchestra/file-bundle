<?php

declare(strict_types=1);

namespace Dev\FileBundle\Model;

class File extends \Symfony\Component\HttpFoundation\File\File implements FileInterface
{
    use ImageTrait;

    public function __construct(string $path, public readonly string|null $uri = null)
    {
        parent::__construct($path, false);
    }

    public function getUri(): string
    {
        return $this->uri;
    }
}
