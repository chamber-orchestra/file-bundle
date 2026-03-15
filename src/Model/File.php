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

    public function __construct(
        string $path,
        public readonly ?string $uri = null,
    ) {
        parent::__construct($path, false);
    }

    public function getUri(): ?string
    {
        return $this->uri;
    }

    #[\Override]
    public function isFile(): bool
    {
        if (parent::isFile()) {
            return true;
        }

        return null !== $this->uri;
    }

    #[\Override]
    public function getMimeType(): ?string
    {
        if (parent::isFile()) {
            return parent::getMimeType();
        }

        if (null !== $this->uri) {
            $ext = \pathinfo($this->getPathname(), \PATHINFO_EXTENSION);

            return match (\strtolower($ext)) {
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                'avif' => 'image/avif',
                'svg' => 'image/svg+xml',
                'pdf' => 'application/pdf',
                'mp4' => 'video/mp4',
                'mov' => 'video/quicktime',
                default => null,
            };
        }

        return null;
    }
}
