<?php

namespace ottimis\phplibs;

use RuntimeException;

/**
 * Lanciata da RouteController::validateRecord() quando uno o più campi non
 * passano la validazione. Porta la lista completa dei campi non validi, così
 * il ValidationMiddleware può rispondere con tutti gli errori in un colpo solo.
 *
 * Estende RuntimeException: i catch esistenti su Exception/RuntimeException
 * continuano a funzionare e il message aggrega tutti gli errori.
 */
class ValidationException extends RuntimeException
{
    /**
     * @param array<int, array{field: string, message: string}> $errors
     */
    public function __construct(private readonly array $errors)
    {
        $parts = array_map(
            static fn(array $e) => "'{$e['field']}': {$e['message']}",
            $errors
        );
        parent::__construct("Validation failed: " . implode('; ', $parts));
    }

    /**
     * @return array<int, array{field: string, message: string}>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
