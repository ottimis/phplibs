<?php

/**
 * Standalone regression test for MailGuard + OGMail: outside production no
 * mail reaches the real recipients.
 *
 * No network: OGMail is captured by a subclass overriding deliver(); the
 * blocked cases use a real OGMail with no transport configured (PHPMailer is
 * not even installed here, SES client never built).
 *
 * No PHPUnit dependency: run directly with `php tests/MailGuardTest.php`.
 * Exits 0 on success, 1 on failure.
 */

putenv('LOG_DRIVER=local');
require __DIR__ . '/../vendor/autoload.php';

use ottimis\phplibs\MailGuard;
use ottimis\phplibs\OGMail;
use ottimis\phplibs\schemas\Base\OGResponse;

const ENV_KEYS = ['ENV', 'ENVIRONMENT', 'MAIL_REDIRECT_TO', 'MAIL_GUARD_DISABLED', 'SMTP_TYPE', 'SMTP_HOST'];

final class CapturingMail extends OGMail
{
    /** @var array<int,array{envelope:array,channel:string}> */
    public array $delivered = [];

    protected function deliver(array $envelope, string $channel): OGResponse
    {
        $this->delivered[] = ['envelope' => $envelope, 'channel' => $channel];
        return new OGResponse(success: true);
    }

    public function last(): ?array
    {
        return $this->delivered === [] ? null : end($this->delivered)['envelope'];
    }
}

$failures = 0;
$assert = static function (string $label, mixed $got, mixed $expected) use (&$failures) {
    if ($got !== $expected) {
        $failures++;
        echo "FAIL {$label}\n  expected: " . var_export($expected, true) . "\n  got:      " . var_export($got, true) . "\n";
    } else {
        echo "ok   {$label}\n";
    }
};

$saved = [];
foreach (ENV_KEYS as $k) {
    $saved[$k] = getenv($k);
}
$logged = [];
// Ogni caso parte da ambiente pulito (setUp) con i log intercettati.
$reset = static function () use (&$logged): void {
    foreach (ENV_KEYS as $k) {
        putenv($k);
    }
    $logged = [];
};
// Per i casi che arrivano al trasporto (catturato): send() richiede comunque SMTP_HOST.
$fakeSmtp = static fn () => putenv('SMTP_HOST=smtp.invalid');
MailGuard::setLogger(static function (string $note, array $data) use (&$logged): void {
    $logged[] = $note . ' ' . json_encode($data, JSON_UNESCAPED_UNICODE);
});

$customerMail = static function (OGMail $mail): OGMail {
    return $mail->setMailFrom('noreply@example.com')
        ->setMailFromName('Example')
        ->addRcpt('customer@example.com')
        ->addCc('collab@example.com')
        ->addBcc('hidden@example.com')
        ->setReplyTo('lead@example.com')
        ->setMailSubject('Nuovo ordine')
        ->setMailHtml('<p>Corpo</p>')
        ->setMailText('Corpo');
};

// --- produzione: invariato anche con MAIL_REDIRECT_TO ---
$reset();
putenv('ENV=production');
putenv('MAIL_REDIRECT_TO=test@ottimis.com');
$fakeSmtp();
$mail = $customerMail(new CapturingMail());
$assert('prod: success', $mail->send()->success, true);
$d = $mail->last();
$assert('prod: to', $d['to'], ['customer@example.com']);
$assert('prod: cc', $d['cc'], ['collab@example.com']);
$assert('prod: bcc', $d['bcc'], ['hidden@example.com']);
$assert('prod: subject', $d['subject'], 'Nuovo ordine');
$assert('prod: html', $d['html'], '<p>Corpo</p>');
$assert('prod: text', $d['text'], 'Corpo');
$assert('prod: headers', $d['headers'], []);
$assert('prod: no log', $logged, []);

$reset();
putenv('ENV=prod');
$route = MailGuard::route([' customer@example.com ', '', '  '], [], [], 'S', 'smtp');
$assert('ENV=prod is production', $route['to'], ['customer@example.com']);
$assert('ENV=prod not redirected', $route['redirected'], false);

$reset();
putenv('ENVIRONMENT=production');
$assert('ENVIRONMENT=production fallback', MailGuard::route(['a@x.com'], [], [], 'S', 'smtp')['redirected'], false);

// --- fuori produzione con redirect ---
$reset();
putenv('ENV=staging');
putenv('MAIL_REDIRECT_TO= mm@ottimis.com , qa@example.org,not-an-email,mm@ottimis.com');
$fakeSmtp();
$mail = $customerMail(new CapturingMail());
$assert('redirect: success', $mail->send()->success, true);
$d = $mail->last();
$assert('redirect: to replaced, deduped', $d['to'], ['mm@ottimis.com', 'qa@example.org']);
$assert('redirect: cc emptied', $d['cc'], []);
$assert('redirect: bcc emptied', $d['bcc'], []);
$assert('redirect: subject prefix', $d['subject'], '[TEST → customer@example.com, collab@example.com, hidden@example.com] Nuovo ordine');
$assert('redirect: html banner lists all', str_contains($d['html'], 'customer@example.com, collab@example.com, hidden@example.com'), true);
$assert('redirect: html banner env', str_contains($d['html'], 'MAIL DI TEST (staging)'), true);
$assert('redirect: html body kept', str_ends_with($d['html'], '<p>Corpo</p>'), true);
$assert('redirect: text banner', $d['text'], "[MAIL DI TEST — destinatari originali: customer@example.com, collab@example.com, hidden@example.com]\n\nCorpo");
$assert('redirect: header', $d['headers'], ['X-Original-To' => 'customer@example.com, collab@example.com, hidden@example.com']);

// fallback testo (strip_tags) con banner in testa
$mail = (new CapturingMail())->setMailFrom('a@x.com')->setMailFromName('A')
    ->addRcpt('customer@example.com')->setMailSubject('S')->setMailHtml('<p>Solo html</p>');
$mail->send();
$assert('redirect: text fallback with banner', $mail->last()['text'], "[MAIL DI TEST — destinatari originali: customer@example.com]\n\nSolo html");

// --- oggetto con max 3 indirizzi, banner e header completi ---
$reset();
putenv('ENV=local');
putenv('MAIL_REDIRECT_TO=mm@ottimis.com');
$route = MailGuard::route(['a@x.com', 'b@x.com'], ['c@x.com', 'd@x.com', 'a@x.com'], ['e@x.com'], 'S', 'smtp');
$assert('cap: subject', $route['subject'], '[TEST → a@x.com, b@x.com, c@x.com, +2] S');
$assert('cap: original deduped', $route['original'], ['a@x.com', 'b@x.com', 'c@x.com', 'd@x.com', 'e@x.com']);
$assert('cap: html banner keeps all', str_contains(MailGuard::htmlBanner($route), 'd@x.com, e@x.com'), true);
$assert('cap: text banner keeps all', str_contains(MailGuard::textBanner($route), 'd@x.com, e@x.com'), true);
$assert('cap: header keeps all', MailGuard::headers($route), ['X-Original-To' => 'a@x.com, b@x.com, c@x.com, d@x.com, e@x.com']);
$route = MailGuard::route(["a@x.com\r\nBcc: evil@x.com"], [], [], 'S', 'smtp');
$assert('subject: no CR/LF', str_contains($route['subject'], "\r") || str_contains($route['subject'], "\n"), false);
$route = MailGuard::route(['<b>@x.com'], [], [], 'S', 'smtp');
$assert('html banner escaped', str_contains(MailGuard::htmlBanner($route), '&lt;b&gt;@x.com'), true);
$plain = ['to' => ['a@x.com'], 'cc' => [], 'bcc' => [], 'subject' => 'S', 'redirected' => false, 'original' => []];
$assert('not redirected: helpers empty', [MailGuard::htmlBanner($plain), MailGuard::textBanner($plain), MailGuard::headers($plain)], ['', '', []]);

// --- fuori produzione senza redirect: fail-closed ---
$reset();
putenv('ENV=local');
$mail = $customerMail(new CapturingMail());
$assert('blocked: success', $mail->send()->success, true);
$assert('blocked: no transport', $mail->delivered, []);
$assert('blocked: one log line', count($logged), 1);
$assert('blocked: log has recipient', str_contains($logged[0] ?? '', 'hidden@example.com'), true);
$assert('blocked: log has subject', str_contains($logged[0] ?? '', 'Nuovo ordine'), true);
$assert('blocked: log has channel', str_contains($logged[0] ?? '', '"channel":"smtp"'), true);

$reset();
$assert('unset env: guard active', MailGuard::route(['customer@example.com'], [], [], 'S', 'smtp'), null);
$assert('unset env: logged', count($logged), 1);

$reset();
putenv('ENV=staging');
putenv('MAIL_REDIRECT_TO=nobody, ,@x');
$assert('only invalid redirect: fail-closed', MailGuard::route(['customer@example.com'], [], [], 'S', 'smtp'), null);

// --- via di fuga MAIL_GUARD_DISABLED ---
$reset();
putenv('ENV=staging');
putenv('MAIL_GUARD_DISABLED=true');
$fakeSmtp();
putenv('MAIL_REDIRECT_TO=mm@ottimis.com');
$mail = $customerMail(new CapturingMail());
$mail->send();
$d = $mail->last();
$assert('disabled: to unchanged', $d['to'], ['customer@example.com']);
$assert('disabled: bcc unchanged', $d['bcc'], ['hidden@example.com']);
$assert('disabled: subject unchanged', $d['subject'], 'Nuovo ordine');
$assert('disabled: body unchanged', $d['html'], '<p>Corpo</p>');

// --- OGMail reale bloccato senza SMTP_TYPE/SMTP_HOST: il blocco viene prima del trasporto ---
$reset();
putenv('ENV=local');
$assert('real OGMail blocked: success', $customerMail(new OGMail())->send()->success, true);

// --- send() due volte: niente doppio prefisso/banner, stato invariato ---
$reset();
$fakeSmtp();
putenv('ENV=staging');
putenv('MAIL_REDIRECT_TO=mm@ottimis.com');
$mail = $customerMail(new CapturingMail());
$mail->send();
$mail->send();
$assert('twice: two deliveries', count($mail->delivered), 2);
$assert('twice: same envelope', $mail->delivered[0]['envelope'], $mail->delivered[1]['envelope']);
$assert('twice: single prefix', substr_count($mail->delivered[1]['envelope']['subject'], '[TEST'), 1);
$assert('twice: single banner', substr_count($mail->delivered[1]['envelope']['html'], 'MAIL DI TEST'), 1);

// --- sendAWS()/sendPHPMailer() diretti passano dalla guard ---
$mail = $customerMail(new CapturingMail());
$mail->sendAWS();
$assert('direct sendAWS: channel', $mail->delivered[0]['channel'], 'ses');
$assert('direct sendAWS: redirected', $mail->last()['to'], ['mm@ottimis.com']);
$assert('direct sendAWS: single prefix', substr_count($mail->last()['subject'], '[TEST'), 1);
$mail->sendPHPMailer();
$assert('direct sendPHPMailer: channel', $mail->delivered[1]['channel'], 'smtp');
$assert('direct sendPHPMailer: redirected', $mail->last()['to'], ['mm@ottimis.com']);
$assert('direct sendPHPMailer: cc emptied', $mail->last()['cc'], []);

$reset();
putenv('ENV=local');
$assert('direct sendPHPMailer blocked: success', $customerMail(new OGMail())->sendPHPMailer()->success, true);
$assert('direct sendAWS blocked: success', $customerMail(new OGMail())->sendAWS()->success, true);
$assert('direct blocked: logged twice', count($logged), 2);

// tearDown
MailGuard::setLogger(null);
foreach ($saved as $k => $v) {
    putenv($v === false ? $k : "$k=$v");
}

echo $failures === 0 ? "\nALL PASSED\n" : "\n{$failures} FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
