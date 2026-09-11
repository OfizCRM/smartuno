<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SmtpConfiguration;
use App\Models\Template;
use App\Services\Mail\MailService;
use App\Support\Romania;
use App\Support\TicketLabels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class EmailSystemController extends Controller
{
    public function __construct(
        protected MailService $mailService
    ) {}

    public function index(): Response
    {
        $smtpConfigurations = SmtpConfiguration::orderBy('is_active', 'desc')->orderBy('id')->get()->map(fn (SmtpConfiguration $c) => [
            'id' => $c->id,
            'transport' => $c->transport,
            'host' => $c->host,
            'port' => $c->port,
            'username' => $c->username,
            'password' => '', // never send to frontend
            'encryption' => $c->encryption,
            'from_email' => $c->from_email,
            'from_name' => $c->from_name,
            'is_active' => $c->is_active,
            'summary' => $c->summary,
        ]);

        $emailTemplates = Template::where('type', 'email')->orderBy('name')->get()->map(fn (Template $t) => [
            'id' => $t->id,
            'name' => $t->name,
            'slug' => $t->slug,
            'subject' => $t->subject,
            'content' => $t->content,
            'enabled' => $t->enabled,
            'description' => $t->meta['description'] ?? null,
            'placeholders' => $t->meta['placeholders'] ?? [],
        ]);

        return Inertia::render('Admin/EmailSystem/Index', [
            'smtpConfigurations' => $smtpConfigurations,
            'emailTemplates' => $emailTemplates,
        ]);
    }

    public function storeSmtp(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'transport' => ['required', 'string', 'in:'.implode(',', SmtpConfiguration::TRANSPORTS)],
            // host/port/encryption describe an SMTP connection and mean nothing
            // to an HTTP API, so they are required only for the transport that
            // reads them. `password` carries the SMTP password OR the API key —
            // one encrypted column, because it is one secret either way.
            'host' => ['required_if:transport,'.SmtpConfiguration::TRANSPORT_SMTP, 'nullable', 'string', 'max:255'],
            'port' => ['required_if:transport,'.SmtpConfiguration::TRANSPORT_SMTP, 'nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['required_if:transport,'.SmtpConfiguration::TRANSPORT_SMTP, 'nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'encryption' => ['required_if:transport,'.SmtpConfiguration::TRANSPORT_SMTP, 'nullable', 'string', 'in:tls,ssl,none'],
            'from_email' => ['required', 'email'],
            'from_name' => ['required', 'string', 'max:255'],
            'activate' => ['boolean'],
        ]);

        if (($validated['activate'] ?? false) === true) {
            SmtpConfiguration::query()->update(['is_active' => false]);
        }

        SmtpConfiguration::create([
            'transport' => $validated['transport'],
            'host' => $validated['host'] ?? null,
            'port' => $validated['port'],
            'username' => $validated['username'],
            'password' => $validated['password'],
            'encryption' => $validated['encryption'],
            'from_email' => $validated['from_email'],
            'from_name' => $validated['from_name'],
            'is_active' => $validated['activate'] ?? false,
        ]);

        return redirect()->route('admin.email-system.index')->with('success', __('SMTP configuration added.'));
    }

    public function updateSmtp(Request $request, SmtpConfiguration $smtpConfiguration): RedirectResponse
    {
        $validated = $request->validate([
            'transport' => ['required', 'string', 'in:'.implode(',', SmtpConfiguration::TRANSPORTS)],
            // Same shape as storeSmtp, except `password` stays nullable: blank
            // means "keep the stored secret", which is why the update below only
            // writes it when something was typed.
            'host' => ['required_if:transport,'.SmtpConfiguration::TRANSPORT_SMTP, 'nullable', 'string', 'max:255'],
            'port' => ['required_if:transport,'.SmtpConfiguration::TRANSPORT_SMTP, 'nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['required_if:transport,'.SmtpConfiguration::TRANSPORT_SMTP, 'nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'encryption' => ['required_if:transport,'.SmtpConfiguration::TRANSPORT_SMTP, 'nullable', 'string', 'in:tls,ssl,none'],
            'from_email' => ['required', 'email'],
            'from_name' => ['required', 'string', 'max:255'],
            'activate' => ['boolean'],
        ]);

        if (($validated['activate'] ?? false) === true) {
            SmtpConfiguration::where('id', '!=', $smtpConfiguration->id)->update(['is_active' => false]);
        }

        $data = [
            'transport' => $validated['transport'],
            'host' => $validated['host'] ?? null,
            'port' => $validated['port'] ?? null,
            'username' => $validated['username'] ?? null,
            'encryption' => $validated['encryption'] ?? null,
            'from_email' => $validated['from_email'],
            'from_name' => $validated['from_name'],
            'is_active' => $validated['activate'] ?? $smtpConfiguration->is_active,
        ];
        if (! empty($validated['password'])) {
            $data['password'] = $validated['password'];
        }
        $smtpConfiguration->update($data);

        return redirect()->route('admin.email-system.index')->with('success', __('SMTP configuration updated.'));
    }

    public function updateTemplate(Request $request, Template $template): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'subject' => ['nullable', 'string', 'max:500'],
            'content' => ['nullable', 'string'],
            'enabled' => ['boolean'],
        ]);

        $template->update($validated);

        return redirect()->route('admin.email-system.index')->with('success', __('Email template updated.'));
    }

    public function destroySmtp(SmtpConfiguration $smtpConfiguration): RedirectResponse
    {
        $smtpConfiguration->delete();

        return redirect()->route('admin.email-system.index')->with('success', __('SMTP configuration removed.'));
    }

    public function activateSmtp(SmtpConfiguration $smtpConfiguration): RedirectResponse
    {
        SmtpConfiguration::query()->update(['is_active' => false]);
        $smtpConfiguration->update(['is_active' => true]);

        return redirect()->route('admin.email-system.index')->with('success', __('SMTP configuration activated.'));
    }

    /**
     * Send a test email using a specific template's content.
     */
    public function testTemplate(Request $request, Template $template): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $smtp = SmtpConfiguration::getActive();
        if (! $smtp) {
            return response()->json(['message' => __('No active SMTP configuration. Please activate one first.')], 422);
        }

        try {
            // Render exactly what a customer would get — layout, sample placeholder
            // values and all. A test send that delivered the bare prose row would
            // preview something nobody ever receives.
            $rendered = $this->mailService->renderTemplate($template, $this->sampleReplacements($template));

            $this->mailService->sendRaw($smtp, $validated['email'], '[Test] '.$rendered['subject'], $rendered['html']);

            return response()->json(['message' => __('Test email sent successfully.')]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Stand-in values for a test send, keyed by the placeholders the template declares.
     *
     * Every value is produced by the same helper the real call site uses — Romania for the
     * dates, TicketLabels for the two ticket enums, greetingName for the name after
     * "Salut," — so this screen previews the email a customer actually receives. It matters
     * more here than it looks: the admin reads the row's own `description` on the same
     * page, and that documentation says the dates and the enums arrive in Romanian.
     *
     * @return array<string, string>
     */
    private function sampleReplacements(Template $template): array
    {
        // {{user_name}} greets the recipient in most templates and identifies the customer
        // to an admin in these two, which is the same split the call sites make.
        $identifiesACustomer = in_array($template->slug, ['support_ticket_admin_new', 'support_ticket_reply_admin'], true);

        $known = [
            'app_name' => config('saas.app_name') ?: config('app.name'),
            'user_name' => $identifiesACustomer ? 'Ana Popescu' : Romania::greetingName('Ana Popescu'),
            'user_email' => 'ana.popescu@example.com',
            'staff_name' => 'Mihai Ionescu',
            'inviter_name' => 'Ana Popescu',
            'organization_name' => 'Cabinet Dentar Sanident',
            'plan_name' => 'Pro',
            'old_plan' => 'Start',
            'new_plan' => 'Pro',
            'billing_cycle' => 'month',
            'amount' => '129,00',
            'currency' => 'RON',
            'starts_at' => Romania::longDate(now()),
            'ends_at' => Romania::longDate(now()->addMonth()),
            'next_renewal' => Romania::longDate(now()->addMonth()),
            'trial_ends_at' => Romania::longDate(now()->addDays(3)),
            'days_remaining' => '3',
            'expires_minutes' => '15',
            'expires_days' => '7',
            'ticket_id' => '1042',
            'ticket_subject' => 'Nu pot conecta numărul de WhatsApp',
            'ticket_priority' => TicketLabels::priority('high'),
            'ticket_message' => "Bună ziua,\n\nAm încercat să conectez numărul, dar primesc o eroare.",
            'reply_message' => "Bună ziua,\n\nAm verificat contul și am reactivat conexiunea.",
            'new_status' => TicketLabels::status('in_progress'),
        ];

        $placeholders = $this->mailService->templateMeta($template)['placeholders'] ?? [];
        if (! is_array($placeholders)) {
            $placeholders = [];
        }

        $replacements = [];
        foreach ($placeholders as $placeholder) {
            if (! is_string($placeholder) || $placeholder === '') {
                continue;
            }

            $replacements[$placeholder] = match (true) {
                isset($known[$placeholder]) => (string) $known[$placeholder],
                str_ends_with($placeholder, '_url') => url('/'),
                default => '['.$placeholder.']',
            };
        }

        return $replacements;
    }

    /**
     * Send a test email using the active SMTP configuration.
     */
    public function testEmail(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $smtp = SmtpConfiguration::getActive();
        if (! $smtp) {
            if ($request->wantsJson()) {
                return response()->json(['message' => __('No active SMTP configuration. Please activate one first.')], 422);
            }
            throw ValidationException::withMessages(['email' => [__('No active SMTP configuration.')]]);
        }

        try {
            $appName = config('app.name');
            $this->mailService->sendRaw(
                $smtp,
                $validated['email'],
                __('Test email from :app', ['app' => $appName]),
                '<p>'.__('This is a test email. Your SMTP configuration is working.').'</p>'
            );
            if ($request->wantsJson()) {
                return response()->json(['message' => __('Test email sent successfully.')]);
            }

            return redirect()->route('admin.email-system.index')->with('success', __('Test email sent.'));
        } catch (\Throwable $e) {
            if ($request->wantsJson()) {
                return response()->json(['message' => $e->getMessage()], 500);
            }
            throw ValidationException::withMessages(['email' => [$e->getMessage()]]);
        }
    }
}
