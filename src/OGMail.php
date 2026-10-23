<?php

namespace ottimis\phplibs;

use Aws\Credentials\CredentialProvider;
use Aws\SesV2\SesV2Client;
use Egulias\EmailValidator\EmailValidator;
use Egulias\EmailValidator\Validation\DNSCheckValidation;
use Egulias\EmailValidator\Validation\RFCValidation;
use Exception;
use ottimis\phplibs\schemas\Base\OGResponse;
use ottimis\phplibs\schemas\OGMail\Attach;
use ottimis\phplibs\schemas\OGMail\CID;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;
use Smarty\Exception as SmartyException;

class OGMail
{
    protected const string CHANNEL_SMTP = 'smtp';
    protected const string CHANNEL_SES = 'ses';

    protected Logger $Logger;
    private SesV2Client $SESClient;
    private string $mailFrom;
    private string $mailFromName;
    private string $mailSubject;
    private string $mailText;
    private string $mailHtml;
    private array $rcpt = [];
    private array $replyTo = [];
    private array $cc = [];
    private array $bcc = [];
    private array $cids = [];
    private array $attaches = [];

    public function __construct()
    {
        $this->Logger = Logger::getInstance();
        if (getenv("SMTP_TYPE") === "aws")   {
            $config = [
                'version' => 'latest',
            ];
            if (Env::isLocal())   {
                $provider = CredentialProvider::sso(getenv("AWS_PROFILE_NAME"));
                $config['credentials'] = $provider;
            }
            $this->SESClient = new SesV2Client($config);
        }
    }

    public static function verify($email, $dns = false): bool
    {
        $validator = new EmailValidator();
        $res = $validator->isValid($email, new RFCValidation());
        if (!$dns) {
            return $res;
        }

        return $res && $validator->isValid($email, new DNSCheckValidation());
    }

    public function addRcpt($email): static
    {
        $this->rcpt[] = $email;
        return $this;
    }

    /**
     * Imposta uno o più indirizzi Reply-To.
     * Accetta sia una singola email (string) sia un array di email — retrocompatibile col vecchio chiamante.
     * Sovrascrive l'eventuale lista precedente; per aggiungere usa addReplyTo().
     */
    public function setReplyTo($email): static
    {
        $this->replyTo = is_array($email) ? array_values($email) : [$email];
        return $this;
    }

    /**
     * Aggiunge un indirizzo Reply-To alla lista esistente.
     * Coerente con addRcpt/addCc/addBcc.
     */
    public function addReplyTo(string $email): static
    {
        $this->replyTo[] = $email;
        return $this;
    }

    public function addCc($email): static
    {
        $this->cc[] = $email;
        return $this;
    }

    public function addBcc($email): static
    {
        $this->bcc[] = $email;
        return $this;
    }

    public function setMailFrom($email): static
    {
        $this->mailFrom = $email;
        return $this;
    }

    public function setMailFromName($name): static
    {
        $this->mailFromName = $name;
        return $this;
    }

    public function setMailSubject($subject): static
    {
        $this->mailSubject = $subject;
        return $this;
    }

    public function setMailText($text): static
    {
        $this->mailText = $text;
        return $this;
    }

    public function setMailHtml($html): static
    {
        $this->mailHtml = $html;
        return $this;
    }

    public function addCid(CID $cid): static
    {
        $this->cids[] = $cid;
        return $this;
    }

    /**
     * Alias for addCid
     *
     * @param CID $cid
     * @return $this
     */
    public function addImage(CID $cid): static
    {
        return $this->addCid($cid);
    }

    public function addAttachment(Attach $attachment): static
    {
        $this->attaches[] = $attachment;
        return $this;
    }


    /**
     * @throws SmartyException
     * @throws Exception
     */
    public function sendTemplate(
        ?string $template = null,
        ?string $templateString = null,
        array  $templateData = [],
    ): OGResponse
    {
        $OGSmarty = new OGSmarty();
        $mailHtml = $OGSmarty->loadTemplate(
            $template,
            $templateString,
            $templateData
        );
        $this->setMailHtml($mailHtml);
        return $this->send();
    }

    /**
     * @throws Exception
     */
    public function send(): OGResponse
    {
        if (empty($this->mailFrom) && empty(getenv("SMTP_FROM"))) {
            throw new RuntimeException("Mail from is required");
        }
        if (empty($this->mailFromName) && empty(getenv("SMTP_FROM_NAME"))) {
            throw new RuntimeException("Mail from name is required");
        }
        if (empty($this->mailSubject))  {
            throw new RuntimeException("Mail subject is required");
        }
        if (empty($this->mailHtml)) {
            throw new RuntimeException("Mail html is required");
        }
        if (empty($this->rcpt)) {
            throw new RuntimeException("Mail recipient is required");
        }

        // La guard viene prima dei controlli di trasporto: una mail bloccata
        // fuori produzione non richiede SMTP_HOST né tocca PHPMailer/SES.
        $channel = getenv("SMTP_TYPE") === "aws" ? self::CHANNEL_SES : self::CHANNEL_SMTP;
        $envelope = $this->guardedEnvelope($channel);
        if ($envelope === null) {
            return new OGResponse(success: true);
        }

        if ($channel === self::CHANNEL_SMTP && empty(getenv("SMTP_HOST"))) {
            throw new RuntimeException("SMTP_HOST is required");
        }
        return $this->deliver($envelope, $channel);
    }

    /**
     * Invio diretto via PHPMailer. Passa comunque dalla MailGuard.
     *
     * @throws Exception
     */
    public function sendPHPMailer(): OGResponse
    {
        $envelope = $this->guardedEnvelope(self::CHANNEL_SMTP);
        if ($envelope === null) {
            return new OGResponse(success: true);
        }
        return $this->deliver($envelope, self::CHANNEL_SMTP);
    }

    /**
     * Invio diretto via AWS SES. Passa comunque dalla MailGuard.
     *
     * @throws Exception
     */
    public function sendAWS(): OGResponse
    {
        $envelope = $this->guardedEnvelope(self::CHANNEL_SES);
        if ($envelope === null) {
            return new OGResponse(success: true);
        }
        return $this->deliver($envelope, self::CHANNEL_SES);
    }

    /**
     * Busta effettiva dopo la MailGuard, calcolata senza toccare lo stato dell'oggetto
     * (chiamare send() due volte dà lo stesso risultato). null = invio bloccato.
     *
     * @return array{to:string[],cc:string[],bcc:string[],subject:string,html:string,text:string,headers:array<string,string>}|null
     */
    private function guardedEnvelope(string $channel): ?array
    {
        $html = $this->mailHtml ?? '';
        $route = MailGuard::route($this->rcpt, $this->cc, $this->bcc, $this->mailSubject ?? '', $channel);
        if ($route === null) {
            return null;
        }
        $text = !empty($this->mailText) ? $this->mailText : strip_tags($html);

        return [
            'to' => $route['to'],
            'cc' => $route['cc'],
            'bcc' => $route['bcc'],
            'subject' => $route['subject'],
            'html' => MailGuard::htmlBanner($route) . $html,
            'text' => MailGuard::textBanner($route) . $text,
            'headers' => MailGuard::headers($route),
        ];
    }

    /**
     * Consegna della busta già passata dalla MailGuard. Punto di estensione: una
     * sottoclasse può sovrascriverlo per intercettare la busta effettiva al posto
     * del trasporto (test senza rete, anche nei progetti).
     *
     * @param array{to:string[],cc:string[],bcc:string[],subject:string,html:string,text:string,headers:array<string,string>} $envelope
     * @param string $channel self::CHANNEL_SMTP | self::CHANNEL_SES
     * @throws Exception
     */
    protected function deliver(array $envelope, string $channel): OGResponse
    {
        return $channel === self::CHANNEL_SES
            ? $this->transportAWS($envelope)
            : $this->transportPHPMailer($envelope);
    }

    /**
     * @throws Exception
     */
    private function transportPHPMailer(array $envelope): OGResponse
    {
        $mail = new PHPMailer();
        $mail->Host = getenv("SMTP_HOST");
        if (!empty(getenv("SMTP_USER")))    {
            $mail->Username = getenv("SMTP_USER");
        }
        if (!empty(getenv("SMTP_PASSWORD")))    {
            $mail->Password = getenv("SMTP_PASSWORD");
        }
        $mail->setFrom($this->mailFrom ?? getenv("SMTP_FROM"), $this->mailFromName ?? getenv("SMTP_FROM_NAME"));

        $mail->SMTPSecure = getenv("SMTP_SECURE");
        $mail->Port = getenv("SMTP_PORT");
        $mail->CharSet = 'UTF-8';
        $mail->isSMTP();
        $mail->SMTPAuth = true;

        $mail->WordWrap = 50;
        $mail->isHTML();

        foreach ($envelope['to'] as $rcpt) {
            $mail->addAddress($rcpt);
        }
        foreach ($this->replyTo as $rt) {
            $mail->AddReplyTo($rt);
        }
        foreach ($envelope['cc'] as $cc) {
            $mail->addCC($cc);
        }
        foreach ($envelope['bcc'] as $bcc) {
            $mail->addBCC($bcc);
        }
        foreach ($envelope['headers'] as $name => $value) {
            $mail->addCustomHeader($name, $value);
        }

        foreach ($this->cids as $cid) {
            $path = "/tmp/$cid->name";
            file_put_contents($path, file_get_contents($cid->url));
            $mail->AddEmbeddedImage($path, $cid->cid, $cid->name);
        }

        foreach ($this->attaches as $attach)    {
            $mail->AddAttachment($attach->path, $attach->name);
        }

        // Set email format to HTML
        $mail->Subject = $envelope['subject'];
        $mail->Body = $envelope['html'];
        $mail->AltBody = $envelope['text'];

        try {
            if ($mail->send()) {
                return new OGResponse(
                    success: true
                );
            }
        } catch (Exception $e) {
            $this->Logger->error("Errore mail - " . $e->getMessage());
            return new OGResponse(
                success: false,
                errorMessage: $e->getMessage()
            );
        }

        $this->Logger->error("Errore mail - " . $mail->ErrorInfo);
        return new OGResponse(
            success: false,
            errorMessage: $mail->ErrorInfo
        );
    }

    /**
     * Rimuove CR/LF/NUL da un valore destinato a un header o parametro MIME,
     * per prevenire header injection nel messaggio raw inviato a SES.
     */
    private static function sanitizeMimeValue(string $value): string
    {
        return str_replace(["\r", "\n", "\0"], '', $value);
    }

    /**
     * @throws Exception
     */
    private function transportAWS(array $envelope): OGResponse
    {
        // New lines are required for the MIME boundary!!! Do not remove them!
        $boundary = uniqid(mt_rand(), true);
        $alternativeBoundary = 'ALT-' . uniqid(mt_rand(), true);
        $topContentType = !empty($this->attaches) ? 'multipart/mixed' : 'multipart/related';
        $mime_headers = [
            'MIME-Version: 1.0',
            'Content-Type: ' . $topContentType . '; boundary="' . $boundary . '"',
            'Content-Transfer-Encoding: 7bit',
            // RFC 2047: il base64 neutralizza CRLF injection e gestisce i subject UTF-8
            'Subject: =?UTF-8?B?' . base64_encode($envelope['subject']) . '?=',
        ];
        foreach ($envelope['headers'] as $name => $value) {
            $mime_headers[] = self::sanitizeMimeValue($name) . ': ' . self::sanitizeMimeValue($value);
        }
        $charset = 'UTF-8';
        $mailText = $envelope['text'];
        $mailHtml = $envelope['html'];

        $html_body = <<<EOT
        --$boundary
        Content-Type: multipart/alternative; boundary="$alternativeBoundary"

        --$alternativeBoundary
        Content-Type: text/plain; charset="$charset"
        
        $mailText
        
        --$alternativeBoundary
        Content-Type: text/html; charset=$charset
        
        $mailHtml
        
        --$alternativeBoundary--

        EOT;

        foreach ($this->cids as $cid)   {
            $image_headers = get_headers($cid->url, true);
            $image_type = $image_headers['Content-Type'];
            if (is_array($image_type)) {
                $image_type = end($image_type);
            }
            // Content-Type arriva dal server remoto: non fidarsi
            $image_type = self::sanitizeMimeValue((string) $image_type);
            $contentId = self::sanitizeMimeValue((string) $cid->cid);
            $image_data = base64_encode(file_get_contents($cid->url));
            $html_body .= <<<EOT
            --{$boundary}
            Content-Type: $image_type;
            Content-Transfer-Encoding: base64
            Content-ID: <$contentId>
            Content-Disposition: inline;

            {$image_data}

            EOT;
        }

        foreach ($this->attaches as $attach) {
            $fileData = base64_encode(file_get_contents($attach->path));
            $mimeType = self::sanitizeMimeValue(mime_content_type($attach->path) ?: 'application/octet-stream');
            // Oltre a CR/LF, le doppie virgolette romperebbero il quoting di filename="..."
            $fileName = str_replace('"', "'", self::sanitizeMimeValue($attach->name));
            $html_body .= <<<EOT
            --{$boundary}
            Content-Type: $mimeType; name="$fileName"
            Content-Transfer-Encoding: base64
            Content-Disposition: attachment; filename="$fileName"

            {$fileData}

            EOT;
        }

        $html_body .= "--$boundary--";


        $data = [
            'Content' => [
                'Raw' => [
                    'Data' => implode("\r\n", $mime_headers) . "\r\n\r\n" . $html_body,
                ],
            ],
            'Destination' => [
                'ToAddresses' => [],
            ],
            'FromEmailAddress' => ($this->mailFromName ?? getenv("SMTP_FROM_NAME"))." <".($this->mailFrom ?? getenv("SMTP_FROM")).">",
            'ReplyToAddresses' => [],
        ];
        $data['Destination']['ToAddresses'] = $envelope['to'];
        if (!empty($this->replyTo)) {
            $data['ReplyToAddresses'] = $this->replyTo;
        }
        if (!empty($envelope['cc'])) {
            $data['Destination']['CcAddresses'] = $envelope['cc'];
        }
        if (!empty($envelope['bcc'])) {
            $data['Destination']['BccAddresses'] = $envelope['bcc'];
        }

        try {
            // Send mail using AWS SES V2 SESClient
            $result = $this->SESClient->sendEmail($data);
        } catch (Exception $e) {
            $this->Logger->error("Errore mail SES -> " . $e->getMessage());
            return new OGResponse(
                success: false,
                errorMessage: $e->getMessage()
            );
        }

        if ($result['MessageId']) {
            return new OGResponse(
                success: true
            );
        }

        $this->Logger->error("Errore mail - " . json_encode($result, JSON_THROW_ON_ERROR));
        return new OGResponse(
            success: false,
            errorMessage: json_encode($result, JSON_THROW_ON_ERROR)
        );
    }
}
