<?php
declare(strict_types=1);

namespace App\Core;

/** Thrown by services when input fails validation. Messages are safe to show to users. */
final class ValidationException extends \RuntimeException
{
    /** @param array<string,string> $errors field => message */
    public function __construct(public readonly array $errors, string $message = '')
    {
        parent::__construct($message !== '' ? $message : (string) (array_values($errors)[0] ?? 'Invalid input.'));
    }
}
