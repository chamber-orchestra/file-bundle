<?php

declare(strict_types=1);

namespace Dev\FileBundle\Model;

use Dev\FileBundle\Exception\RuntimeException;

/**
 * @mixin File
 */
trait ImageTrait
{
    private ?array $imageSize = null;
    private ?array $metadata = null;

    public function isImage(): bool
    {
        if (!$this->isFile()) {
            return false;
        }

        return \str_contains($this->getMimeType(), 'image/');
    }

    /**
     * @return array
     */
    public function getImageSize(): array
    {
        if (!$this->isImage()) {
            throw new RuntimeException('File is not an image');
        }

        if (null === $this->imageSize) {
            $size = @\getimagesize($this->getRealPath());
            if (empty($size) || (0 === $size[0]) || (0 === $size[1])) {
                throw new RuntimeException("Can't get image sizes");
            }
            $this->imageSize = $size;
        }

        return $this->imageSize;
    }

    /**
     * @return int
     */
    public function getWidth(): int
    {
        return $this->getImageSize()[0];
    }

    /**
     * @return int
     */
    public function getHeight(): int
    {
        return $this->getImageSize()[1];
    }

    /**
     * @return float
     */
    public function getRatio(): float
    {
        return (float) $this->getWidth() / $this->getHeight();
    }

    /**
     * @return int
     */
    public function getOrientation(): int
    {
        return $this->getMetadata()['Orientation'] ?? 0;
    }

    public function getMetadata(): array
    {
        if (!$this->isImage()) {
            throw new RuntimeException('File is not an image');
        }

        if (null === $this->metadata) {
            $data = [];
            if (\function_exists('exif_read_data')) {
                $data = @\exif_read_data($this->getRealPath());
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

    private function isFlipped(int $orientation): bool
    {
        return match ($orientation) {
            2, 4, 5, 7 => true,
            default => false,
        };
    }
}
