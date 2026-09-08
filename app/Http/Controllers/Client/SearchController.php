<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $query = trim($request->get('q', ''));

        if (strlen($query) < 2) {
            return response()->json(['results' => []]);
        }

        $user = $request->user();
        $results = [];

        // Navigation destinations. `admin` marks the ones whose controllers
        // abort for non-administrators (TeamController:19, AuditLogController:17) —
        // offering those to staff sent them to a 403 from the search box.
        //
        // NOTE: labels are matched in English, so a Romanian user searching
        // "setari" finds nothing. That needs the locale dictionary on the server
        // and is worth doing once the Settings hub exists and this list has to
        // cover everything that left the sidebar.
        $navItems = [
            ['label' => 'Dashboard', 'href' => route('client.dashboard'), 'icon' => 'LayoutDashboard'],
            ['label' => 'Inbox', 'href' => route('client.inbox.index'), 'icon' => 'Inbox'],
            ['label' => 'Contacts', 'href' => route('client.contacts.index'), 'icon' => 'Users'],
            ['label' => 'Campaigns', 'href' => route('client.campaigns.index'), 'icon' => 'Radio'],
            ['label' => 'Social media', 'href' => route('client.social.posts.index'), 'icon' => 'Share2'],
            ['label' => 'Automations', 'href' => route('client.automations.index'), 'icon' => 'Zap'],
            ['label' => 'Settings', 'href' => route('client.settings.index'), 'icon' => 'Settings'],
            ['label' => 'Support', 'href' => route('client.support.index'), 'icon' => 'LifeBuoy'],
            ['label' => 'Subscription', 'href' => route('client.subscription.show'), 'icon' => 'CreditCard'],
            ['label' => 'Billing History', 'href' => route('client.billing.index'), 'icon' => 'CreditCard'],
            ['label' => 'Pricing / Plans', 'href' => route('client.pricing'), 'icon' => 'Package'],
            ['label' => 'Profile', 'href' => route('client.profile.edit'), 'icon' => 'User'],
            ['label' => 'Two-Factor Auth', 'href' => route('client.profile.2fa'), 'icon' => 'Shield'],
            ['label' => 'Sessions', 'href' => route('client.profile.sessions'), 'icon' => 'Monitor'],
            ['label' => 'Notifications', 'href' => route('client.notifications.index'), 'icon' => 'Bell'],
            ['label' => 'Webhooks', 'href' => route('client.webhooks.index'), 'icon' => 'Webhook'],
            ['label' => 'API Tokens', 'href' => route('client.api-tokens.index'), 'icon' => 'Key'],
            ['label' => 'Team', 'href' => route('client.team.index'), 'icon' => 'Users', 'admin' => true],
            ['label' => 'Workspaces', 'href' => route('client.workspaces.index'), 'icon' => 'Layers', 'admin' => true],
            ['label' => 'Audit Log', 'href' => route('client.audit-log.index'), 'icon' => 'FileText', 'admin' => true],
        ];

        $isAdmin = $user?->isClientAdministrator() ?? false;

        $lower = strtolower($query);
        foreach ($navItems as $item) {
            if (($item['admin'] ?? false) && ! $isAdmin) {
                continue;
            }
            if (str_contains(strtolower($item['label']), $lower)) {
                unset($item['admin']);
                $results[] = array_merge($item, ['type' => 'page']);
            }
        }

        return response()->json(['results' => array_slice($results, 0, 10)]);
    }
}
