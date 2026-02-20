<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Exception;

use ChamberOrchestra\FileBundle\Exception\ORM\MappingException;
use ChamberOrchestra\FileBundle\Mapping\Attribute\UploadableProperty;
use ChamberOrchestra\FileBundle\NamingStrategy\NamingStrategyInterface;
use PHPUnit\Framework\TestCase;

class MappingExceptionTest extends TestCase
{
    public function testNamingStrategyIsNotValidInstanceMessage(): void
    {
        $exception = MappingException::namingStrategyIsNotValidInstance('App\\Entity\\Foo', \stdClass::class);

        self::assertStringContainsString('App\\Entity\\Foo', $exception->getMessage());
        self::assertStringContainsString(NamingStrategyInterface::class, $exception->getMessage());
    }

    public function testNoUploadedFieldsMessage(): void
    {
        $exception = MappingException::noUploadedFields('App\\Entity\\Bar');

        self::assertStringContainsString('App\\Entity\\Bar', $exception->getMessage());
        self::assertStringContainsString(UploadableProperty::class, $exception->getMessage());
    }
}
