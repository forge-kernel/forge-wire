<?php

declare(strict_types=1);

namespace Modules\ForgeWire\Exceptions;

final class ValidationException extends \Exception
{
    public function __construct(public array $errors)
    {
        parent::__construct("Validation failed");
    }
}