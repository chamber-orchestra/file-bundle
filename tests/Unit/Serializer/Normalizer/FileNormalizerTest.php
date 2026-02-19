<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Serializer\Normalizer;

use ChamberOrchestra\FileBundle\Model\File;
use ChamberOrchestra\FileBundle\Serializer\Normalizer\FileNormalizer;
use PHPUnit\Framework\TestCase;

class FileNormalizerTest extends TestCase
{
    public function testNormalizeWithBaseUrl(): void
    {
        $normalizer = new FileNormalizer('https://example.com');
        $file = new File('/tmp/test.txt', '/uploads/test.txt');

        $result = $normalizer->normalize($file);

        self::assertSame('https://example.com/uploads/test.txt', $result);
    }

    public function testNormalizeWithBaseUrlTrailingSlash(): void
    {
        $normalizer = new FileNormalizer('https://example.com/');
        $file = new File('/tmp/test.txt', '/uploads/test.txt');

        $result = $normalizer->normalize($file);

        self::assertSame('https://example.com/uploads/test.txt', $result);
    }

    public function testNormalizeWithoutBaseUrl(): void
    {
        $normalizer = new FileNormalizer();
        $file = new File('/tmp/test.txt', '/uploads/test.txt');

        $result = $normalizer->normalize($file);

        self::assertSame('/uploads/test.txt', $result);
    }

    public function testSupportsNormalizationForModelFile(): void
    {
        $normalizer = new FileNormalizer();
        $file = new File('/tmp/test.txt', '/uploads/test.txt');

        self::assertTrue($normalizer->supportsNormalization($file));
    }

    public function testSupportsNormalizationForOtherTypes(): void
    {
        $normalizer = new FileNormalizer();

        self::assertFalse($normalizer->supportsNormalization(new \stdClass()));
        self::assertFalse($normalizer->supportsNormalization('string'));
    }

    public function testNormalizeWithNullUriReturnsNull(): void
    {
        $normalizer = new FileNormalizer('https://example.com');
        $file = new File('/tmp/test.txt', null);

        $result = $normalizer->normalize($file);

        self::assertNull($result);
    }

    public function testGetSupportedTypes(): void
    {
        $normalizer = new FileNormalizer();

        $types = $normalizer->getSupportedTypes(null);

        self::assertSame([File::class => true], $types);
    }

    public function testNormalizeWithAbsoluteHttpsUri(): void
    {
        $normalizer = new FileNormalizer('https://example.com');
        $file = new File('/tmp/test.txt', 'https://my-bucket.s3.amazonaws.com/test/file.txt');

        $result = $normalizer->normalize($file);

        self::assertSame('https://my-bucket.s3.amazonaws.com/test/file.txt', $result);
    }

    public function testNormalizeWithAbsoluteHttpUri(): void
    {
        $normalizer = new FileNormalizer('https://example.com');
        $file = new File('/tmp/test.txt', 'http://cdn.example.com/uploads/test.txt');

        $result = $normalizer->normalize($file);

        self::assertSame('http://cdn.example.com/uploads/test.txt', $result);
    }
}
