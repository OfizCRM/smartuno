<?php

namespace App\Modules\Offers\Services;

use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentVersion;
use App\Modules\Documents\Services\DocumentQuota;
use App\Modules\Offers\Models\Offer;
use App\Modules\Offers\Models\OfferItem;
use App\Modules\Shared\Services\PrivateFileStore;
use App\Support\Romania;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * The offer as a PDF the firm can hand over, and as a row in the document
 * library.
 *
 * It is filed as a Document rather than written to a folder of its own on
 * purpose. Everything the library already does, the offer PDF gets for free:
 * it is versioned when it is re-rendered, it counts against the workspace's
 * storage allowance, it turns up in the library search by name and by contact,
 * and it can be picked as an attachment from inside a conversation — which is
 * the whole of what Stage 2b needs from it. A second private folder would have
 * meant reimplementing four features that already work.
 *
 * The Document row is built the same way OfficeController::create() builds one
 * (source 'generated', the contact carried over, the creator recorded), because
 * two ways of writing that row is how the library ends up with files nothing
 * can find.
 *
 * Nothing here is caught. InvoiceService swallows a failed render and returns
 * null, which leaves the caller with no document and no idea why; an offer that
 * would not render is something the person pressing the button has to be told
 * about.
 */
class OfferPdfRenderer
{
    /** The same directory the library's own files go in. */
    private const DIRECTORY = 'documents';

    private const MIME = 'application/pdf';

    public function __construct(
        private readonly PrivateFileStore $files,
        private readonly DocumentQuota $quota,
    ) {}

    /**
     * Render the offer and return the library row holding it.
     *
     * Re-rendering an offer replaces its PDF: the same Document row keeps the
     * same uuid — so every link already pointing at it still resolves — and the
     * copy being replaced is pushed into the version history rather than
     * deleted, exactly as an edit through ONLYOFFICE does.
     *
     * @param  array<string, mixed>  $seller  the client_profiles identity, or [] when there is no client
     * @param  array<string, mixed>  $settings  as OfferSettings::get() returns them
     */
    public function render(Offer $offer, array $seller, array $settings): Document
    {
        // The one read of offer_items in the module that used the plain
        // relation. It carries its own workspace_id, so it gets its own clause
        // here too — the relation alone would happily render whatever rows the
        // offer_id matched.
        $offer->loadMissing('contact');
        $items = OfferItem::query()
            ->where('workspace_id', $offer->workspace_id)
            ->where('offer_id', $offer->id)
            ->orderBy('position')
            ->get();

        $contents = Pdf::loadView('pdf.offer', [
            'offer' => $offer,
            'items' => $items,
            'contact' => $offer->contact,
            'seller' => $seller,
            'settings' => $settings,
            // Dates are written the way a Romanian reads them, and pinned to
            // Romanian by App\Support\Romania whatever the app locale is — the
            // recipient of this file is a Romanian customer, always.
            'issuedAt' => Romania::longDate($offer->created_at),
            'validUntil' => $offer->valid_until !== null ? Romania::longDate($offer->valid_until) : null,
            // One formatter for the lines and the totals both, so a column
            // cannot end up formatted two ways on the same page.
            'money' => fn (mixed $cents): string => self::lei($cents),
            'quantity' => fn (mixed $value): string => self::quantity($value),
        ])->setPaper('a4')->output();

        // Not run through __(): the name is stored data, not interface. Two
        // colleagues in one workspace would otherwise see the same file under
        // two different names depending on the language each was working in.
        $name = 'Ofertă '.$offer->number.'.pdf';

        $existing = $offer->pdf_document_id !== null
            ? Document::query()
                ->where('workspace_id', $offer->workspace_id)
                ->whereKey($offer->pdf_document_id)
                ->first()
            : null;

        $document = $existing !== null
            ? $this->replace($existing, $name, $contents)
            : $this->file($offer, $name, $contents);

        if ((int) $offer->pdf_document_id !== (int) $document->id) {
            $offer->pdf_document_id = $document->id;
            $offer->save();
        }

        // The storage bar is cached; without this it would keep showing the
        // figure from before the file existed.
        $this->quota->forget((int) $offer->workspace_id);

        return $document;
    }

    /** The first render: a new row in the library. */
    private function file(Offer $offer, string $name, string $contents): Document
    {
        $entry = $this->files->put(self::DIRECTORY, $name, self::MIME, $contents);

        return Document::create([
            'workspace_id' => $offer->workspace_id,
            'contact_id' => $offer->contact_id,
            'name' => $entry['name'],
            'path' => $entry['path'],
            'disk' => $entry['disk'],
            'mime' => self::MIME,
            'extension' => 'pdf',
            'size_bytes' => $entry['size'],
            'source' => 'generated',
            'created_by' => $offer->created_by,
        ]);
    }

    /**
     * A re-render: what was there becomes a version, the row points at the new
     * file.
     *
     * The old file is deliberately left on disk — it is what the version row
     * refers to, and the library's own purge command is what eventually removes
     * it. Deleting it here would leave the history pointing at nothing.
     */
    private function replace(Document $document, string $name, string $contents): Document
    {
        // Written first, then the history — see the same note in
        // OfficeController::store(). pdf() re-renders on every view of an offer
        // PDF, so with the version row first a failing disk added one orphan
        // version per page view, each pointing at a file that never changed.
        $entry = $this->files->put(self::DIRECTORY, $name, self::MIME, $contents);

        DocumentVersion::create([
            'document_id' => $document->id,
            'version' => (int) DocumentVersion::where('document_id', $document->id)->max('version') + 1,
            'name' => $document->name,
            'path' => $document->path,
            // Still the row's own disk — the update below has not run yet. The
            // old file is deliberately left where it is, so this is the only
            // record of which disk to go and find it on, and mid-migration that
            // is not the disk the render above just wrote to.
            'disk' => $document->disk,
            'mime' => $document->mime,
            'size_bytes' => $document->size_bytes,
            'created_by' => $document->created_by,
        ]);

        $document->update([
            'name' => $entry['name'],
            'path' => $entry['path'],
            'disk' => $entry['disk'],
            'size_bytes' => $entry['size'],
        ]);

        return $document;
    }

    /** Bani as a Romanian reads them: "1.234,50". The currency is added by the view. */
    private static function lei(mixed $cents): string
    {
        return number_format(((int) $cents) / 100, 2, ',', '.');
    }

    /** "2", "2,5", "0,75" — three decimals are stored, none of them are shown unless they mean something. */
    private static function quantity(mixed $value): string
    {
        $formatted = number_format((float) (is_scalar($value) ? $value : 0), 3, ',', '');

        return rtrim(rtrim($formatted, '0'), ',');
    }
}
