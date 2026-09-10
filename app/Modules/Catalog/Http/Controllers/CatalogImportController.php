<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\CatalogItem;
use App\Modules\Catalog\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Getting an existing price list into the catalogue without typing it twice.
 *
 * The firm already has its products somewhere — an Excel sheet, an export from
 * the till, a list the accountant keeps. This reads that file with PHP's own
 * fgetcsv; no package is added for a job the language already does.
 *
 * Import only ever creates. It does not match, merge or overwrite: a run that
 * quietly rewrote prices the shop had corrected by hand would be worse than one
 * that made duplicates the shop can see and delete.
 */
class CatalogImportController extends Controller
{
    /** Kilobytes. The drop zone says the same number. */
    private const MAX_KB = 2048;

    /** Rows read in one run. Beyond this the file is truncated and it says so. */
    private const MAX_ROWS = 2000;

    /**
     * The longest line fgetcsv will read, in bytes.
     *
     * Without it a 2 MB file of nothing but commas parses into two million
     * fields and allocates about 100 MB — a fatal error on the shared hosting
     * this is meant to run on, reachable from an upload that passes every
     * validator.
     */
    private const MAX_LINE_BYTES = 65536;

    /**
     * The header names understood, in both languages.
     *
     * The file comes from the customer, and a Romanian firm's spreadsheet has
     * Romanian headers — the on-screen instructions say so in Romanian. Reading
     * only the English names would mean the hint and the parser disagree, and
     * the person following the hint gets "the file needs a column called name".
     */
    private const COLUMNS = [
        'name' => 'name',
        'nume' => 'name',
        'denumire' => 'name',
        'produs' => 'name',
        'type' => 'type',
        'tip' => 'type',
        'code' => 'code',
        'cod' => 'code',
        'sku' => 'code',
        'category' => 'category',
        'categorie' => 'category',
        'unit' => 'unit',
        'unitate' => 'unit',
        'um' => 'unit',
        'price' => 'price',
        'pret' => 'price',
        'preț' => 'price',
        'stock' => 'stock',
        'stoc' => 'stock',
        'cantitate' => 'stock',
    ];

    public function store(Request $request): RedirectResponse
    {
        $workspaceId = (int) $request->user()->workspace_id;
        $createdBy = (int) $request->user()->id;

        $request->validate([
            'file' => ['required', 'file', 'max:'.self::MAX_KB, 'mimes:csv,txt'],
        ]);

        $handle = fopen($request->file('file')->getRealPath(), 'r');

        if ($handle === false) {
            return back()->withErrors(['file' => __('The file could not be read.')]);
        }

        $delimiter = $this->delimiter($handle);
        $header = fgetcsv($handle, self::MAX_LINE_BYTES, $delimiter, '"', '');
        $map = is_array($header) ? $this->map($header) : [];

        if (! isset($map['name'])) {
            fclose($handle);

            return back()->withErrors(['file' => __('The file needs a column called "name".')]);
        }

        [$rows, $skipped, $truncated] = $this->read($handle, $delimiter, $map, $workspaceId, $createdBy);
        fclose($handle);

        foreach (array_chunk($rows, 200) as $chunk) {
            CatalogItem::insert($chunk);
        }

        $message = __(':created item(s) imported, :skipped row(s) skipped.', [
            'created' => count($rows),
            'skipped' => $skipped,
        ]);

        if ($truncated) {
            $message .= ' '.__('Only the first :max rows were read.', ['max' => self::MAX_ROWS]);
        }

        return back()->with('success', $message);
    }

    /**
     * Read the rows.
     *
     * @param  resource  $handle
     * @param  array<string, int>  $map
     * @return array{0: array<int, array<string, mixed>>, 1: int, 2: bool}
     */
    private function read($handle, string $delimiter, array $map, int $workspaceId, int $createdBy): array
    {
        $rows = [];
        $skipped = 0;
        $read = 0;
        $truncated = false;
        $now = now();

        while (($line = fgetcsv($handle, self::MAX_LINE_BYTES, $delimiter, '"', '')) !== false) {
            // fgetcsv hands back [null] for a blank line. That is not a row the
            // person meant to import, so it is not a row worth reporting skipped.
            if ($line === [null]) {
                continue;
            }

            if ($read >= self::MAX_ROWS) {
                $truncated = true;
                break;
            }

            $read++;

            $name = $this->cell($line, $map, 'name');

            if ($name === null) {
                $skipped++;

                continue;
            }

            $rows[] = [
                // Set here because insert() skips the model's creating hook, and
                // a row without a uuid has no URL.
                'uuid' => (string) Str::uuid(),
                'workspace_id' => $workspaceId,
                'type' => $this->type($this->cell($line, $map, 'type')),
                'name' => $this->clip($name, 255),
                'code' => $this->clip($this->cell($line, $map, 'code'), 255),
                'category' => $this->clip($this->cell($line, $map, 'category'), 255),
                'unit' => $this->clip($this->cell($line, $map, 'unit'), 32) ?? 'buc',
                'price_cents' => Money::bani($this->cell($line, $map, 'price')),
                'stock' => $this->quantity($this->cell($line, $map, 'stock')),
                // low_stock_threshold is left to the column's own default.
                'is_active' => true,
                'created_by' => $createdBy,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return [$rows, $skipped, $truncated];
    }

    /**
     * Which column is which.
     *
     * Matched case- and space-insensitively, and only against the names we
     * understand — a sheet with twenty columns imports the seven that mean
     * something and ignores the rest rather than refusing the file.
     *
     * @param  array<int, string|null>  $header
     * @return array<string, int>
     */
    private function map(array $header): array
    {
        $map = [];

        foreach ($header as $index => $label) {
            // A UTF-8 BOM on the first cell would make "name" not match "name".
            $label = mb_strtolower(trim(str_replace("\u{FEFF}", '', (string) $label)));
            $column = self::COLUMNS[$label] ?? null;

            // First one wins: a sheet with both "name" and "nume" is a sheet
            // where the second is a translation of the first, not a new column.
            if ($column !== null && ! isset($map[$column])) {
                $map[$column] = $index;
            }
        }

        return $map;
    }

    /**
     * Comma or semicolon.
     *
     * Excel in a Romanian locale writes semicolons, and a file it wrote is the
     * single most likely thing to be dropped here.
     *
     * @param  resource  $handle
     */
    private function delimiter($handle): string
    {
        $first = fgets($handle, self::MAX_LINE_BYTES) ?: '';
        rewind($handle);

        return substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
    }

    /**
     * One cell, or null when the column is absent or the cell is empty.
     *
     * @param  array<int, string|null>  $line
     * @param  array<string, int>  $map
     */
    private function cell(array $line, array $map, string $column): ?string
    {
        if (! isset($map[$column])) {
            return null;
        }

        $value = trim((string) ($line[$map[$column]] ?? ''));

        return $value === '' ? null : $value;
    }

    /**
     * A type column, if the file has one.
     *
     * A dental clinic importing its price list has services, not products, and
     * without this every one of them lands under the wrong tab with a stock
     * column it cannot use. Romanian and English are both accepted because the
     * file comes from the customer, not from us. 'bundle' is not offered — it
     * is a Stage 3 concept with components this importer cannot express.
     */
    private function type(?string $value): string
    {
        $value = mb_strtolower(trim((string) $value));

        return in_array($value, ['service', 'serviciu', 'servicii', 'services'], true)
            ? 'service'
            : 'product';
    }

    /** Stock is a whole number of things. "12 buc" is twelve. */
    private function quantity(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $digits = (string) preg_replace('/[^0-9]/', '', $value);

        return $digits === '' ? null : (int) $digits;
    }

    /**
     * Fit a cell to its column.
     *
     * Counted in characters, not bytes, because the column is utf8mb4 and a
     * Romanian name is not one byte per letter.
     */
    private function clip(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, $length);
    }
}
