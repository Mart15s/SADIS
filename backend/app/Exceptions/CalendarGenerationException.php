<?php

namespace App\Exceptions;

use RuntimeException;

class CalendarGenerationException extends RuntimeException
{
    public static function noPlants(): self
    {
        return new self('Šiam planui generuoti rekomendacinio veiksmų kalendoriaus negalima — plane nėra augalų.');
    }
}
