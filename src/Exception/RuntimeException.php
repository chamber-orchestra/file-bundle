<?php

declare(strict_types=1);

namespace Dev\FileBundle\Exception;

use Dev\MetadataBundle\Exception\ExceptionInterface;

class RuntimeException extends \RuntimeException implements ExceptionInterface
{
}
