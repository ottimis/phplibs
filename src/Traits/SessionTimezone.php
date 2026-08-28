<?php

namespace ottimis\phplibs\Traits;

use ottimis\phplibs\Env;
use RuntimeException;

/**
 * Timezone di sessione della connessione DB, letto da env alla connessione
 * (stesso schema di SQL_MODE, ma valido sia per MySQL che per PostgreSQL).
 *
 * Perché a livello di sessione e non "sistemando i dati": la stessa immagine
 * gira su server con default diversi (UTC in cluster, ora locale in dev), e il
 * default del server decide come vengono interpretate NOW()/CURRENT_TIMESTAMP
 * e le colonne TIMESTAMP. Fissarlo per sessione rende il comportamento
 * indipendente dal server su cui si atterra.
 *
 * Env, dalla più specifica alla più generica (la prima definita vince):
 *   1. DB_TIMEZONE_{name} — solo per la connessione named "{name}"
 *   2. DB_TIMEZONE        — override specifico del DB
 *   3. TIMEZONE           — timezone dell'applicazione (Env::timezone()),
 *                           condivisa con il default PHP (Env::applyTimezone())
 * Nessuna definita (o vuota) => nessuna SET, si tiene il default del server
 * (retrocompatibilità totale).
 *
 * Il livello DB_TIMEZONE esiste per un caso concreto: MySQL senza tz tables
 * accetta solo l'offset ('+02:00'), che però NON è un identificatore PHP
 * valido. In quel caso si tiene `TIMEZONE=Europe/Rome` per l'app e si mette
 * l'offset solo su `DB_TIMEZONE`.
 *
 * Chi usa il trait fornisce solo la sintassi del proprio dialetto
 * (timezoneSql()); la lettura da env, la validazione e la gestione errori
 * stanno qui.
 */
trait SessionTimezone
{
    /**
     * Statement per impostare il timezone di sessione nel dialetto del driver.
     * $tz è già validato da timezoneFromEnv().
     */
    abstract protected function timezoneSql(string $tz): string;

    /**
     * Formati ammessi: nome IANA (`Europe/Rome`, `Etc/GMT+3`), `UTC`, `SYSTEM`
     * (MySQL), `Local` (PostgreSQL), offset fisso (`+02:00`, `-05:00`).
     * Tutto il resto è rifiutato: il valore finisce in un literal SQL e un
     * apice/punto e virgola nell'env non deve poter uscire dalla stringa.
     */
    private const TIMEZONE_PATTERN = '/^[A-Za-z0-9_\/+:-]{1,64}$/';

    /**
     * @throws RuntimeException se l'env è valorizzata con un formato non valido
     */
    protected static function timezoneFromEnv(string $dbname): ?string
    {
        $value = $dbname === "default" || $dbname === ""
            ? getenv('DB_TIMEZONE')
            : (getenv('DB_TIMEZONE_' . $dbname) ?: getenv('DB_TIMEZONE'));

        $tz = trim((string) $value);
        if ($tz === '') {
            $tz = Env::timezone() ?? '';
        }

        if ($tz === '') {
            return null;
        }

        if (!preg_match(self::TIMEZONE_PATTERN, $tz)) {
            throw new RuntimeException(
                "Invalid DB timezone '" . $tz . "': ammessi nome IANA (Europe/Rome), UTC/SYSTEM o offset (+02:00)."
            );
        }

        return $tz;
    }

    /**
     * Esegue la SET all'apertura della connessione. Un timezone che il server
     * rifiuta è un errore fatale, non un warning: proseguire significherebbe
     * scrivere e leggere date sfasate in silenzio.
     *
     * @throws RuntimeException
     */
    protected function applySessionTimezone(string $dbname): void
    {
        $tz = static::timezoneFromEnv($dbname);
        if ($tz === null) {
            return;
        }

        try {
            $ok = $this->query($this->timezoneSql($tz));
        } catch (\Throwable $e) {
            throw new RuntimeException(self::timezoneErrorMessage($tz, $e->getMessage()), 0, $e);
        }

        if ($ok === false) {
            $error = $this->error();
            throw new RuntimeException(self::timezoneErrorMessage(
                $tz,
                is_array($error) ? implode(' ', array_map('strval', $error)) : (string) $error
            ));
        }
    }

    private static function timezoneErrorMessage(string $tz, string $error): string
    {
        return "Cannot set session timezone '" . $tz . "': " . $error
            . ". Su MySQL i nomi IANA richiedono le tz tables caricate sul server"
            . " (mysql_tzinfo_to_sql; assenti nelle immagini mysql ufficiali):"
            . " in quel caso usare un offset fisso come '+02:00'.";
    }
}
