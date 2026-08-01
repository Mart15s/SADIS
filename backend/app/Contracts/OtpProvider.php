<?php

namespace App\Contracts;

interface OtpProvider
{
    public function send(string $phone, string $code, string $purpose): void;
}
