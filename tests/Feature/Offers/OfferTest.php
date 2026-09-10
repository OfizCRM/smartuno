<?php

namespace Tests\Feature\Offers;

use App\Modules\Catalog\Models\CatalogItem;
use App\Modules\Documents\Models\Document;
use App\Modules\Offers\Models\Offer;
use App\Modules\Offers\Models\OfferItem;
use App\Modules\Shared\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesPrivateDisk;
use Tests\TestCase;

/**
 * An offer a person builds by hand.
 *
 * The properties worth pinning here are the ones a customer would notice: the
 * number is theirs and starts at one, the money is recomputed by the server and
 * not trusted from the form, and a price the shop changes tomorrow cannot alter
 * what it quoted today.
 */
class OfferTest extends TestCase
{
    use FakesPrivateDisk, RefreshDatabase;

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateDisk();
        $this->ctx = $this->createWorkspaceContext();
    }

    private function item(?int $workspaceId = null, array $attrs = []): CatalogItem
    {
        return CatalogItem::create(array_merge([
            'workspace_id' => $workspaceId ?? $this->ctx['workspace']->id,
            'type' => 'product',
            'name' => 'Cremă hidratantă 50ml',
            'code' => 'CR-1042',
            'unit' => 'buc',
            'price_cents' => 24000,
            'stock' => 24,
            'is_active' => true,
        ], $attrs));
    }

    private function draft(): Offer
    {
        $this->actingAs($this->ctx['user'])
            ->post(route('client.offers.store'), [])
            ->assertRedirect();

        return Offer::latest('id')->firstOrFail();
    }

    // ─── the number ──────────────────────────────────────────────────────

    public function test_a_new_offer_gets_a_number_of_its_own(): void
    {
        $offer = $this->draft();

        $this->assertSame('OF-'.now()->format('Y').'-0001', $offer->number);
        $this->assertSame('draft', $offer->status);
        $this->assertSame($this->ctx['workspace']->id, $offer->workspace_id);
    }

    public function test_each_firm_numbers_from_one(): void
    {
        $this->draft();
        $this->draft();

        $other = $this->createWorkspaceContext();
        $this->actingAs($other['user'])->post(route('client.offers.store'), [])->assertRedirect();

        $mine = Offer::where('workspace_id', $this->ctx['workspace']->id)->orderBy('id')->pluck('number');
        $theirs = Offer::where('workspace_id', $other['workspace']->id)->pluck('number');

        // A shared sequence would tell each firm how many offers the other one
        // has written, which is the whole reason this is not an autoincrement.
        $year = now()->format('Y');
        $this->assertSame(["OF-{$year}-0001", "OF-{$year}-0002"], $mine->all());
        $this->assertSame(["OF-{$year}-0001"], $theirs->all());
    }

    // ─── the money ───────────────────────────────────────────────────────

    public function test_the_server_computes_the_totals_from_what_it_stored(): void
    {
        $offer = $this->draft();
        $item = $this->item();

        $this->actingAs($this->ctx['user'])->put(route('client.offers.update', $offer->uuid), [
            'items' => [
                ['catalog_item_id' => $item->id, 'quantity' => 2],
                ['name' => 'Manoperă', 'unit' => 'oră', 'quantity' => 1.5, 'unit_price_cents' => 10000],
            ],
        ])->assertRedirect();

        $offer->refresh();

        // 2 x 240,00 = 480,00 and 1,5 x 100,00 = 150,00.
        $this->assertSame(63000, $offer->subtotal_cents);
        $this->assertSame(
            $offer->subtotal_cents - $offer->discount_cents + $offer->shipping_cents + $offer->vat_cents,
            $offer->total_cents,
            'the five figures must add up exactly',
        );
        $this->assertSame(2, $offer->items()->count());
    }

    public function test_a_line_takes_the_catalogue_price_unless_the_person_changed_it(): void
    {
        $offer = $this->draft();
        $item = $this->item();

        $this->actingAs($this->ctx['user'])->put(route('client.offers.update', $offer->uuid), [
            'items' => [
                ['catalog_item_id' => $item->id, 'quantity' => 1],
                ['catalog_item_id' => $item->id, 'quantity' => 1, 'unit_price_cents' => 19900],
            ],
        ])->assertRedirect();

        $lines = OfferItem::where('offer_id', $offer->id)->orderBy('position')->get();

        // The catalogue price is the default, not a floor: the unit price is
        // editable on the screen because a firm discounts a line by hand. The
        // minimum-price rule that will bound it is Stage 3.
        $this->assertSame(24000, (int) $lines[0]->unit_price_cents);
        $this->assertSame(19900, (int) $lines[1]->unit_price_cents);
    }

    public function test_a_catalogue_price_change_does_not_touch_an_offer_already_written(): void
    {
        $offer = $this->draft();
        $item = $this->item();

        $this->actingAs($this->ctx['user'])->put(route('client.offers.update', $offer->uuid), [
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 1]],
        ])->assertRedirect();

        $before = OfferItem::where('offer_id', $offer->id)->firstOrFail();
        $storedPrice = (int) $before->unit_price_cents;
        $storedName = $before->name;

        $item->update(['price_cents' => 99900, 'name' => 'Cremă hidratantă 50ml (nou)']);

        $after = OfferItem::where('offer_id', $offer->id)->firstOrFail();

        // The line owns its own name and price from the moment it was added.
        $this->assertSame($storedPrice, (int) $after->unit_price_cents);
        $this->assertSame($storedName, $after->name);
        $this->assertSame($offer->fresh()->total_cents, $offer->refresh()->total_cents);
    }

    // ─── the guards ──────────────────────────────────────────────────────

    public function test_a_line_pointing_at_another_firms_product_is_refused_not_dropped(): void
    {
        $offer = $this->draft();
        $intruder = $this->createWorkspaceContext();
        $foreign = $this->item($intruder['workspace']->id, ['name' => 'NU E AL MEU']);

        $this->actingAs($this->ctx['user'])
            ->from(route('client.offers.show', $offer->uuid))
            ->put(route('client.offers.update', $offer->uuid), [
                'items' => [['catalog_item_id' => $foreign->id, 'quantity' => 1]],
            ])
            ->assertSessionHasErrors();

        // Refused, not silently dropped: an offer that quietly loses a line is
        // worse than one that will not save.
        $this->assertSame(0, OfferItem::where('offer_id', $offer->id)->count());
    }

    public function test_a_contact_from_another_firm_cannot_be_put_on_the_offer(): void
    {
        $offer = $this->draft();
        $intruder = $this->createWorkspaceContext();
        $foreign = Contact::factory()->create(['workspace_id' => $intruder['workspace']->id]);

        $this->actingAs($this->ctx['user'])->put(route('client.offers.update', $offer->uuid), [
            'contact_id' => $foreign->id,
        ])->assertRedirect();

        $this->assertNull($offer->fresh()->contact_id);
    }

    public function test_another_firm_cannot_open_or_edit_the_offer(): void
    {
        $offer = $this->draft();
        $intruder = $this->createWorkspaceContext();

        $this->actingAs($intruder['user'])->get(route('client.offers.show', $offer->uuid))->assertForbidden();
        $this->actingAs($intruder['user'])->put(route('client.offers.update', $offer->uuid), [])->assertForbidden();
        $this->actingAs($intruder['user'])->get(route('client.offers.pdf', $offer->uuid))->assertForbidden();
        $this->actingAs($intruder['user'])->delete(route('client.offers.destroy', $offer->uuid))->assertForbidden();
    }

    public function test_an_offer_that_has_left_the_building_cannot_be_edited(): void
    {
        $offer = $this->draft();
        $offer->forceFill(['status' => 'sent', 'sent_at' => now()])->save();

        $this->actingAs($this->ctx['user'])
            ->from(route('client.offers.show', $offer->uuid))
            ->put(route('client.offers.update', $offer->uuid), ['notes' => 'altceva'])
            ->assertSessionHasErrors('status');

        $this->assertNull($offer->fresh()->notes);
    }

    // ─── the decision, and the PDF ───────────────────────────────────────

    public function test_marking_the_offer_accepted_records_when(): void
    {
        $offer = $this->draft();

        $this->actingAs($this->ctx['user'])
            ->post(route('client.offers.decision', $offer->uuid), ['decision' => 'accepted'])
            ->assertRedirect();

        $offer->refresh();
        $this->assertSame('accepted', $offer->decision);
        $this->assertNotNull($offer->decided_at);
    }

    public function test_the_pdf_comes_back_as_a_pdf_and_is_filed_in_documents(): void
    {
        $offer = $this->draft();
        $item = $this->item();
        $this->actingAs($this->ctx['user'])->put(route('client.offers.update', $offer->uuid), [
            'items' => [['catalog_item_id' => $item->id, 'quantity' => 1]],
        ]);

        $response = $this->actingAs($this->ctx['user'])->get(route('client.offers.pdf', $offer->uuid));

        $response->assertOk();
        $this->assertStringContainsString('pdf', strtolower((string) $response->headers->get('content-type')));
        $this->assertStringStartsWith('%PDF', $response->getContent());

        $this->assertDatabaseHas('documents', [
            'workspace_id' => $this->ctx['workspace']->id,
            'source' => 'generated',
        ]);

        // The rendered offer carries the seller's prices and the buyer's
        // details, and it is filed as an ordinary document — so it gets the
        // ordinary document's tripwire.
        $filed = Document::where('workspace_id', $this->ctx['workspace']->id)->firstOrFail();
        $this->assertTrue($this->privateDisk()->exists($filed->path));
        $this->assertNotOnTheWebServersDisk($filed->path, 'A rendered offer PDF');
    }

    // ─── the regressions the review found ────────────────────────────────

    public function test_the_printed_lines_add_up_to_the_printed_subtotal(): void
    {
        $offer = $this->draft();
        // 0,705 m of cable at 29,00 lei is 20,445 — the case where rounding the
        // line and rounding the sum give different answers. A customer who adds
        // the column must get the number printed underneath it.
        $item = $this->item(attrs: ['name' => 'Cablu', 'unit' => 'm', 'price_cents' => 2900]);

        $this->actingAs($this->ctx['user'])->put(route('client.offers.update', $offer->uuid), [
            'items' => [
                ['catalog_item_id' => $item->id, 'quantity' => 0.705],
                ['catalog_item_id' => $item->id, 'quantity' => 0.585],
                ['catalog_item_id' => $item->id, 'quantity' => 1.005],
            ],
        ])->assertRedirect();

        $offer->refresh();
        $printed = OfferItem::where('offer_id', $offer->id)->sum('line_total_cents');

        $this->assertSame((int) $printed, (int) $offer->subtotal_cents);
    }

    public function test_the_offer_keeps_its_own_wording_when_the_catalogue_is_renamed(): void
    {
        $offer = $this->draft();
        $item = $this->item();

        $this->actingAs($this->ctx['user'])->put(route('client.offers.update', $offer->uuid), [
            'items' => [['catalog_item_id' => $item->id, 'name' => 'Cremă hidratantă 50ml', 'unit' => 'buc', 'quantity' => 1]],
        ]);

        $item->update(['name' => 'Cremă 50ml (RELANSARE)', 'unit' => 'set']);

        // A save that changed nothing but the notes must not rewrite how the
        // customer's own offer describes what they were quoted.
        $this->actingAs($this->ctx['user'])->put(route('client.offers.update', $offer->uuid), [
            'notes' => 'ceva',
            'items' => [['catalog_item_id' => $item->id, 'name' => 'Cremă hidratantă 50ml', 'unit' => 'buc', 'quantity' => 1]],
        ])->assertRedirect();

        $line = OfferItem::where('offer_id', $offer->id)->firstOrFail();
        $this->assertSame('Cremă hidratantă 50ml', $line->name);
        $this->assertSame('buc', $line->unit);
    }

    public function test_an_empty_offer_is_worth_nothing(): void
    {
        $offer = $this->draft();

        // Not the shipping fee and the VAT on it, which is what a fresh draft
        // used to be created carrying — and what the list summed as work in
        // progress before a single product was picked.
        $this->assertSame(0, (int) $offer->total_cents);
        $this->assertSame(0, (int) $offer->shipping_cents);
        $this->assertSame(0, (int) $offer->vat_cents);
    }

    public function test_a_line_too_large_to_add_up_is_refused_rather_than_a_500(): void
    {
        $offer = $this->draft();

        $this->actingAs($this->ctx['user'])
            ->from(route('client.offers.show', $offer->uuid))
            ->put(route('client.offers.update', $offer->uuid), [
                'items' => [['name' => 'Prea mare', 'quantity' => 999999, 'unit_price_cents' => 99999999999]],
            ])
            ->assertSessionHasErrors();
    }

    public function test_saving_does_not_detach_the_client(): void
    {
        $offer = $this->draft();
        $contact = Contact::factory()->create(['workspace_id' => $this->ctx['workspace']->id]);

        $this->actingAs($this->ctx['user'])->put(route('client.offers.update', $offer->uuid), [
            'contact_id' => $contact->id,
        ])->assertRedirect();

        $props = $this->actingAs($this->ctx['user'])
            ->get(route('client.offers.show', $offer->uuid))
            ->viewData('page')['props'];

        // The editor sends contact_id back from this prop on every save. Without
        // the id in it, changing one quantity wiped the client off the offer.
        $this->assertSame($contact->id, $props['offer']['contact']['id']);

        $this->actingAs($this->ctx['user'])->put(route('client.offers.update', $offer->uuid), [
            'contact_id' => $props['offer']['contact']['id'],
            'notes' => 'o modificare oarecare',
        ])->assertRedirect();

        $this->assertSame($contact->id, (int) $offer->fresh()->contact_id);
    }

    public function test_the_offer_settings_actually_save(): void
    {
        $this->actingAs($this->ctx['user'])->put(route('client.offers.settings.save'), [
            'validity_days' => 21,
            'shipping' => '25',
            'free_shipping' => '350,50',
            'default_discount_percent' => 5,
            'footer_text' => 'Mulțumim!',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $props = $this->actingAs($this->ctx['user'])
            ->get(route('client.offers.settings'))
            ->viewData('page')['props'];

        // The form speaks lei; the store keeps bani. Both halves have to agree,
        // and nothing here may fail silently.
        $this->assertSame(21, (int) $props['settings']['validity_days']);
        $this->assertSame(2500, (int) $props['settings']['shipping_cents']);
        $this->assertSame(35050, (int) $props['settings']['free_shipping_cents']);
    }

    public function test_the_list_counts_only_this_firms_offers(): void
    {
        $this->draft();
        $intruder = $this->createWorkspaceContext();
        $this->actingAs($intruder['user'])->post(route('client.offers.store'), []);

        $props = $this->actingAs($this->ctx['user'])
            ->get(route('client.offers.index'))
            ->viewData('page')['props'];

        $this->assertSame(1, $props['counts']['all']);
        $this->assertCount(1, $props['offers']['data']);
    }
}
