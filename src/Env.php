<?php

namespace ottimis\phplibs;

/**
 * Accessor unico per il nome dell'ambiente.
 *
 * ENV è la variabile canonica, ENVIRONMENT il fallback per i progetti storici:
 * la prima definita vince (?:), quindi un valore esplicito su ENV è la risposta
 * e un'eventuale divergenza tra le due non è semanticamente significativa.
 *
 * Nessuna delle due definita => name() = "" => non-produzione e non-local:
 * è il degrado permissivo storico della libreria, scelto deliberatamente
 * (le superfici di disclosure sono comunque dietro flag espliciti, vedi flag()).
 */
final class Env
{
    public static function name(): string
    {
        return strtolower(trim(getenv('ENV') ?: getenv('ENVIRONMENT') ?: ''));
    }

    public static function is(string ...$names): bool
    {
        return in_array(self::name(), array_map('strtolower', $names), true);
    }

    public static function isProduction(): bool
    {
        return self::is('production', 'prod');
    }

    public static function isLocal(): bool
    {
        return self::is('local');
    }

    /**
     * Flag booleana esplicita, default spento: solo "true"/"1"/"on"/"yes"
     * (case-insensitive) attivano.
     */
    public static function flag(string $name): bool
    {
        return in_array(strtolower(trim(getenv($name) ?: '')), ['true', '1', 'on', 'yes'], true);
    }
}
