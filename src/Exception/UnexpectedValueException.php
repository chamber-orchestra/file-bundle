<?php

declare(strict_types=1);

namespace Dev\FileBundle\Exception;

use Dev\MetadataBundle\Exception\ExceptionInterface;

class UnexpectedValueException extends \UnexpectedValueException implements ExceptionInterface
{
}
