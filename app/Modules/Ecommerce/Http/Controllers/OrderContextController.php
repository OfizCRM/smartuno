<?php

namespace App\Modules\Ecommerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ecommerce\Models\EcommerceOrder;
use App\Modules\Shared\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderContextController extends Controller
{
    /**
     * Recent ecommerce orders for a contact — rendered in the Inbox sidebar.
     */
    public function index(Request $request, Contact $contact): JsonResponse
    {
        // current_workspace_id is not a column, so the old idiom always fell
        // through to workspace_id — see docs/traps.md.
        $workspaceId = (int) $request->user()->workspace_id;
        abort_unless((int) $contact->workspace_id === $workspaceId, 403);

        $scoped = fn () => EcommerceOrder::where('workspace_id', $workspaceId)
            ->where('contact_id', $contact->id);

        $orders = $scoped()
            ->latest('placed_at')
            ->take(5)
            ->get(['number', 'status', 'financial_status', 'fulfillment_status', 'currency', 'total', 'tracking_url', 'placed_at']);

        // The panel shows five; the tab badge says how many there are. Without a
        // total the badge could only ever count to five.
        return response()->json([
            'orders' => $orders,
            'total' => $scoped()->count(),
        ]);
    }
}
