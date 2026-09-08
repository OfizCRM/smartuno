<?php

namespace App\Modules\Email\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ecommerce\Services\StoreUrlGuard;
use App\Modules\Email\Services\MailboxSettings;
use App\Modules\Email\Services\MailboxTester;
use App\Modules\Shared\Models\ChannelAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The firm's mailbox: one address, read over IMAP and replied to over its own
 * SMTP, so a customer's thread stays a thread.
 *
 * Deliberately separate from the campaign SMTP at /app/broadcasts/email-server.
 * A firm sends its newsletter from marketing@ and talks to customers from
 * office@, and mixing a bulk sender's reputation into real correspondence is a
 * bad idea besides.
 */
class MailboxController extends Controller
{
    public function __construct(private readonly MailboxTester $tester) {}

    public function index(Request $request): Response
    {
        $workspaceId = (int) $request->user()->workspace_id;

        return Inertia::render('client/Settings/Mailbox', [
            'mailbox' => MailboxSettings::toForm(MailboxSettings::forWorkspace($workspaceId)),
            'pollChoices' => MailboxSettings::POLL_CHOICES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspaceId = (int) $request->user()->workspace_id;
        $existing = MailboxSettings::forWorkspace($workspaceId);
        $validated = $this->validated($request, $existing !== null);

        $credentials = $this->credentialsFrom($validated, $existing);

        $account = $existing ?? new ChannelAccount([
            'workspace_id' => $workspaceId,
            'channel' => 'email',
            'provider' => MailboxSettings::PROVIDER,
        ]);

        $account->fill([
            'display_name' => $validated['email'],
            'status' => 'active',
        ]);
        $account->setAttribute('credentials', $credentials);
        $account->meta_json = array_merge($account->meta_json ?? [], [
            'poll_minutes' => (int) $validated['poll_minutes'],
            'initial_days' => (int) $validated['initial_days'],
            // A save is the tenant asserting the settings are right; clear the
            // last failure so a stale error does not sit on a working mailbox.
            'last_error' => null,
        ]);
        $account->save();

        return back()->with('success', __('Mailbox saved.'));
    }

    /** Connect and authenticate against both servers, without sending anything. */
    public function test(Request $request): JsonResponse
    {
        $workspaceId = (int) $request->user()->workspace_id;
        $existing = MailboxSettings::forWorkspace($workspaceId);
        $validated = $this->validated($request, $existing !== null);
        $credentials = $this->credentialsFrom($validated, $existing);

        $result = $this->tester->test($credentials['imap'], $credentials['smtp']);

        // Record the outcome so the settings hub and the inbox can show a
        // mailbox that has stopped working, rather than going quiet.
        if ($existing) {
            $ok = $result['imap']['ok'] && $result['smtp']['ok'];
            $existing->status = $ok ? 'active' : 'error';
            $existing->meta_json = array_merge($existing->meta_json ?? [], [
                'last_error' => $ok ? null : trim($result['imap']['message'].' '.$result['smtp']['message']),
            ]);
            $existing->save();
        }

        return response()->json($result);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $account = MailboxSettings::forWorkspace((int) $request->user()->workspace_id);

        // Conversations keep their channel_account_id, so deleting the mailbox
        // would orphan every email thread. Detach them first: resolvedChannel()
        // then reads the channel off their messages and they stay findable.
        if ($account) {
            $account->conversations()->update(['channel_account_id' => null]);
            $account->delete();
        }

        return back()->with('success', __('Mailbox removed.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $hasStoredPasswords): array
    {
        $passwordRule = $hasStoredPasswords ? ['nullable', 'string', 'max:255'] : ['required', 'string', 'max:255'];

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:191'],
            'from_name' => ['nullable', 'string', 'max:128'],
            'imap.host' => ['required', 'string', 'max:255'],
            'imap.port' => ['required', 'integer', 'between:1,65535'],
            'imap.encryption' => ['required', Rule::in(['ssl', 'tls', 'none'])],
            'imap.username' => ['required', 'string', 'max:255'],
            'imap.password' => $passwordRule,
            'smtp.host' => ['required', 'string', 'max:255'],
            'smtp.port' => ['required', 'integer', 'between:1,65535'],
            'smtp.encryption' => ['required', Rule::in(['ssl', 'tls', 'none'])],
            'smtp.username' => ['required', 'string', 'max:255'],
            'smtp.password' => $passwordRule,
            'poll_minutes' => ['required', Rule::in(MailboxSettings::POLL_CHOICES)],
            'initial_days' => ['required', 'integer', 'between:0,90'],
        ]);

        // Both hosts are typed by the tenant and the server dials them, so they
        // get the same treatment as any other user-supplied host: no loopback,
        // no private range, no cloud metadata endpoint.
        foreach (['imap', 'smtp'] as $part) {
            $blocked = StoreUrlGuard::guardHost($validated[$part]['host']);
            if ($blocked !== null) {
                throw ValidationException::withMessages([
                    "{$part}.host" => $blocked,
                ]);
            }
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function credentialsFrom(array $validated, ?ChannelAccount $existing): array
    {
        $stored = $existing?->getAttribute('credentials') ?? [];

        $server = fn (string $part) => [
            'host' => $validated[$part]['host'],
            'port' => (int) $validated[$part]['port'],
            'encryption' => $validated[$part]['encryption'],
            'username' => $validated[$part]['username'],
            // An empty password field means "keep the one you have" — the page
            // never receives the stored password, so it cannot send it back.
            'password' => $validated[$part]['password'] ?: ($stored[$part]['password'] ?? ''),
        ];

        return [
            'email' => $validated['email'],
            'from_name' => $validated['from_name'] ?? '',
            'imap' => $server('imap'),
            'smtp' => $server('smtp'),
        ];
    }
}
