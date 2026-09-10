<?php

namespace App\Modules\AI\Jobs;

use App\Modules\AI\Models\AiKbChunk;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Services\EmbeddingStore;
use App\Modules\AI\Services\Llm\LlmManager;
use App\Modules\AI\Services\LlmGateway;
use App\Modules\Documents\Models\Document;
use App\Modules\Shared\Services\PrivateFileStore;
use App\Modules\Shared\Services\TextExtractor;
use App\Services\StorageManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use League\HTMLToMarkdown\HtmlConverter;

class IndexDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * How a source_ref names a row in the document library instead of a key on
     * a disk: "document:<uuid>".
     *
     * A scheme, not a path prefix, and that distinction is the whole point. The
     * prefix it replaces — "documents/" — was a guess about where the file
     * sits, so it stopped being true the moment the private disk moved or a
     * directory_prefix was configured. A uuid identifies the row; the row is
     * what knows where the file is. The table already carries refs in this
     * shape (the seeder writes "demo:<slug>"), so the reader is not new either.
     */
    public const DOCUMENT_REF_PREFIX = 'document:';

    public function __construct(public readonly int $documentId) {}

    public function handle(LlmGateway $llm, EmbeddingStore $store, StorageManager $storage): void
    {
        $doc = AiKbDocument::with('chunks')->find($this->documentId);
        if (! $doc) {
            return;
        }

        $doc->update(['status' => 'indexing']);

        // Resolved before the text is extracted rather than after, because
        // reading a file out of the document library is a tenant-scoped read
        // and this is the workspace it has to be scoped to.
        $kb = $doc->knowledgeBase ?? $doc->load('knowledgeBase')->knowledgeBase;
        $kbId = $kb?->id ?? 0;
        $workspaceId = (int) ($kb?->workspace_id ?? 0);

        try {
            $text = $this->extractText($doc, $storage, $workspaceId);
            $chunks = $this->chunk($text);

            // Remove old chunks
            $doc->chunks()->delete();

            $chunkModels = [];
            foreach ($chunks as $i => $chunkText) {
                $chunkModels[] = AiKbChunk::create([
                    'kb_id' => $kbId,
                    'document_id' => $doc->id,
                    'ord' => $i,
                    'content' => $chunkText,
                    'tokens' => (int) (strlen($chunkText) / 4),
                ]);
            }

            // Embed all chunks.
            //
            // A missing embedding provider (Anthropic-only or none configured) is a
            // non-fatal condition: the document is still indexed as plain text and we
            // log it so operators can see RAG won't work until a provider is added.
            //
            // A transient embedding API error, by contrast, is allowed to propagate so
            // the queue retries — rather than silently marking the document "indexed"
            // with no vectors.
            if ($workspaceId && ! empty($chunkModels)) {
                if ($this->embedProviderAvailable($workspaceId)) {
                    foreach (array_chunk($chunkModels, 20) as $batch) {
                        $texts = array_map(fn ($c) => $c->content, $batch);
                        $embeddings = $llm->embed($workspaceId, $texts);

                        foreach ($batch as $j => $chunk) {
                            if (isset($embeddings[$j])) {
                                $store->storeEmbedding($chunk, $embeddings[$j]);
                            }
                        }
                    }
                } else {
                    Log::warning('IndexDocumentJob: indexed without embeddings — no embedding-capable provider configured', [
                        'document_id' => $doc->id,
                        'kb_id' => $kbId,
                        'workspace_id' => $workspaceId,
                    ]);
                }
            }

            $doc->update([
                'status' => 'indexed',
                'last_indexed_at' => now(),
                'tokens' => array_sum(array_map(fn ($c) => $c->tokens, $chunkModels)),
            ]);
        } catch (\Throwable $e) {
            $doc->update(['status' => 'error']);
            throw $e;
        }
    }

    /** True when the workspace has an embedding-capable provider (OpenAI/Gemini). */
    private function embedProviderAvailable(int $workspaceId): bool
    {
        // Any failure to RESOLVE a provider (none configured, orphaned workspace,
        // malformed config) is treated as "no embeddings" — a non-fatal condition,
        // so the document still indexes as plain text. This deliberately does NOT
        // swallow errors from the actual embed() call below, which must still
        // propagate so the queue retries rather than indexing with no vectors.
        try {
            LlmManager::forWorkspaceEmbed($workspaceId);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function extractText(AiKbDocument $doc, StorageManager $storage, int $workspaceId): string
    {
        return match ($doc->source_type) {
            'text' => $doc->source_ref ?? '',
            'url' => $this->fetchUrl($doc->source_ref ?? ''),
            'file' => $this->readFile($doc->source_ref ?? '', $storage, $workspaceId),
            'faq' => $this->formatFaq($doc->source_ref ?? ''),
            'sitemap' => $this->processSitemap($doc),
            default => '',
        };
    }

    private function fetchUrl(string $url): string
    {
        if (empty($url)) {
            return '';
        }
        $resp = Http::retry(2, 500)->timeout(30)->get($url);
        if (! $resp->successful()) {
            return '';
        }
        $html = $resp->body();
        // Convert HTML to Markdown using league/html-to-markdown, then strip remaining tags
        if (class_exists(HtmlConverter::class)) {
            $converter = new HtmlConverter(['strip_tags' => true]);

            return $converter->convert($html);
        }

        return strip_tags($html);
    }

    /**
     * Read a "file" document back, from whichever disk it is actually on.
     *
     * Two unrelated things arrive here as a source_ref, and they do not live in
     * the same place:
     *
     *  - "document:<uuid>" — a file in the document library. Private disk, read
     *    through PrivateFileStore, the one class that knows which disk the
     *    private one currently is. A contract must not end up in the web root
     *    just because the bot was asked to read it.
     *  - a bare disk key ("kb-docs/x.pdf") — uploaded straight into the
     *    knowledge base by AiKnowledgeBaseController or its API twin, and so on
     *    the disk StorageManager resolves.
     *
     * These used to be told apart by str_starts_with($ref, 'documents/'), which
     * was wrong in both directions. It named the disk 'local' by hand, so it
     * broke the moment the private disk stopped being local — the entire point
     * of the R2 work. And it matched on a key layout, so a configured
     * directory_prefix broke it too. Either way the job read the wrong disk,
     * found nothing, returned '' and marked the row indexed with zero chunks: a
     * chatbot that quietly empties itself while the screen reports success.
     *
     * Which is also why a failed read now throws rather than returning ''. The
     * job marks the row 'error' and the queue retries. Across a network a failed
     * read is an ordinary Tuesday, and "indexed, no chunks" is a lie about one.
     *
     * The extraction itself is shared with the document library. It used to be a
     * PDF branch and then "return the bytes", which meant a .docx — a zip archive
     * — was embedded as binary rubbish and the document showed as indexed while
     * the bot answered as if it had never been added.
     */
    private function readFile(string $ref, StorageManager $storage, int $workspaceId): string
    {
        if ($ref === '') {
            return '';
        }

        $document = $this->libraryDocument($ref, $workspaceId);

        if ($document !== null) {
            // The extension the library recorded, not one re-derived from the
            // key: the column outlives any change to how keys are laid out.
            return $this->extractOrFail(
                $document->extension,
                app(PrivateFileStore::class)->contents($document->path, $document->disk),
                $ref,
            );
        }

        if (str_starts_with($ref, self::DOCUMENT_REF_PREFIX)) {
            // It names a library document and no such document exists in this
            // workspace: deleted, or somebody else's. Both are worth an error on
            // the row rather than a bot answering as though the file were blank.
            throw new \RuntimeException(
                "IndexDocumentJob: kb document {$this->documentId} references {$ref}, which is not a document in workspace {$workspaceId}."
            );
        }

        // What is left is a bare storage key, and it arrived in `source_ref`,
        // which is validated as ['nullable', 'string', 'max:512'] and taken
        // straight from the request on both endpoints that write this table.
        // Read unchecked, it let one firm name ANY key on the public disk —
        // another firm's uploaded knowledge base, a client logo, chat media —
        // and have its contents extracted into their own chatbot's answers.
        //
        // So a bare key is only honoured inside this workspace's own uploads
        // directory. Every legitimate value has that shape: it is the path the
        // upload branch of addDocument() just wrote and stored on the row.
        $ours = $storage->prefixedPath('kb-docs/'.$workspaceId).'/';

        if (str_contains($ref, '..') || ! str_starts_with($ref, $ours)) {
            throw new \RuntimeException(
                "IndexDocumentJob: kb document {$this->documentId} references {$ref}, which is not a file workspace {$workspaceId} uploaded."
            );
        }

        // get() alone, with no exists() in front of it, for the same reason
        // PrivateFileStore::contents() dropped that pair: on an S3-compatible
        // disk it is a HeadObject and then a GetObject, and on a miss Laravel's
        // exists() can fall back to a ListObjectsV2 — a Class A operation spent
        // to learn that a file is absent. Every disk here sets 'throw' => false,
        // so a failed read comes back as null rather than an exception.
        return $this->extractOrFail(
            pathinfo($ref, PATHINFO_EXTENSION),
            $storage->disk()->get($ref),
            $ref,
        );
    }

    /**
     * The library row a source_ref points at, or null when it points elsewhere.
     *
     * Two shapes resolve here, deliberately through the same lookup:
     *
     *  - "document:<uuid>", written since the link stopped storing a bare path;
     *  - a bare "documents/..." path, written by the link before that. Resolving
     *    those by path costs four lines and needs no backfill command, which on
     *    this install would have run over zero rows and then been dead code. It
     *    also beats a fallback that string-matches 'documents/': a lookup that
     *    never reads the prefix cannot be broken by changing it, and changing it
     *    is what the next stage does.
     *
     * Workspace-scoped, which the read it replaces was not — that one handed
     * whatever sat at the key to whichever knowledge base named it, with nothing
     * checking the two belonged to the same customer. Soft-deleted documents do
     * not resolve either, so a price list the office has withdrawn stops being
     * quoted rather than being re-indexed from a file still on disk.
     */
    private function libraryDocument(string $ref, int $workspaceId): ?Document
    {
        if ($workspaceId <= 0) {
            return null;
        }

        $query = Document::where('workspace_id', $workspaceId);

        return str_starts_with($ref, self::DOCUMENT_REF_PREFIX)
            ? $query->where('uuid', substr($ref, strlen(self::DOCUMENT_REF_PREFIX)))->first()
            : $query->where('path', $ref)->first();
    }

    /**
     * Turn the bytes into text, or say out loud that there were none.
     *
     * Null means the read did not happen — a missing object, a bucket that
     * refused, a network that was not there. Only the caller knows which disk it
     * asked, so the reference is quoted back rather than guessed at.
     */
    private function extractOrFail(string $extension, ?string $contents, string $ref): string
    {
        if ($contents === null) {
            throw new \RuntimeException(
                "IndexDocumentJob: kb document {$this->documentId} could not read {$ref} back from storage."
            );
        }

        return app(TextExtractor::class)->extract($extension, $contents);
    }

    /**
     * Turn the FAQ payload (a JSON array of {question, answer} pairs produced by the
     * UI) into clean, embeddable text. Falls back to the raw string if it isn't JSON.
     */
    private function formatFaq(string $raw): string
    {
        if (trim($raw) === '') {
            return '';
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            // Not JSON — treat as plain text.
            return $raw;
        }

        $parts = [];
        foreach ($decoded as $pair) {
            if (! is_array($pair)) {
                continue;
            }
            $q = trim((string) ($pair['question'] ?? ''));
            $a = trim((string) ($pair['answer'] ?? ''));
            if ($q === '' && $a === '') {
                continue;
            }
            $parts[] = "Q: {$q}\nA: {$a}";
        }

        return implode("\n\n", $parts);
    }

    /**
     * Parse a sitemap and fan out one lightweight child job per page URL.
     *
     * This job stays cheap on purpose: it only fetches + parses the XML and
     * enqueues child "url" documents. The actual page crawling/embedding happens
     * in those child jobs on the queue, so the originating web request never
     * blocks on hundreds of HTTP fetches (which previously caused a 502 when the
     * queue ran synchronously).
     *
     * Handles both <urlset> (a flat list of pages) and <sitemapindex> (a list of
     * nested sitemaps, e.g. Yoast/WordPress) — for the latter, each nested sitemap
     * is enqueued as its own "sitemap" child and expanded recursively.
     */
    private function processSitemap(AiKbDocument $doc): string
    {
        $sitemapUrl = $doc->source_ref ?? '';
        if (empty($sitemapUrl)) {
            return '';
        }
        $resp = Http::retry(2, 500)->timeout(20)->get($sitemapUrl);
        if (! $resp->successful()) {
            return '';
        }

        try {
            $xml = simplexml_load_string($resp->body());
            if ($xml === false) {
                throw new \RuntimeException('Unparseable sitemap XML');
            }

            // <sitemapindex> → nested sitemaps; <urlset> → page URLs.
            $isIndex = isset($xml->sitemap);
            $childType = $isIndex ? 'sitemap' : 'url';

            $locs = [];
            foreach (($isIndex ? $xml->sitemap : $xml->url) as $node) {
                $loc = trim((string) $node->loc);
                if ($loc !== '') {
                    $locs[$loc] = true; // dedupe by URL
                }
            }

            foreach (array_slice(array_keys($locs), 0, 200) as $loc) {
                $child = AiKbDocument::create([
                    'kb_id' => $doc->kb_id,
                    'title' => $loc,
                    'source_type' => $childType,
                    'source_ref' => $loc,
                    'status' => 'pending',
                ]);
                static::dispatch($child->id)->onQueue('ai');
            }
        } catch (\Throwable) {
            // Malformed sitemap; fall back to fetching the URL as HTML
            return $this->fetchUrl($sitemapUrl);
        }

        return '';
    }

    private function chunk(string $text, int $size = 800, int $overlap = 100): array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $chunks = [];
        $i = 0;

        while ($i < count($words)) {
            $slice = array_slice($words, $i, $size);
            $chunks[] = implode(' ', $slice);
            $i += ($size - $overlap);
        }

        return array_values(array_filter($chunks));
    }
}
