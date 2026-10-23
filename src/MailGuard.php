<?php

namespace ottimis\phplibs;

/**
 * Punto di passaggio di ogni mail fuori produzione: i DB di staging/locale
 * contengono le email vere dei clienti, che non devono ricevere nulla.
 *
 * - Produzione (Env::isProduction()): destinatari e oggetto invariati, MAIL_REDIRECT_TO ignorata.
 * - Fuori produzione con MAIL_REDIRECT_TO (indirizzi separati da virgola): la mail va SOLO
 *   a quegli indirizzi; To/Cc/Bcc originali tolti e riportati nell'oggetto
 *   ("[TEST → a@x.com, …] …"), in un riquadro in testa al corpo e nell'header
 *   X-Original-To. Reply-To, immagini e allegati non passano di qui e restano invariati.
 * - Fuori produzione senza redirect valido: fail-closed, nessun invio, una riga di log;
 *   il chiamante tratta l'invio come riuscito.
 * - MAIL_GUARD_DISABLED=true: si comporta come la produzione anche fuori produzione.
 *   È una scelta consapevole del progetto (es. staging che deve davvero inviare).
 *
 * Ambiente non impostato (né ENV né ENVIRONMENT) = non produzione = guard attiva.
 *
 * OGMail la applica in ogni strada di invio. Un mailer di progetto che non passa da OGMail
 * (es. Microsoft Graph) deve chiamare route() e usare htmlBanner()/textBanner()/headers().
 */
final class MailGuard
{
    /** Quanti destinatari originali entrano nell'oggetto, il resto è "+N". */
    private const int SUBJECT_MAX_ADDRESSES = 3;

    /** @var (callable(string, array): void)|null */
    private static $logger = null;

    /**
     * @param string[] $to
     * @param string[] $cc
     * @param string[] $bcc
     * @param string $channel canale di invio, solo per il log (es. "smtp", "ses", "graph")
     * @return array{to:string[],cc:string[],bcc:string[],subject:string,redirected:bool,original:string[]}|null
     *         null = non inviare (fuori produzione senza MAIL_REDIRECT_TO valida)
     */
    public static function route(array $to, array $cc, array $bcc, string $subject, string $channel): ?array
    {
        $to = self::clean($to);
        $cc = self::clean($cc);
        $bcc = self::clean($bcc);

        if (Env::isProduction() || Env::flag('MAIL_GUARD_DISABLED')) {
            return ['to' => $to, 'cc' => $cc, 'bcc' => $bcc, 'subject' => $subject, 'redirected' => false, 'original' => []];
        }

        $original = array_values(array_unique(array_merge($to, $cc, $bcc)));
        $redirect = self::redirectTargets();
        if ($redirect === []) {
            self::log(
                "MailGuard: invio $channel bloccato fuori produzione (MAIL_REDIRECT_TO non impostata o non valida)",
                ['env' => Env::name(), 'to' => $original, 'subject' => $subject, 'channel' => $channel]
            );
            return null;
        }

        return [
            'to' => $redirect,
            'cc' => [],
            'bcc' => [],
            'subject' => self::subjectPrefix($original) . ' ' . $subject,
            'redirected' => true,
            'original' => $original,
        ];
    }

    /** Riquadro HTML da mettere in testa al corpo di una mail dirottata ('' se non dirottata). */
    public static function htmlBanner(array $route): string
    {
        if (!$route['redirected']) {
            return '';
        }
        $list = htmlspecialchars(implode(', ', $route['original']), ENT_QUOTES, 'UTF-8');
        return '<div style="border:2px dashed #c0392b;background:#fdecea;color:#7b241c;padding:10px 14px;margin:0 0 16px;'
            . 'font:13px/1.4 Arial,sans-serif">'
            . '<strong>MAIL DI TEST (' . htmlspecialchars(Env::name() ?: 'non-prod', ENT_QUOTES, 'UTF-8') . ')</strong> — '
            . 'destinatari originali: ' . ($list !== '' ? $list : '—')
            . '</div>';
    }

    /** Variante testo del riquadro ('' se non dirottata). */
    public static function textBanner(array $route): string
    {
        if (!$route['redirected']) {
            return '';
        }
        return '[MAIL DI TEST — destinatari originali: ' . (implode(', ', $route['original']) ?: '—') . "]\n\n";
    }

    /**
     * Header da aggiungere dove il canale li supporta ([] se non dirottata).
     *
     * @return array<string,string>
     */
    public static function headers(array $route): array
    {
        return $route['redirected'] && $route['original'] !== []
            ? ['X-Original-To' => implode(', ', $route['original'])]
            : [];
    }

    /**
     * Solo per i test: intercetta la riga di log del blocco fail-closed al posto del Logger.
     * Il callable riceve il messaggio e il payload già passato da Redactor.
     *
     * @param (callable(string, array): void)|null $logger
     */
    public static function setLogger(?callable $logger): void
    {
        self::$logger = $logger;
    }

    /** @return string[] */
    private static function redirectTargets(): array
    {
        $out = [];
        foreach (explode(',', (string) (getenv('MAIL_REDIRECT_TO') ?: '')) as $addr) {
            $addr = trim($addr);
            if ($addr !== '' && filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                $out[] = $addr;
            }
        }
        return array_values(array_unique($out));
    }

    private static function subjectPrefix(array $original): string
    {
        $shown = array_slice($original, 0, self::SUBJECT_MAX_ADDRESSES);
        $more = count($original) - count($shown);
        $list = implode(', ', $shown) . ($more > 0 ? ", +$more" : '');
        // Header: niente a capo (difesa contro header injection).
        return '[TEST → ' . str_replace(["\r", "\n"], ' ', $list !== '' ? $list : '—') . ']';
    }

    /** @return string[] */
    private static function clean(array $list): array
    {
        $out = [];
        foreach ($list as $addr) {
            $addr = trim((string) $addr);
            if ($addr !== '') {
                $out[] = $addr;
            }
        }
        return $out;
    }

    private static function log(string $note, array $data): void
    {
        $data = (array) Redactor::redact($data);
        if (self::$logger !== null) {
            (self::$logger)($note, $data);
            return;
        }
        try {
            Logger::getInstance()->warning($note, 'MAIL_GUARD_BLOCKED', $data);
        } catch (\Throwable $e) {
            // Il blocco non deve mai trasformarsi in un errore di invio.
            error_log($note . ' ' . json_encode($data, JSON_UNESCAPED_UNICODE));
        }
    }
}
