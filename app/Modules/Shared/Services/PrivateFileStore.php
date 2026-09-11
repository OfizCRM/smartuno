<?php

namespace App\Modules\Shared\Services;

use App\Services\PrivateStorageManager;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Files that must never have a public URL.
 *
 * Deliberately NOT StorageManager, which is the rule everywhere else in this
 * application: it resolves to the `public` disk, and that disk is symlinked into
 * the web root and served with no authentication at all. Logos, favicons and
 * WhatsApp previews need exactly that. An invoice, a scanned ID or a signed
 * contract is the class of file that must not have one.
 *
 * StorageManager's cloud disks are not the answer either, though not for the
 * reason this comment used to give ("they are declared public-read", which was
 * true of one disk out of four). What is actually true: `do_spaces` IS declared
 * public-read — 'visibility' => 'public' plus ['ACL' => 'public-read'], in both
 * config/filesystems.php and StorageManager::buildDiskConfig(). `s3` and
 * `wasabi` declare no visibility at all, so each object inherits whatever the
 * bucket policy happens to be, which is set outside this repository and cannot
 * be read from here. `r2` is pinned 'visibility' => 'private', but R2 ignores
 * per-object ACLs entirely, so its bucket's own public/private setting is the
 * only thing that decides. One disk is certainly world-readable, two are
 * unknowable from the code, and the fourth is decided in a dashboard — so none
 * of the four is a control this class could rest on.
 *
 * This is the mechanism only. How large a file may be, and who is allowed to
 * read one back, belong to the module that owns it: the email module caps a
 * message at 25 MB, the document library caps a workspace at its plan's storage
 * allowance, and those are not the same rule.
 */
class PrivateFileStore
{
    /**
     * `local` is storage/app/private: outside the web root, so no web server
     * hands one of these files out by path.
     *
     * It is not unreachable, though, and the comment that used to sit here said
     * the storage route needs a signature "which nothing in this application
     * ever mints". That is false. The disk sets 'serve' => true, so
     * FilesystemServiceProvider::serveFiles() registers GET /storage/{path} as
     * the route `storage.local` — live right now; `php artisan route:list`
     * prints it. Illuminate\Filesystem\ServeFile checks the URL signature and
     * NOTHING else: no session, no user, no workspace. And app/Jobs/
     * GenerateWorkspaceExportJob does mint such a link today —
     * Storage::temporaryUrl($path, now()->addHours(72)) on the default disk,
     * which .env pins to `local` — and emails it. Any path under
     * storage/app/private, this class's included, is one signature away from
     * being readable by whoever holds the link, for as long as it lasts.
     *
     * The claim that is true, and testable, is narrower: no path this class
     * returns is ever passed to temporaryUrl() or to route('storage.local').
     * `grep -rE "temporaryUrl|storage\.local" app/` finds exactly one hit, the
     * export job above, and its path is one it built itself. Every read of a
     * file stored here goes instead through a controller that resolves the
     * workspace first — or, for the Document Server, through an application
     * route signed per document after that check has already run
     * (OnlyOfficeSession::downloadUrl), which is a route that can carry checks
     * of its own. storage.local carries none. Hand it a document path and you
     * have published an unauthenticated cross-tenant link with a long fuse.
     *
     * Two constants below hold that same string, and they are deliberately not
     * one. They answer different questions, and the day they stop being equal is
     * the day this class earns its keep.
     *
     * FALLBACK_DISK below is the only one left, and it answers a different
     * question from "where do new files go". That one is now answered by
     * PrivateStorageManager, which reads the admin's setting.
     *
     * There WAS a second constant here, DEFAULT_DISK, holding the same literal
     * and meaning "where a file written from now on goes". Its docblock said
     * diskName() should come to return
     * app(PrivateStorageManager::class)->diskName() when the private disk moved
     * to R2 — and then the switch, the separate private bucket and the
     * connection test were all built while that one line was never changed.
     * Every private write kept going to the server disk with the panel happily
     * reporting R2, which on a host with no persistent storage means the files
     * are gone at the next deploy.
     *
     * So it is not a constant any more. A value that must change in step with a
     * runtime setting is not a constant; it is the setting, copied.
     */

    /**
     * The disk a file lives on when the caller has nothing to say about it.
     *
     * This one MUST stay the literal string 'local' forever, and it is separate
     * from whatever diskName() answers precisely so that moving the write target
     * cannot drag it along. Every row and every payload entry written before the
     * disk column existed is on `local`, there is no record of it anywhere, and
     * there is nothing to backfill from — so "no disk recorded" can only ever
     * mean `local`. Point this at R2 as well and every file the product has ever
     * stored becomes unreadable in one deploy.
     *
     * The name is load-bearing beyond production, too. Storage::fake() swaps a
     * disk BY NAME, and the suite fakes this one by name in a dozen files; a new
     * disk pointed at the same root would leave every one of them writing to the
     * developer's real storage/app, and passing while it did. That is also why
     * PrivateStorageManager::PRIVATE_DISK_MAP maps the local provider to 'local'
     * and gives R2 a name of its own rather than reusing this one.
     */
    private const FALLBACK_DISK = 'local';

    /**
     * The disk new files are written to.
     *
     * Shaped after StorageManager::diskName() — public, no arguments, returns a
     * name rather than a Filesystem — for the reason that shape exists there: a
     * test can call it and hand the answer to Storage::fake(), so the fake
     * follows the configuration instead of restating it. `Storage::fake($store->
     * diskName())` keeps working when the answer changes; `Storage::fake('local')`
     * silently stops testing anything.
     *
     * One method, so the private disk has exactly one definition. It returns a
     * constant today and will resolve something at runtime later; every caller
     * already goes through here, so that becomes a change to this body and to
     * nothing else.
     */
    public function diskName(): string
    {
        return app(PrivateStorageManager::class)->diskName();
    }

    /**
     * Write one file and describe it.
     *
     * The stored name is a uuid plus an extension derived from the declared MIME
     * — never from the name the file arrived under. That is the rule that stops
     * "factura.pdf.php" landing somewhere a web server would execute it. The
     * original name is kept as data, for display only.
     *
     * The entry now carries `disk` beside `path`, because a path on its own is
     * half an address: it says where inside a disk the file sits and never which
     * disk that is. Callers that persist the path — documents, document_versions,
     * document_templates, and the attachment entries inside messages.payload —
     * persist this beside it, and hand it back to contents() and delete(). The
     * disk reported is the one actually written to, taken from write() rather
     * than resolved a second time here, so a caller can never record a disk the
     * bytes did not go to.
     *
     * @return array{name: string, mime: string, size: int, path: string, disk: string}
     *
     * @throws RuntimeException when the write did not happen
     */
    public function put(string $directory, string $name, string $mime, string $contents): array
    {
        $path = trim($directory, '/').'/'.Str::uuid().'.'.$this->extensionFor($mime);

        // The write first and on its own line, so the order is the one that
        // matters and not an accident of array evaluation: nothing is described
        // until the bytes are down, and a failure raises before an entry exists
        // for a caller to persist.
        $disk = $this->write($path, $contents);

        return [
            'name' => $this->safeName($name),
            'mime' => $mime,
            'size' => strlen($contents),
            'path' => $path,
            'disk' => $disk,
        ];
    }

    /**
     * Write at an exact path, and say which disk it landed on.
     *
     * For derived files — a rendered preview — where the caller needs to be able
     * to find the same one again. Everything a person uploaded goes through
     * put(), which chooses the name itself.
     *
     * It returns the disk name for the same reason put()'s entry carries one: a
     * caller that keeps the path has to keep the disk with it, or it has kept
     * half an address. Nothing calls this today, which is exactly why the return
     * value has to be there before something does — the first caller inherits
     * whatever this signature admits.
     *
     * @return string the disk the bytes were written to
     *
     * @throws RuntimeException when the write did not happen
     */
    public function putRaw(string $path, string $contents): string
    {
        return $this->write($path, $contents);
    }

    /**
     * The bytes at that path, or null if there is nothing there.
     *
     * There is deliberately no exists() check in front of this. On the local
     * disk that pair is two cheap stat calls, but on an S3-compatible disk it is
     * a HeadObject followed by a GetObject, and on a miss Laravel's exists() can
     * fall back to a ListObjectsV2 — a Cloudflare Class A operation, billed at
     * about ten times a Class B read, spent to learn that a file is absent.
     *
     * get() answers both questions in one round trip. Checked against the
     * framework installed here (Laravel 12.54.1):
     * Illuminate\Filesystem\FilesystemAdapter::get() catches
     * League\Flysystem\UnableToReadFile, re-throws it only when the disk's
     * 'throw' is true, and otherwise reports it and falls off the end of the
     * method — returning null, exactly as its own `@return string|null` says.
     * Both adapters in play raise that one exception for a missing object: the
     * local Flysystem adapter, and AwsS3V3Adapter::read(), which wraps anything
     * GetObject throws in UnableToReadFile.
     *
     * And every disk here sets 'throw' => false — `local` and `public` in
     * config/filesystems.php, and every runtime cloud disk in
     * StorageManager::buildDiskConfig(). `local` is the one that matters for
     * this class and it is configured that way, so the null comes back rather
     * than an exception. A disk added later with 'throw' => true would break
     * that: this method would raise instead of returning null, and every caller
     * that treats null as "missing" would start 500ing. Check that before
     * adding one.
     *
     * The disk comes from the caller, per file, because during a migration
     * window there is no such thing as "the" disk: one document is on `local`,
     * the next on R2, and only the row knows which. Null means the row or the
     * payload entry recorded no disk, which can only mean `local` — see
     * FALLBACK_DISK.
     *
     * WHY AN OPTIONAL SECOND ARGUMENT rather than an entry array, which was the
     * alternative. Count the call sites across contents() and delete() and
     * thirteen of them hold an Eloquent model — $document, $version, $template,
     * $row — against six holding an attachment entry. An array parameter would
     * force each of the thirteen to construct one (['path' => $document->path,
     * 'disk' => $document->disk]) where today it passes a single expression, and
     * a hand-built array is a place to mistype a key into silence. With a second
     * argument each site appends `, $document->disk` or `, $entry['disk'] ?? null`
     * and nothing else changes — mechanical, visible in a diff, and wrong in a way
     * that is a type error rather than a wrong-bucket read.
     *
     * It is optional, and null-tolerant, for one reason only: attachment entries
     * written before this work have no `disk` key and never will, so `$entry['disk']
     * ?? null` has to be a legal thing to pass. That tolerance is also the trap —
     * a call site that simply forgets the argument reads `local` and looks fine
     * until the day the files are not there. The models cannot forget it silently
     * for long: documents.disk is NOT NULL, so a forgotten write shows up as a
     * constraint error rather than a null.
     */
    public function contents(string $path, ?string $disk = null): ?string
    {
        return $this->disk($disk)->get($path);
    }

    /**
     * Remove a file, from the disk it is actually on.
     *
     * Same per-file disk as contents(), and it matters more here than there: a
     * delete aimed at the wrong disk does not fail, it silently succeeds against
     * nothing. Flysystem treats deleting an absent object as a no-op on both
     * adapters, so the row goes and the bytes stay — a tenant's contract left on
     * a disk with nothing pointing at it, still counted by nobody and still
     * readable by anyone who can list the bucket.
     */
    public function delete(?string $path, ?string $disk = null): void
    {
        if ($path) {
            $this->disk($disk)->delete($path);
        }
    }

    /**
     * A filename fit to show and to put in a Content-Disposition header.
     *
     * Directory separators and control characters go; the rest is left alone so
     * a Romanian invoice keeps its diacritics.
     */
    public function safeName(string $name): string
    {
        $name = preg_replace('#[\x00-\x1F/\\\\]+#u', '', trim($name)) ?? '';
        $name = ltrim($name, '.');

        return $name !== '' ? Str::limit($name, 180, '') : 'fisier';
    }

    /**
     * The content type to serve a stored file as, or null if it must not be
     * shown in the browser at all.
     *
     * Keyed on the extension WE gave the file, never on the type the sender
     * declared: that is the whole reason put() derives its own. The list is
     * deliberately short — an invoice, a photo, a spreadsheet export. Anything a
     * browser executes, HTML and SVG above all, is absent and stays a download.
     * SVG cannot reach here anyway; extensionFor() files it as `bin`.
     */
    public function previewMimeFor(string $path): ?string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'txt' => 'text/plain; charset=UTF-8',
            'csv' => 'text/plain; charset=UTF-8',

            // Playable in the thread rather than downloaded. Safe to serve
            // inline for the same reason the images above are: none of these is
            // interpreted as script, and the response that carries them sets
            // nosniff and a sandboxed CSP either way.
            'mp4' => 'video/mp4',
            '3gp' => 'video/3gpp',
            'mov' => 'video/quicktime',
            'webm' => 'video/webm',
            'mp3' => 'audio/mpeg',
            'm4a' => 'audio/mp4',
            'aac' => 'audio/aac',
            'ogg' => 'audio/ogg',
            'amr' => 'audio/amr',
            'wav' => 'audio/wav',

            default => null,
        };
    }

    public function extensionFor(string $mime): string
    {
        return match (strtolower($mime)) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'text/plain' => 'txt',
            'text/csv' => 'csv',
            'application/zip' => 'zip',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',

            // What arrives in a conversation. WhatsApp sends voice notes as
            // ogg/opus and amr, videos as mp4 and 3gp, stickers as webp; without
            // these they all became 'bin' and then would not play in the thread.
            'video/mp4' => 'mp4',
            'video/3gpp' => '3gp',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'm4a',
            'audio/aac' => 'aac',
            'audio/ogg' => 'ogg',
            'audio/opus' => 'ogg',
            'audio/amr' => 'amr',
            'audio/wav' => 'wav',

            // Everything else keeps a neutral extension: the file is only ever
            // streamed back as a download, never interpreted. SVG is absent on
            // purpose — it is XML, it can carry script, and there is no reason
            // for one to arrive in a conversation.
            default => 'bin',
        };
    }

    /**
     * Write, or raise. The single place both writers go through, so the two
     * cannot drift apart on what a failure means.
     *
     * Every disk this application configures is built with 'throw' => false, so
     * FilesystemAdapter::put() swallows UnableToWriteFile and returns false
     * instead of raising. Discarding that boolean — which is what this class did
     * — is how a write that never happened still produces a returned path, a
     * document row, a success toast and a quota charge, discovered weeks later
     * by whoever opens the contract and finds nothing. On the local disk a write
     * essentially always succeeds and the bug never shows; across a network to
     * R2, a 403, a timeout or a 503 is an ordinary Tuesday.
     *
     * The four callers that create a database row from the path put() returns —
     * DocumentController, VersionController, OfficeController and
     * DocumentImporter — are each inside a controller action or a queued job, so
     * this surfaces as a 500 or a failed job. That is the trade, made
     * deliberately: a silent failure becomes a visible one.
     *
     * The message cannot say WHY, and that is Laravel's doing, not an omission
     * here: put() hands the UnableToWriteFile to report(), which drops it unless
     * the disk sets 'report' => true — `local` sets it false and no cloud disk
     * sets it at all, and it defaults to false. The 403, the timeout or the 503
     * is gone by the time this sees a boolean. Turning 'report' on, or 'throw'
     * on with a catch here, is how the cause gets kept; neither is this stage.
     *
     * Not every failure arrives as this message, either. put() catches only
     * UnableToWriteFile and UnableToSetVisibility, so if the parent directory
     * cannot be created the Flysystem UnableToCreateDirectory escapes it
     * untouched and reaches the caller as itself. Still loud, which is all this
     * stage asks for. On an S3-compatible disk it does not arise: there are no
     * directories to create.
     *
     * The message is not translated on purpose. It is for a log line and a stack
     * trace, never for a person to read; what the user is shown is the owning
     * controller's business.
     *
     * It returns the disk it wrote to rather than letting put() and putRaw() ask
     * again afterwards. diskName() answers from a constant today and will answer
     * from configuration later, and "resolve once, report what was used" is the
     * difference between an entry that describes the write and an entry that
     * describes a second, later guess at it.
     *
     * @return string the disk the bytes were written to
     *
     * @throws RuntimeException
     */
    private function write(string $path, string $contents): string
    {
        $disk = $this->diskName();

        if (Storage::disk($disk)->put($path, $contents) === false) {
            throw new RuntimeException(sprintf(
                'PrivateFileStore: write to [%s] failed on the [%s] disk.',
                $path,
                $disk
            ));
        }

        return $disk;
    }

    /**
     * The disk to read or delete on.
     *
     * `?:` and not `??`: an empty string reaches here easily — a `(string)` cast
     * over a missing array key, a column read before the default landed — and
     * Storage::disk('') does not fall back, it throws InvalidArgumentException
     * for a driver that was never configured. Treating '' the same as null turns
     * that into a read of `local`, which for a file with no recorded disk is the
     * right answer anyway.
     */
    private function disk(?string $name = null): Filesystem
    {
        $name = $name ?: self::FALLBACK_DISK;

        // A recorded disk is not necessarily the current default, and a disk
        // that is not the current default has never been configured: the R2
        // private disk exists only as a Config::set side effect of
        // PrivateStorageManager::diskName(), which reads do not call. Without
        // this, every download of an already-migrated file raises
        // InvalidArgumentException — a 500, where every caller here is written
        // to expect a 404.
        return Storage::disk(app(PrivateStorageManager::class)->ensureDisk($name));
    }
}
