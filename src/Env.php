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

    /**
     * Timezone dell'applicazione (`TIMEZONE`), condivisa tra i consumatori:
     * il timezone di sessione del DB (vedi Traits\SessionTimezone, che accetta
     * gli override DB_TIMEZONE / DB_TIMEZONE_{name}) e il default PHP
     * (applyTimezone()). Non impostata o vuota => null, ognuno tiene il proprio
     * default.
     *
     * Il valore è restituito grezzo (solo trim): i due consumatori hanno
     * vincoli di formato diversi e validano ciascuno il proprio.
     */
    public static function timezone(): ?string
    {
        $tz = trim(getenv('TIMEZONE') ?: '');

        return $tz === '' ? null : $tz;
    }

    /**
     * Imposta il timezone di default di PHP (date(), strtotime(), DateTime)
     * da `TIMEZONE`. Da chiamare esplicitamente in index.php: la libreria non
     * lo fa da sola, perché è uno stato globale del processo.
     *
     * ATTENZIONE al formato: PHP accetta SOLO gli identificatori IANA
     * ("Europe/Rome", "UTC"). Gli offset fissi ("+02:00") e "SYSTEM" — legittimi
     * per il timezone di sessione MySQL — NON sono identificatori PHP validi:
     * qui il metodo ritorna false senza toccare nulla e senza emettere warning.
     * Se il server MySQL non ha le tz tables, tenere `TIMEZONE=Europe/Rome` e
     * mettere l'offset solo su `DB_TIMEZONE`.
     *
     * @return bool true se il timezone è stato applicato
     */
    public static function applyTimezone(): bool
    {
        $tz = self::timezone();
        if ($tz === null) {
            return false;
        }

        return @date_default_timezone_set($tz);
    }
}
