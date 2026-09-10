<?php

namespace Tests\Feature\ProductionHardening;

use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentTemplate;
use App\Modules\Documents\Models\DocumentVersion;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Services\PrivateFileStore;
use App\Services\PrivateStorageManager;
use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\Finder\Finder;
use Tests\Concerns\FakesPrivateDisk;
use Tests\TestCase;

/**
 * What keeps a private file private is a convention. This makes it a mechanism.
 *
 * Every document, offer PDF, mail attachment and scanned contract in this
 * product is safe for exactly one reason: no line of code has ever asked the
 * private disk for a URL. Nothing enforces that. There is no interface that
 * forbids it, no linter rule, no type that will not compile. One developer who
 * needs to show a PDF in an iframe, finds `->url()` on the disk they already
 * have, and ships it, publishes every file that disk holds.
 *
 * That is not hypothetical and it is not a slippery-slope argument. It has
 * already happened once in this codebase, in app/Jobs/GenerateWorkspaceExportJob,
 * which calls Storage::temporaryUrl() on the default disk — `local`, the private
 * one — and emails the result. The link it mints is checked by
 * Illuminate\Filesystem\ServeFile, which validates the signature and NOTHING
 * else: no session, no user, no workspace. That job is outside this test's scope
 * because it is outside app/Modules, and it is named here so nobody mistakes the
 * scope for a clean bill of health.
 *
 * Worth knowing before reading the assertions, because it is the opposite of
 * what most people assume: `->url()` on the private disk does NOT throw today.
 * FilesystemAdapter::url() falls through to getLocalUrl() for a local adapter,
 * which returns the string "/storage/{path}" — no signature, no exception, no
 * warning. It happens to 404 right now only because storage/app/private is not
 * the directory public/storage is symlinked to. Point the private disk at R2 in
 * Stage 6 and the same call returns a real bucket URL against a real object.
 * The failure mode is silent in development and total in production.
 *
 * So the rule is enforced here, by reading the source, rather than trusted.
 *
 * Scope is app/Modules on purpose: that is where every private file in the
 * product is written and read, and it is where the next feature will be written.
 */
class PrivateStorageBoundaryTest extends TestCase
{
    use FakesPrivateDisk, RefreshDatabase;

    /**
     * Minting a URL for a stored file. All three are a way to hand someone bytes
     * without going through a controller that could have checked who they are.
     */
    private const URL_METHODS = ['url', 'temporaryUrl', 'temporaryUploadUrl'];

    /**
     * The resolver is not taken on trust — it is proved.
     *
     * Thirteen test files now fake `$store->diskName()` instead of the literal
     * they used to name. That indirection is only worth anything if the answer is
     * the disk the store really writes to. If diskName() ever returned a name the
     * writes do not go to, every one of those suites would quietly start writing
     * to the developer's real storage/app and passing while it did — the exact
     * silent-green failure the indirection exists to prevent.
     *
     * So: fake what diskName() says, write a real file through the real store,
     * and require it to be on the fake. A wrong answer fails here, loudly, in one
     * place, instead of being invisible in thirteen.
     */
    public function test_the_disk_the_store_names_is_the_disk_the_store_writes_to(): void
    {
        $disk = $this->fakePrivateDisk();
        $entry = app(PrivateFileStore::class)->put('proof', 'factura.pdf', 'application/pdf', 'PDF-CONTENT');

        $this->assertTrue(
            $disk->exists($entry['path']),
            'PrivateFileStore::diskName() named a disk that its own put() did not write to. '.
            'Storage::fake() swaps a disk BY NAME, so this does not merely fail here: every test '.
            'that fakes the private disk is now writing to the real filesystem under storage/app '.
            'and passing while it does. Fix diskName() so it answers with the disk write() uses.'
        );

        $this->assertSame(
            $this->privateDiskName(),
            $entry['disk'],
            'The entry reports a different disk from the one the store says it writes to. The '.
            'entry is what gets persisted next to the path, so a caller that believes it would '.
            'later look for the file in the wrong place.'
        );
    }

    /**
     * The store never hands out the disk itself.
     *
     * This is the assertion that makes the source scan below tractable. A scan
     * can see `Storage::disk('local')->url(...)`; it cannot see
     * `$this->files->disk()->url(...)`, because it has no idea what
     * `$this->files` is. So the handle is not obtainable in the first place:
     * every public method of PrivateFileStore returns a string, an array, or
     * nothing, and none returns a Filesystem.
     *
     * That is deliberately unlike StorageManager, which has a public disk()
     * returning a Filesystem — correctly, because everything it holds is meant to
     * be world-readable. Copying that shape here is how the private side would
     * acquire the same hole.
     *
     * Note this only closes the door from THIS class. Anyone can still write
     * Storage::disk('local') themselves, and the scan below is what covers that.
     * The two assertions are complements, not duplicates.
     */
    public function test_the_private_file_store_never_hands_out_the_disk_itself(): void
    {
        $handleTypes = [Filesystem::class, Cloud::class, FilesystemAdapter::class];

        foreach ((new ReflectionClass(PrivateFileStore::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $type = $method->getReturnType();

            $this->assertTrue(
                $type instanceof ReflectionNamedType,
                "PrivateFileStore::{$method->getName()}() has no declared return type. Every ".
                'method here needs one, because this test reads return types to prove the private '.
                'disk handle never leaves the class.'
            );

            $this->assertNotContains(
                ltrim($type->getName(), '\\'),
                $handleTypes,
                "PrivateFileStore::{$method->getName()}() returns a filesystem handle, so any ".
                'caller can now do ->url() or ->temporaryUrl() on the private disk and no test in '.
                'this file can see it happen. The whole point of this class is that callers get '.
                'bytes, paths and disk names — never the disk. If a caller needs an operation '.
                'this class does not offer, add a method that performs it; do not return the disk. '.
                "See PrivateFileStore's class docblock for why a private file must never gain a ".
                'URL that works without our permission check.'
            );
        }
    }

    /**
     * The private disk's configuration does not carry a URL.
     *
     * A 'url' key is not decoration. FilesystemAdapter::getLocalUrl() checks it
     * first and, when set, returns it concatenated with the path — so one line in
     * config/filesystems.php turns every stored invoice into a link, with no code
     * change anywhere and nothing in a diff that looks like a security decision.
     * On the s3 driver the same key is what makes ->url() return a working bucket
     * address rather than a guess.
     *
     * The `public` disk has one, and should: logos and favicons are served by
     * nginx with no session precisely because that is what they are for. This
     * asserts the two disks stay different in the one way that matters.
     */
    public function test_the_private_disk_configuration_carries_no_url(): void
    {
        $name = $this->privateDiskName();
        $config = config("filesystems.disks.{$name}");

        $this->assertIsArray($config, "The private disk [{$name}] is not configured at all.");

        $this->assertArrayNotHasKey(
            'url',
            $config,
            "The [{$name}] disk has a 'url' key. FilesystemAdapter::url() returns that base ".
            'concatenated with the path, which means every private file — invoices, signed '.
            'contracts, scanned IDs — now has an address that resolves without passing through '.
            'any controller, and therefore without any workspace check. If a private file needs '.
            'to be reachable from a browser, the answer is a route that resolves the tenant '.
            'first, which is what client.documents.file already is. It is not a base URL on the '.
            'disk. Compare the `public` disk, which has one on purpose.'
        );

        $this->assertNotSame(
            'public',
            $config['visibility'] ?? null,
            "The [{$name}] disk declares 'visibility' => 'public'. On an S3-compatible driver ".
            'that sets public-read on every object written, which makes the bucket policy '.
            'irrelevant and the files world-readable.'
        );
    }

    /**
     * Nobody under app/Modules mints a URL for a private file.
     *
     * Reads the source rather than the behaviour, because there is no behaviour
     * to observe until the day somebody adds the call, and by then it is in
     * production.
     *
     * Comments are stripped before matching, via token_get_all, so the long
     * docblock in PrivateFileStore that discusses temporaryUrl() by name does not
     * trip its own rule. That matters more than it sounds: a scan that cannot be
     * written about honestly in comments is a scan people work around.
     */
    public function test_no_module_mints_a_url_for_a_private_file(): void
    {
        $violations = [];
        $scanned = 0;

        foreach ($this->modulePhpFiles() as $file) {
            $path = $file->getRealPath();
            $relative = 'app/Modules'.substr($path, strlen(app_path('Modules')));
            $scanned++;

            foreach ($this->violationsIn((string) file_get_contents($path)) as $line => $why) {
                $violations[] = "{$relative}:{$line} — {$why}";
            }
        }

        // A scan that found no files reports no violations, which reads exactly
        // like a clean codebase. The modules directory has hundreds of files; if
        // this ever drops to a handful, the Finder is looking in the wrong place
        // and every assertion below is vacuous.
        $this->assertGreaterThan(
            200,
            $scanned,
            "Only {$scanned} files were scanned under app/Modules. The scan is not looking where ".
            'it thinks it is, so its clean result means nothing.'
        );

        $this->assertSame(
            [],
            $violations,
            "A module is minting a URL for a file that must not have one:\n\n".
            implode("\n", $violations).
            "\n\nWHY THIS IS A TEST AND NOT A REVIEW COMMENT: a URL for a private file is read by ".
            'Illuminate\\Filesystem\\ServeFile, or by the object store directly. Neither knows '.
            'what a workspace is. Whoever holds the link reads the file — a different tenant, a '.
            'former employee, anyone the link is forwarded to — and there is no log of it and no '.
            "way to take it back.\n\n".
            'Note that ->url() on the private disk does not throw today. It returns "/storage/" '.
            'plus the path, which happens to 404 only because storage/app/private is not the '.
            'directory that is symlinked. When the private disk becomes R2 the same call returns '.
            "a URL that works.\n\n".
            'The way to let a person read a private file is a controller that resolves the '.
            'workspace and streams the bytes — DocumentController::file() and '.
            'AttachmentController are both already there, and either is a shorter change than '.
            'the one that caused this failure.'
        );
    }

    /**
     * A disk named on a row can be resolved, whether or not it is the default.
     *
     * The R2 private disk exists in no config file. It is created at runtime by
     * PrivateStorageManager::diskName(), as a Config::set side effect of
     * answering "which disk do I write to". Writes therefore work.
     *
     * Reads do not call it. They take the disk recorded on the row, which after
     * a migration is a name diskName() is not currently answering — and
     * Storage::disk() does not fall back for an unconfigured name, it raises
     * InvalidArgumentException. Every one of DocumentController::file(),
     * VersionController::show(), OfficeController::download(),
     * OfferController::pdf() and AttachmentController::show() reaches the disk
     * that way, so the first download after the switch is a 500 where the call
     * site is written to expect a 404 — and the second, and every one after.
     *
     * The failure is worst precisely when it is least visible: it needs a row
     * naming the non-default disk, which no test fixture creates until a real
     * migration has run.
     */
    public function test_a_private_disk_resolves_even_when_it_is_not_the_default(): void
    {
        $target = PrivateStorageManager::PRIVATE_DISK_MAP['storage_r2'];

        // Deliberately NOT Storage::fake(): a fake registers the disk by name,
        // which is the very thing this test is asserting happens without one.
        Config::set("filesystems.disks.{$target}", null);

        IntegrationConfig::create([
            'provider' => 'storage_r2',
            'label' => 'Cloudflare R2',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => [
                'account_id' => 'abc123',
                'access_key_id' => 'key',
                'secret_access_key' => 'secret',
                'bucket' => 'smartuno-private',
            ],
        ]);

        app(PrivateStorageManager::class)->ensureDisk($target);

        $config = config("filesystems.disks.{$target}");

        $this->assertIsArray(
            $config,
            "Reading a file recorded on [{$target}] still raises InvalidArgumentException, because ".
            'that disk is configured only as a side effect of asking which disk to WRITE to. Every '.
            'download of a migrated file is a 500.'
        );

        $this->assertSame('s3', $config['driver'] ?? null);

        // The same rule the configured private disk lives under. A disk that
        // appears at runtime must not arrive with the one key that publishes it.
        $this->assertArrayNotHasKey(
            'url',
            $config,
            "The [{$target}] disk was configured with a 'url' key, so ->url() on it now returns a ".
            'working bucket address for every private file in the product.'
        );
    }

    public function test_an_unknown_disk_name_is_not_invented(): void
    {
        app(PrivateStorageManager::class)->ensureDisk('whatever_the_row_said');

        // A name that reached a row without passing through setProvider() is not
        // one we should be manufacturing a bucket for. Laravel rejecting it is
        // the right answer.
        $this->assertNull(config('filesystems.disks.whatever_the_row_said'));
    }

    /** Every PHP file under app/Modules. */
    private function modulePhpFiles(): Finder
    {
        return Finder::create()->files()->in(app_path('Modules'))->name('*.php');
    }

    /**
     * The source as statements, one per element, whitespace collapsed.
     *
     * Statements rather than lines, because a line is not the unit the rule is
     * about. Written across two lines —
     *
     *     Storage::disk('local')
     *         ->url($path);
     *
     * — a line scan sees `->url(` preceded by nothing and waves it through. The
     * shape is not exotic; it is what a formatter produces the moment the call
     * grows a second argument. Splitting on the `;` TOKEN rather than on the
     * character also means a semicolon inside a string literal cannot cut a
     * statement in half.
     *
     * Comments are dropped here, so the long docblocks in this file and in
     * PrivateFileStore that discuss temporaryUrl() by name do not trip the rule
     * they describe. A scan that cannot be written about honestly in comments is
     * a scan people work around.
     *
     * @return array<int, array{int, string}> [line the statement starts on, text]
     */
    private function statements(string $source): array
    {
        $statements = [];
        $text = '';
        $startedAt = 1;

        foreach (token_get_all($source) as $token) {
            if (! is_array($token)) {
                if ($token !== ';') {
                    $text .= $token;

                    continue;
                }

                if (trim($text) !== '') {
                    $statements[] = [$startedAt, (string) preg_replace('/\s+/', ' ', trim($text))];
                }

                $text = '';

                continue;
            }

            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML], true)) {
                continue;
            }

            if (trim($text) === '' && $token[0] !== T_WHITESPACE) {
                $startedAt = $token[2];
            }

            $text .= $token[1];
        }

        if (trim($text) !== '') {
            $statements[] = [$startedAt, (string) preg_replace('/\s+/', ' ', trim($text))];
        }

        return $statements;
    }

    /**
     * Everything that names the private disk in this file.
     *
     * The literal is the easy half. The rest is what a scan matching only the
     * literal cannot see, and each of these is a shape somebody reaches for
     * precisely because repeating a magic string felt wrong:
     *
     *   - `$store->diskName()` — asked of the resolver, so the rule follows
     *     Stage 6 wherever the resolver points.
     *   - `self::DEFAULT_DISK`, `PrivateStorageManager::LOCAL_DISK` and the
     *     entries of PRIVATE_DISK_MAP — the constants the two storage classes
     *     already answer with, which is what a careful developer would use.
     *   - a local constant declared in the same file, then used as `self::DISK`.
     *   - a variable or property handed the name earlier in the file.
     *
     * @param  array<int, array{int, string}>  $statements
     */
    private function privateDiskNameExpression(array $statements): string
    {
        $private = preg_quote($this->privateDiskName(), '/');

        $alternatives = [
            '[\'"]'.$private.'[\'"]',
            '[^()]*diskName\(\)',
            '(?:self|static|parent|\w+)::(?:DEFAULT_DISK|FALLBACK_DISK|LOCAL_DISK|PRIVATE_DISK)\b',
            '(?:self|static|parent|\w+)::PRIVATE_DISK_MAP\[[^\]]*\]',
        ];

        foreach ($statements as [, $text]) {
            if (preg_match('/(\$\w+(?:->\w+)*)\s*=\s*[\'"]'.$private.'[\'"]/', $text, $m)) {
                $alternatives[] = preg_quote($m[1], '/');
            }

            if (preg_match('/(\$\w+(?:->\w+)*)\s*=\s*[^=]*diskName\(\)/', $text, $m)) {
                $alternatives[] = preg_quote($m[1], '/');
            }

            if (preg_match('/const\s+(\w+)\s*=\s*[\'"]'.$private.'[\'"]/', $text, $m)) {
                $alternatives[] = '(?:self|static|parent|\w+)::'.preg_quote($m[1], '/').'\b';
            }
        }

        return '(?:'.implode('|', array_unique($alternatives)).')';
    }

    /**
     * Variables and properties in this file that hold the private disk itself.
     *
     * A property is the one worth spelling out. Caching the handle —
     * `$this->handle = Storage::disk(self::DISK)` in a constructor, then
     * `$this->handle->url($path)` two hundred lines later — is ordinary, tidy
     * code, and it puts the call and the disk it is made on in different methods
     * where no reading of a single statement can connect them. That is why the
     * assignment is tracked rather than the call site alone.
     *
     * @param  array<int, array{int, string}>  $statements
     * @return array<int, string>
     */
    private function handlesHoldingThePrivateDisk(array $statements, string $nameExpr): array
    {
        $holders = [];

        foreach ($statements as [, $text]) {
            if (preg_match('/(\$\w+(?:->\w+)*)\s*=\s*Storage::(?:disk\(\s*'.$nameExpr.'\s*\)|build\()/', $text, $m)) {
                $holders[] = $m[1];
            }
        }

        return array_values(array_unique($holders));
    }

    /**
     * Line number => why, for every URL call in this source that reaches a
     * private file.
     *
     * The shapes caught:
     *
     * 1. The bare facade — Storage::temporaryUrl(...). filesystems.default is
     *    env('FILESYSTEM_DISK', 'local'), so the facade with no ->disk() IS the
     *    private disk. This is precisely the shape of the real bug in
     *    GenerateWorkspaceExportJob, which is why it is matched by name.
     * 2. Naming the private disk, by any of the routes privateDiskNameExpression()
     *    enumerates — literal, resolver, constant, variable.
     * 3. A variable or property holding either of those.
     * 4. Storage::build([...]) — a disk assembled inline. Its configuration is
     *    not in config/filesystems.php, so nothing here or in review can tell
     *    whether it is the private bucket. Flagged for that reason alone.
     *
     * temporaryUrl and temporaryUploadUrl are additionally banned outright
     * anywhere under app/Modules, whatever the receiver. That is stricter than
     * shapes 1-4 and deliberately so: the only disk a module could legitimately
     * want a temporary URL for is the public one, and the public disk is already
     * readable without one — so a presigned URL in a module is either pointless
     * or it is reaching for a private file. When Stage 6 needs one, it will need
     * to change this test to get it, which is the review this deserves.
     *
     * What it still cannot see, said plainly so the green is not read as more
     * than it is: a disk handed in from ANOTHER file — injected through a
     * constructor, returned by a service, pulled from a container binding. That
     * needs dataflow across files, which this is not. It is why
     * test_the_private_file_store_never_hands_out_the_disk_itself() exists: it
     * closes the one route by which this codebase's private disk could be
     * obtained that way.
     *
     * @return array<int, string>
     */
    private function violationsIn(string $source): array
    {
        $found = [];
        $statements = $this->statements($source);
        $nameExpr = $this->privateDiskNameExpression($statements);
        $handles = $this->handlesHoldingThePrivateDisk($statements, $nameExpr);
        $diskCall = 'Storage::disk\(\s*'.$nameExpr.'\s*\)';

        foreach ($statements as [$number, $text]) {
            foreach (['temporaryUrl', 'temporaryUploadUrl'] as $method) {
                if (str_contains($text, $method.'(')) {
                    $found[$number] = "{$method}() has no place in a module — it hands out bytes ".
                        'without a controller in the way.';
                }
            }

            foreach (self::URL_METHODS as $method) {
                if (str_contains($text, 'Storage::'.$method.'(')) {
                    $found[$number] = "Storage::{$method}() with no ->disk() uses the default disk, ".
                        'which this application configures as the private one.';
                }
            }

            foreach (self::URL_METHODS as $method) {
                $call = '->'.$method.'(';
                $offset = 0;

                while (($at = strpos($text, $call, $offset)) !== false) {
                    $receiver = rtrim(substr($text, 0, $at));
                    $offset = $at + strlen($call);

                    if (preg_match('/'.$diskCall.'$/', $receiver)) {
                        $found[$number] = "{$method}() called on the private disk.";
                    }

                    if (preg_match('/Storage::build\(.*\)$/', $receiver)) {
                        $found[$number] = "{$method}() called on a disk built inline. Nothing here ".
                            'or in review can tell which bucket an inline config names; declare it '.
                            'in config/filesystems.php so it can be read.';
                    }

                    foreach ($handles as $holder) {
                        if (preg_match('/'.preg_quote($holder, '/').'$/', $receiver)) {
                            $found[$number] = "{$method}() called on {$holder}, which holds the private disk.";
                        }
                    }
                }
            }
        }

        return $found;
    }

    /**
     * No payload ships the storage path of a private file.
     *
     * A path is not a secret in the way a password is, and that is exactly what
     * makes this easy to wave through. It becomes one the moment the private disk
     * gains any way to be addressed — the 'url' key above, a bucket someone flips
     * to public, a signed storage.local link minted somewhere else in the app. A
     * path already sitting in a browser's page source, in a bug report, in a
     * screenshot, or in a support ticket is then a live link, retroactively, for
     * every file the product has ever shown a list of.
     *
     * The frontend has no use for it either: every screen addresses a document by
     * uuid, through a route that resolves the workspace first. The path was in
     * the payload because `Document` was passed whole, not because anything asked
     * for it.
     *
     * Both payloads that used to leak have been closed at the call site —
     * DocumentController::index() maps every row through makeHidden(['path',
     * 'disk']) and list() selects columns by name. Both are correct. Neither is a
     * property: they hold for those two queries and for nothing else.
     *
     * The gap they leave is not theoretical. index() also had to narrow its
     * eager load to `versions:id,document_id,version,name`, because the bare
     * `versions` it used to have was pulling whole DocumentVersion rows — every
     * superseded file's path — into the same page, through a relation nobody was
     * thinking about while writing the document's own payload. That is the third
     * leak, it already happened once, and makeHidden on the parent did not stop
     * it. $hidden on the model would have.
     *
     * So it is asserted as the property. $hidden is enforced by Eloquent at
     * serialisation, which means it covers the next controller, the next Inertia
     * page, the next JSON endpoint and the next eager load — none of which exist
     * yet, and none of which will remember this rule.
     *
     * It costs nothing to add: $hidden suppresses serialisation only, so
     * $document->path keeps working everywhere in PHP, and nothing in
     * resources/js reads either key.
     */
    public function test_no_payload_can_ship_the_storage_path_of_a_private_file(): void
    {
        $models = [
            Document::class,
            DocumentVersion::class,
            DocumentTemplate::class,
        ];

        foreach ($models as $class) {
            $model = new $class;
            $serialised = array_keys($model->setRawAttributes([
                'path' => 'documents/secret.pdf',
                'disk' => 'local',
            ])->toArray());

            $this->assertNotContains(
                'path',
                $serialised,
                "{$class} serialises `path` into every payload it appears in. Add 'path' to its ".
                "\$hidden (or set \$visible without it).\n\n".
                'The two payloads that leaked are already closed at the call site — index() uses '.
                'makeHidden(), list() selects columns by name — and both are correct. Neither is '.
                'a property: each holds for one query and for nothing else. index() also had to '.
                'narrow its eager load to versions:id,document_id,version,name, because the bare '.
                "`versions` was dragging every superseded file's path into the same page through ".
                'a relation nobody was thinking about while writing the payload. That is the '.
                "third leak, it already happened once, and makeHidden on the parent did not stop it.\n\n".
                '$hidden suppresses serialisation only — $document->path keeps working everywhere '.
                'in PHP — and nothing in resources/js reads the key. Every screen addresses a '.
                'document by uuid, through a route that resolves the workspace first.'
            );

            $this->assertNotContains(
                'disk',
                $serialised,
                "{$class} serialises `disk` into every payload. It is half of the same address ".
                'as `path` and is no more use to a browser than the other half.'
            );
        }
    }

    /**
     * The scan can actually see a violation.
     *
     * A source-scanning test that returns an empty list is indistinguishable from
     * one whose regex stopped matching years ago — a rename, a reformat, a
     * Finder that silently found no files. This feeds it the four shapes and
     * requires it to object to each, so "no violations" keeps meaning something.
     */
    public function test_the_scan_recognises_each_shape_it_is_meant_to_catch(): void
    {
        $disk = $this->privateDiskName();

        $samples = [
            'the bare facade' => '<?php Storage::temporaryUrl($path, now());',
            'the disk named literally' => '<?php Storage::disk(\''.$disk.'\')->url($path);',
            'the disk from the resolver' => '<?php Storage::disk($store->diskName())->url($path);',
            'a variable holding the disk' => '<?php $d = Storage::disk(\''.$disk.'\'); return $d->url($path);',
            'a presigned upload' => '<?php $this->anything->temporaryUploadUrl($path, now());',

            // The five below defeated the line-based scan this replaced. Each is
            // ordinary code somebody writes for a reason that has nothing to do
            // with wanting a public file, which is what makes them the ones to
            // pin.
            'the call wrapped onto a second line' => "<?php Storage::disk('".$disk."')\n    ->url(\$path);",
            'the name in a local constant' => '<?php class X { private const D = \''.$disk.'\'; '.
                'public function f() { return Storage::disk(self::D)->url($p); } }',
            'the name in a variable' => '<?php $name = \''.$disk.'\'; return Storage::disk($name)->url($p);',
            'the disk cached on a property' => '<?php $this->handle = Storage::disk(\''.$disk.'\'); '.
                'return $this->handle->url($p);',
            'a disk built inline' => '<?php return Storage::build([\'driver\' => \'local\'])->url($p);',
        ];

        foreach ($samples as $shape => $code) {
            $this->assertNotSame(
                [],
                $this->violationsIn($code),
                "The scan no longer recognises {$shape}. Until it does, this whole test file is ".
                'reporting a clean codebase it cannot actually see.'
            );
        }

        // And it does not object to the public disk, which is what StorageManager
        // hands out and what avatars, logos and WhatsApp previews are served from.
        // A scan that cried wolf here would be turned off within a week.
        $this->assertSame(
            [],
            $this->violationsIn('<?php return $this->storageManager->disk()->url($path);'),
            'The scan flagged a URL on the public disk. That disk exists to be published; '.
            'flagging it makes the test noise and gets it deleted.'
        );

        $this->assertSame(
            [],
            $this->violationsIn('<?php return Storage::disk(\'public\')->url($path);'),
            'The scan flagged Storage::disk(\'public\')->url(). Naming the public disk explicitly '.
            'is the clearest way to write that call, and it is what every avatar and logo in the '.
            'product does.'
        );
    }

    /**
     * The private disk is not the public one, and does not live inside it.
     *
     * Both halves are worth pinning. If diskName() ever answered `public` the
     * whole class becomes a very elaborate way of publishing invoices. And two
     * differently-named disks rooted at the same directory is the same outcome
     * reached more quietly: writes go through PrivateFileStore, land under
     * storage/app/public anyway, and nginx serves them.
     */
    public function test_the_private_disk_is_not_the_disk_the_web_server_publishes(): void
    {
        $this->assertNotSame(
            'public',
            $this->privateDiskName(),
            'The private disk resolved to `public`, which is symlinked into the web root and '.
            'served with no authentication. Every invoice in the product is now a public URL.'
        );

        $this->assertNotSame(
            Storage::disk('public')->path(''),
            Storage::disk($this->privateDiskName())->path(''),
            'The private and public disks share a root directory, so a file written privately '.
            'lands inside the directory nginx publishes.'
        );
    }
}
