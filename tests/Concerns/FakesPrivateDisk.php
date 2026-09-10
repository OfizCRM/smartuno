<?php

namespace Tests\Concerns;

use App\Modules\Shared\Services\PrivateFileStore;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Faking the disk private files actually go to, rather than the one they used to.
 *
 * Thirteen test files used to open with `Storage::fake('local')`. That literal
 * was correct and load-bearing — Storage::fake() swaps a disk BY NAME, so a test
 * that fakes the wrong name does not fail, it writes to the developer's real
 * storage/app and passes while it does. The problem is only that the literal is
 * a COPY of a decision that lives in PrivateFileStore::diskName(). The day that
 * method returns something else, thirteen files keep faking `local`, the code
 * under test keeps writing to the real private disk, and thirteen suites go on
 * passing green against files nobody is looking at.
 *
 * So the name is asked for instead of restated. `Storage::fake($store->diskName())`
 * follows the configuration; `Storage::fake('local')` restates it once and then
 * drifts. There is one reader of diskName() in the whole test suite — this trait —
 * for the same reason there is one in the application.
 *
 * The resolver is not taken on trust. PrivateStorageBoundaryTest writes a real
 * file through the store and asserts it lands on the disk this trait faked, so a
 * diskName() that answered wrongly would fail there loudly rather than quietly
 * turning every test that uses this trait into a no-op.
 */
trait FakesPrivateDisk
{
    /**
     * The disk private files go to, as PrivateFileStore itself defines it.
     *
     * Never a literal. That is the whole point of the trait.
     */
    protected function privateDiskName(): string
    {
        return app(PrivateFileStore::class)->diskName();
    }

    /**
     * Swap the private disk for an in-memory one, for the duration of the test.
     *
     * Call it in setUp() where `Storage::fake('local')` used to sit.
     */
    protected function fakePrivateDisk(): Filesystem
    {
        return Storage::fake($this->privateDiskName());
    }

    /**
     * The private disk itself, for asserting what a test's writes did.
     *
     * Stands in for `Storage::disk('local')` at assertion sites for the same
     * reason fakePrivateDisk() stands in for the fake: an assertion pointed at a
     * disk the code no longer writes to reports "file missing" and reads as a
     * real regression, or worse, reports "file present" about a leftover.
     */
    protected function privateDisk(): Filesystem
    {
        return Storage::disk($this->privateDiskName());
    }

    /**
     * The tripwire: this file is not on the disk the web server publishes.
     *
     * storage/app/public is symlinked into the web root and served by nginx with
     * no session, no workspace check and no authentication of any kind. A file
     * that reaches it is readable by anyone who can guess or is handed the path,
     * for as long as it is there — and the class of file these tests deal with is
     * invoices, signed contracts, scanned IDs and a tenant's inbound mail.
     *
     * Asserted at every site where a private file is written, because the way
     * this breaks is not a dramatic rewrite. It is one call site switched from
     * PrivateFileStore to StorageManager by someone who needed a URL and found
     * the class that hands them out.
     *
     * Deliberately NOT faking `public` first. Left real, a write that lands there
     * lands on the developer's actual filesystem and this still sees it; faked, a
     * write that escapes to the real public disk instead of the fake would slip
     * past. The less hermetic form is the more sensitive one.
     */
    protected function assertNotOnTheWebServersDisk(?string $path, string $what = 'This file'): void
    {
        if ($path === null) {
            return;
        }

        $this->assertFalse(
            Storage::disk('public')->exists($path),
            "{$what} is on the `public` disk, which is symlinked into the web root and served ".
            "with no authentication at all — anyone holding [{$path}] can read it. Private files ".
            'go through PrivateFileStore, never StorageManager. If you came here needing a URL, '.
            "read PrivateFileStore's class docblock before changing anything: the answer is a ".
            'controller that resolves the workspace first, not a disk that publishes.'
        );
    }
}
