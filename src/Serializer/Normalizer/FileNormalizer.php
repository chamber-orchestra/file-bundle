<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ChamberOrchestra\FileBundle\Serializer\Normalizer;

use ChamberOrchestra\FileBundle\Model\File;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class FileNormalizer implements NormalizerInterface
{
    public function __construct(
        private readonly string $baseUrl = '',
    ) {
    }

    public function normalize($object, ?string $format = null, array $context = []): ?string
    {
        /** @var File $object */
        $uri = $object->getUri();

        if (null === $uri) {
            return null;
        }

        if (\str_starts_with($uri, 'http://') || \str_starts_with($uri, 'https://')) {
            return $uri;
        }

        return \rtrim($this->baseUrl, '/').$uri;
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof File;
    }

    /**
     * @return array<string, bool>
     */
    public function getSupportedTypes(?string $format): array
    {
        return [
            File::class => true,
        ];
    }
}
