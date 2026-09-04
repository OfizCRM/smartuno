<?php

namespace App\Http\Controllers\Client\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateWorkspaceExportJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DataExportController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeExport($request);

        return Inertia::render('client/Settings/DataExport', [
            'status' => session('export_status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeExport($request);

        GenerateWorkspaceExportJob::dispatch($request->user()->id)
            ->onQueue('default');

        // A status token, not an English sentence: the page renders it through i18n.
        return back()->with('export_status', 'requested');
    }

    /**
     * The archive holds every contact's phone number and every message body in the
     * workspace, so it is administrator-only — the same gate Client\AuditLogController
     * puts on the audit trail.
     */
    private function authorizeExport(Request $request): void
    {
        $user = $request->user();

        if (! $user->client_id || ! $user->isClientAdministrator()) {
            abort(403, __('Only client administrators can export workspace data.'));
        }
    }
}
