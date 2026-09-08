<?php

namespace App\Services\Mail;

use App\Models\SmtpConfiguration;
use App\Models\Template;
use App\Modules\Broadcasting\Models\WorkspaceSmtpConfig;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MailService
{
    /**
     * Send an email using the active SMTP configuration and an email template by key.
     *
     * @param  array<string, string>  $replacements  e.g. ['app_name' => 'CloudPOS', 'plan_name' => 'Pro', 'user_name' => 'John']
     */
    public function sendWithTemplate(string $templateKey, string $to, array $replacements = []): bool
    {
        $smtp = SmtpConfiguration::getActive();
        if (! $smtp) {
            Log::warning('MailService: No active SMTP configuration. Email not sent.', ['to' => $to, 'template' => $templateKey]);

            return false;
        }

        $template = Template::where('type', 'email')->where('slug', $templateKey)->where('enabled', true)->first();
        if (! $template) {
            Log::warning('MailService: Email template not found or disabled.', ['key' => $templateKey]);

            return false;
        }

        $rendered = $this->renderTemplate($template, $replacements);

        // Transactional mail must never surface an SMTP error to the end user.
        // Swallow send failures here (already logged in sendRaw) and report a
        // simple false so callers fall back gracefully. Campaign delivery and
        // admin test-send use sendRaw directly and keep the thrown exception.
        try {
            return $this->sendRaw($smtp, $to, $rendered['subject'], $rendered['html']);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Substitute the placeholders in a template row and wrap its prose in the
     * shared branded layout.
     *
     * The row's `content` holds prose only; the chrome (header label, preheader,
     * button, closing note) comes from `meta`, so substitution has to run over
     * those meta strings too — they reach the recipient exactly like the body does.
     *
     * @param  array<string, string>  $replacements
     * @return array{subject: string, html: string}
     */
    public function renderTemplate(Template $template, array $replacements = []): array
    {
        // Two maps on purpose. Values printed as text (subject, preheader, href)
        // are escaped by Blade at render time, so they go in raw; values dropped
        // into HTML we print unescaped are escaped here instead. Support ticket
        // bodies are attacker-controlled free text, so this is the only thing
        // standing between a customer's <script> and an admin's inbox.
        $textValues = $replacements;
        $htmlValues = $this->escapeForHtml($replacements);

        $meta = $this->templateMeta($template);

        $subject = $this->replacePlaceholders((string) ($template->subject ?: $template->name), $textValues);
        $body = $this->replacePlaceholders((string) ($template->content ?? ''), $htmlValues);

        $preheader = isset($meta['preheader']) && is_string($meta['preheader']) && $meta['preheader'] !== ''
            ? $this->replacePlaceholders($meta['preheader'], $textValues)
            : $this->excerpt($body);

        $note = isset($meta['note']) && is_string($meta['note']) && $meta['note'] !== ''
            ? $this->replacePlaceholders($meta['note'], $htmlValues)
            : null;

        $ctaLabel = isset($meta['cta_label']) && is_string($meta['cta_label']) && $meta['cta_label'] !== ''
            ? $meta['cta_label']
            : null;

        $ctaUrl = isset($meta['cta_url']) && is_string($meta['cta_url']) && $meta['cta_url'] !== ''
            ? $this->replacePlaceholders($meta['cta_url'], $textValues)
            : null;

        $branding = $this->branding();

        try {
            $html = view('mail.layout', [
                'subject' => $subject,
                'preheader' => $preheader,
                'label' => isset($meta['label']) && is_string($meta['label']) ? $meta['label'] : null,
                'body' => $body,
                'ctaLabel' => $ctaLabel,
                'ctaUrl' => $ctaUrl,
                'fallback' => (bool) ($meta['fallback'] ?? false),
                'note' => $note,
                'appName' => $branding['appName'],
                'tagline' => $branding['tagline'],
                'supportEmail' => $branding['supportEmail'],
            ])->render();
        } catch (\Throwable $e) {
            // A broken view must not take down the password-reset path with it.
            // An unstyled email beats no email.
            Log::error('MailService: Failed to render the email layout, falling back to bare prose.', [
                'template' => $template->slug,
                'error' => $e->getMessage(),
            ]);

            $html = $body;
        }

        return ['subject' => $subject, 'html' => $html];
    }

    /**
     * Send a raw email using the given SMTP configuration.
     *
     * @param  SmtpConfiguration|WorkspaceSmtpConfig  $smtp
     * @param  array<string, string>  $extraHeaders  Additional RFC headers (e.g. List-Unsubscribe)
     * @param  string|null  $fromEmail  Override the SMTP default from address
     * @param  string|null  $fromName  Override the SMTP default from name
     * @param  string|null  $replyTo  Optional reply-to address
     */
    public function sendRaw(
        Model $smtp,
        string $to,
        string $subject,
        string $bodyHtml,
        array $extraHeaders = [],
        ?string $fromEmail = null,
        ?string $fromName = null,
        ?string $replyTo = null,
    ): bool {
        try {
            $this->configureMailer($smtp);
            Mail::mailer('dynamic_smtp')->html($bodyHtml, function ($message) use ($to, $subject, $smtp, $extraHeaders, $fromEmail, $fromName, $replyTo) {
                $message->to($to)
                    ->subject($subject)
                    ->from($fromEmail ?: $smtp->from_email, $fromName ?: $smtp->from_name);

                if ($replyTo) {
                    $message->replyTo($replyTo);
                }

                foreach ($extraHeaders as $name => $value) {
                    $message->getHeaders()->addTextHeader($name, $value);
                }
            });

            return true;
        } catch (\Throwable $e) {
            Log::error('MailService: Failed to send email.', [
                'to' => $to,
                'error' => $e->getMessage(),
                'smtp_id' => $smtp->id,
            ]);
            throw $e;
        }
    }

    /**
     * Configure Laravel mail to use the given SMTP configuration for the 'dynamic_smtp' mailer.
     *
     * @param  SmtpConfiguration|WorkspaceSmtpConfig  $smtp
     */
    public function configureMailer(Model $smtp): void
    {
        $encryption = $smtp->encryption;
        if ($encryption === 'none' || $encryption === 'null' || $encryption === '') {
            $encryption = null;
        }

        $manager = app('mail.manager');
        if (method_exists($manager, 'purge')) {
            $manager->purge('dynamic_smtp');
        }

        Config::set('mail.mailers.dynamic_smtp', [
            'transport' => 'smtp',
            'host' => $smtp->host,
            'port' => $smtp->port,
            'username' => $smtp->username,
            'password' => $smtp->getDecryptedPassword(),
            'encryption' => $encryption,
            'timeout' => null,
            'from' => [
                'address' => $smtp->from_email,
                'name' => $smtp->from_name,
            ],
        ]);
    }

    /**
     * The template's structural chrome: label, preheader, cta_label, cta_url,
     * fallback, note — everything the admin UI cannot edit.
     *
     * The `meta` cast already hands back an array; the json_decode is for the
     * nullable json column being read raw (which is also all static analysis
     * sees, since the cast lives in a casts() method).
     *
     * @return array<array-key, mixed>
     */
    public function templateMeta(Template $template): array
    {
        $meta = $template->meta;

        if (is_string($meta)) {
            $meta = json_decode($meta, true);
        }

        return is_array($meta) ? $meta : [];
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function replacePlaceholders(string $text, array $replacements): string
    {
        foreach ($replacements as $key => $value) {
            $text = str_replace('{{'.$key.'}}', (string) $value, $text);
        }

        return $text;
    }

    /**
     * Values headed for a spot in the email we print unescaped. Newlines become
     * <br> so a multi-line support message does not arrive as one run-on blob.
     *
     * @param  array<string, string>  $replacements
     * @return array<string, string>
     */
    private function escapeForHtml(array $replacements): array
    {
        $escaped = [];
        foreach ($replacements as $key => $value) {
            $escaped[$key] = nl2br(e((string) $value), false);
        }

        return $escaped;
    }

    /**
     * Inbox preview line for a row whose meta carries no preheader.
     */
    private function excerpt(string $html): string
    {
        // Tags become spaces, or "</h1><p>" welds the heading to the first sentence.
        $text = html_entity_decode((string) preg_replace('/<[^>]*>/', ' ', $html), ENT_QUOTES, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_substr($text, 0, 140);
    }

    /**
     * The white-label name, tagline and support address.
     *
     * BrandingServiceProvider already pushes the admin's SystemSetting values into
     * these config keys at boot (and leaves the .env defaults in place when unset),
     * so reading config here gives the same answer as HandleInertiaRequests'
     * brandingShare() without a query per email.
     *
     * @return array{appName: string, tagline: ?string, supportEmail: ?string}
     */
    private function branding(): array
    {
        $appName = config('saas.app_name') ?: config('app.name');
        $tagline = config('saas.tagline');
        $supportEmail = config('saas.support_email');

        return [
            'appName' => is_string($appName) && $appName !== '' ? $appName : 'SmartUno',
            'tagline' => is_string($tagline) && $tagline !== '' ? $tagline : null,
            'supportEmail' => $this->contactableSupportEmail($supportEmail),
        ];
    }

    /**
     * The footer offers this address as the place to ask for help, so it has to be a
     * mailbox somebody reads.
     *
     * config('saas.support_email') falls through to the placeholder from config/saas.php
     * or to MAIL_FROM_ADDRESS, which on most installs is a no-reply — neither is empty,
     * so the "is it set" guard never trips. A footer with no help line is better than one
     * pointing at a dead mailbox.
     */
    private function contactableSupportEmail(mixed $email): ?string
    {
        if (! is_string($email) || $email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        if (str_ends_with(strtolower($email), '@example.com')) {
            return null;
        }

        $localPart = strtolower((string) strstr($email, '@', true));

        return in_array(str_replace(['-', '.', '_'], '', $localPart), ['noreply', 'donotreply'], true)
            ? null
            : $email;
    }
}
