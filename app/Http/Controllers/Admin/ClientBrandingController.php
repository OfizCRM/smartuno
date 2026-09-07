<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\StorageManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ClientBrandingController extends Controller
{
    public function __construct(private StorageManager $storage) {}

    public function update(Request $request, Client $client): RedirectResponse
    {
        // Route middleware alone was the only gate here, unlike every method in
        // ClientController; the policy check makes the two consistent.
        $this->authorizeForUser($request->user('admin'), 'update', $client);

        $validated = $request->validate([
            'logo' => ['nullable', 'image', 'max:2048'],
            'primary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'tagline' => ['nullable', 'string', 'max:255'],
            'custom_domain' => ['nullable', 'string', 'max:255'],
            'support_email' => ['nullable', 'email', 'max:255'],
        ]);

        if ($request->hasFile('logo')) {
            $file = $request->file('logo');
            $disk = $this->storage->diskName();
            // extension() guesses from the file's own mime type. The client-supplied
            // name must not decide it: a valid PNG uploaded as "shell.php" passes the
            // image rule, and stored under that name on the public disk it is served
            // straight back through the storage symlink for the webserver to execute.
            $path = $this->storage->prefixedPath('client-logos/'.Str::uuid().'.'.($file->extension() ?: 'png'));
            $this->storage->disk()->putFileAs(dirname($path), $file, basename($path));
            $validated['logo_path'] = $path;
            $validated['logo_disk'] = $disk;
        }
        unset($validated['logo']);

        $client->update(array_filter($validated, fn ($v) => $v !== null));

        return back()->with('success', __('Branding updated.'));
    }
}
