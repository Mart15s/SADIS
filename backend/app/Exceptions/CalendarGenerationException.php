<?php

namespace App\Exceptions;

use RuntimeException;

class CalendarGenerationException extends RuntimeException
{
    public static function noPlants(): self
    {
        return new self('A recommendation calendar cannot be generated for this plan because it has no plants.');
    }
}
