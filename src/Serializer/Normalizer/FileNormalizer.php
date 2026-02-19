<?php

declare(strict_types=1);

namespace Dev\FileBundle\Serializer\Normalizer;

use Dev\FileBundle\Model\File;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class FileNormalizer implements NormalizerInterface
{
    public function __construct(private RequestStack $stack)
    {
    }

    public function normalize($object, ?string $format = null, array $context = []): string|null
    {
        $base = '';
        if ($request = $this->stack->getCurrentRequest()) {
            $base = $request->getSchemeAndHttpHost();
        }

        return $base.$object?->getUri();
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof File;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            File::class => false,
        ];
    }
}