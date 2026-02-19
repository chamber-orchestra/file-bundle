<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ChamberOrchestra\FileBundle\Model;

use ChamberOrchestra\FileBundle\Exception\RuntimeException;

/**
 * @mixin File
 */
trait ImageTrait
{
    /** @var array<int|string, mixed>|null */
    private ?array $imageSize = null;
    /** @var array<string, mixed>|null */
    private ?array $metadata = null;

    public function isImage(): bool
    {
        if (!$this->isFile()) {
            return false;
        }

        $mimeType = $this->getMimeType();

        return null !== $mimeType && \str_contains($mimeType, 'image/');
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getImageSize(): array
    {
        if (!$this->isImage()) {
            throw new RuntimeException('File is not an image');
        }

        if (null === $this->imageSize) {
            $size = @\getimagesize($this->getRealPath());
            if (false === $size || 0 === $size[0] || 0 === $size[1]) {
                throw new RuntimeException("Can't get image sizes");
            }
            $this->imageSize = $size;
        }

        return $this->imageSize;
    }

    public function getWidth(): int
    {
        $size = $this->getImageSize();
        /** @var int $width */
        $width = $size[0];

        return $width;
    }

    public function getHeight(): int
    {
        $size = $this->getImageSize();
        /** @var int $height */
        $height = $size[1];

        return $height;
    }

    public function getRatio(): float
    {
        return (float) $this->getWidth() / $this->getHeight();
    }

    public function getOrientation(): int
    {
        $metadata = $this->getMetadata();
        $orientation = $metadata['Orientation'] ?? 0;

        return \is_int($orientation) ? $orientation : 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        if (!$this->isImage()) {
            throw new RuntimeException('File is not an image');
        }

        if (null === $this->metadata) {
            /** @var array<string, mixed> $data */
            $data = [];
            if (\function_exists('exif_read_data')) {
                /** @var array<string, mixed>|false $exif */
                $exif = @\exif_read_data($this->getRealPath());
                $data = false !== $exif ? $exif : [];
            }
            $this->metadata = $data;
        }

        return $this->metadata;
    }

    public function getOrientationAngle(): int
    {
        $orientation = $this->getOrientation();
        if ($orientation < 1 || $orientation > 8) {
            return 0;
        }

        return $this->calculateRotation($orientation);
    }

    /**
     * calculates to rotation degree from the EXIF Orientation.
     */
    private function calculateRotation(int $orientation): int
    {
        return match ($orientation) {
            3, 4 => 180,
            5, 6 => 90,
            7, 8 => -90,
            default => 0,
        };
    }
}
