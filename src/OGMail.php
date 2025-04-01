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
    protected Logger $Logger;
    private SesV2Client $SESClient;
    private string $mailFrom;
    private string $mailFromName;
    private string $mailSubject;
    private string $mailText;
    private string $mailHtml;
    private array $rcpt = [];
    private string $replyTo;
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
            if (getenv('ENV') === 'local')   {
                $provider = CredentialProvider::sso(getenv("S3_PROFILE_NAME"));
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

    public function setReplyTo($email): static
    {
        $this->replyTo = $email;
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
        if (empty($this->mailText)) {
            $this->mailText = strip_tags($this->mailHtml);
        }

        if (getenv("SMTP_TYPE") === "aws") {
            return $this->sendAWS();
        }

        if (empty(getenv("SMTP_HOST"))) {
            throw new RuntimeException("SMTP_HOST is required");
        }
        return $this->sendPHPMailer();
    }

    /**
     * @throws Exception
     */
    public function sendPHPMailer(): OGResponse
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

        foreach ($this->rcpt as $rcpt) {
            $mail->addAddress($rcpt);
        }
        if (!empty($this->replyTo)) {
            $mail->AddReplyTo($this->replyTo);
        }
        foreach ($this->cc as $cc) {
            $mail->addCC($cc);
        }
        foreach ($this->bcc as $bcc) {
            $mail->addBCC($bcc);
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
        $mail->Subject = $this->mailSubject;
        $mail->Body = $this->mailHtml;
        $mail->AltBody = $this->mailText;

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
     * @throws Exception
     */
    public function sendAWS(): OGResponse
    {
        // New lines are required for the MIME boundary!!! Do not remove them!
        $boundary = uniqid(mt_rand(), true);
        $alternativeBoundary = 'ALT-' . uniqid(mt_rand(), true);
        $mime_headers = [
            'MIME-Version: 1.0',
            'Content-Type: multipart/related; boundary="' . $boundary . '"',
            'Content-Transfer-Encoding: 7bit',
            'Subject: ' . $this->mailSubject,
        ];
        $charset = 'UTF-8';

        $html_body = <<<EOT
        --$boundary
        Content-Type: multipart/alternative; boundary="$alternativeBoundary"

        --$alternativeBoundary
        Content-Type: text/plain; charset="$charset"
        
        $this->mailText
        
        --$alternativeBoundary
        Content-Type: text/html; charset=$charset
        
        $this->mailHtml
        
        --$alternativeBoundary--

        EOT;

        foreach ($this->cids as $cid)   {
            $image_headers = get_headers($cid->url, true);
            $image_type = $image_headers['Content-Type'];
            $image_data = base64_encode(file_get_contents($cid->url));
            $html_body .= <<<EOT
            --{$boundary}
            Content-Type: $image_type;
            Content-Transfer-Encoding: base64
            Content-ID: <$cid->cid>
            Content-Disposition: inline;

            {$image_data}

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
        $data['Destination']['ToAddresses'] = $this->rcpt;
        if (!empty($this->replyTo)) {
            $data['ReplyToAddresses'] = $this->replyTo;
        }
        if (!empty($this->cc)) {
            $data['Destination']['CcAddresses'] = $this->cc;
        }
        if (!empty($this->bcc)) {
            $data['Destination']['BccAddresses'] = $this->bcc;
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
