<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\StorageManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class ClientBrandingController extends Controller
{
    public function __construct(private StorageManager $storage) {}

    public function update(Request $request, Client $client): RedirectResponse
    {
        // Route middleware alone was the only gate here, unlike every method in
        // ClientController; the policy check makes the two consistent.
        $this->authorizeForUser($request->user('admin'), 'update', $client);

        $validated = $request->validate([
            // SVG is accepted because StorageManager stores the SvgSanitizer
            // output for one rather than the uploaded bytes. 'image' rejects SVG
            // in Laravel 12 without allow_svg, and the mimes rule spells the
            // whole list out rather than resting on a framework default.
            'logo' => ['nullable', 'image:allow_svg', 'mimes:png,jpg,jpeg,gif,webp,svg', 'max:2048'],
            'primary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'tagline' => ['nullable', 'string', 'max:255'],
            'custom_domain' => ['nullable', 'string', 'max:255'],
            'support_email' => ['nullable', 'email', 'max:255'],
        ]);

        $supersededPath = null;
        $supersededDisk = null;

        $file = $request->file('logo');
        if ($file instanceof UploadedFile) {
            $stored = $this->storage->storeImageUpload($file, 'client-logos');

            // An SVG the sanitiser could not reduce to its allow-list. Reported
            // on the field rather than as a 500: it is the uploaded file that is
            // the problem, and a different export fixes it.
            if ($stored === null) {
                throw ValidationException::withMessages([
                    'logo' => __('This SVG could not be used. Export it again as a plain SVG, without scripts and without internal CSS, or upload a PNG instead.'),
                ]);
            }

            // Remembered, not deleted yet: the write above has to be on disk and
            // the new path committed to the row before the old file goes, or a
            // failure in between leaves logo_path naming nothing.
            $supersededPath = $client->logo_path;
            $supersededDisk = $client->logo_disk;

            $validated['logo_path'] = $stored['path'];
            $validated['logo_disk'] = $stored['disk'];
        }
        unset($validated['logo']);

        $client->update(array_filter($validated, fn ($v) => $v !== null));

        // Without this every admin re-upload left its predecessor on the object
        // store forever — billable, and unreachable through any screen.
        $this->storage->deleteStoredFile($supersededPath, $supersededDisk);

        return back()->with('success', __('Branding updated.'));
    }
}
