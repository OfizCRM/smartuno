<?php

namespace App\Modules\Shared\Services;

use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser;

/**
 * Pulls readable text out of a file.
 *
 * Needed twice over: the chatbot's knowledge base indexes it, and the document
 * library searches it. Before this, the knowledge base accepted .docx uploads
 * and indexed the raw bytes of the zip archive a .docx actually is — no error,
 * no complaint, a document that showed as "indexed" while the bot answered as
 * though it did not exist.
 *
 * No new dependency. A .docx and an .xlsx are zip archives of XML, and PHP has
 * both ZipArchive and an XML parser; PDFs already had smalot/pdfparser.
 */
class TextExtractor
{
    /** Enough for any contract, and a stop before a runaway spreadsheet. */
    public const MAX_CHARS = 400000;

    /** Whether text can be got out of this at all. */
    public function supports(string $extension): bool
    {
        return in_array(strtolower($extension), ['pdf', 'docx', 'xlsx', 'txt', 'csv', 'md', 'json'], true);
    }

    /**
     * @param  string  $contents  the raw file
     * @return string plain text, empty when nothing could be read
     */
    public function extract(string $extension, string $contents): string
    {
        $extension = strtolower($extension);

        try {
            $text = match ($extension) {
                'pdf' => $this->fromPdf($contents),
                'docx' => $this->fromZippedXml($contents, ['word/document.xml']),
                // Shared strings hold most of a spreadsheet's words; the sheets
                // hold the numbers and anything not de-duplicated into them.
                'xlsx' => $this->fromZippedXml($contents, ['xl/sharedStrings.xml'], 'xl/worksheets/'),
                'txt', 'csv', 'md', 'json' => $contents,
                default => '',
            };
        } catch (\Throwable $e) {
            // A corrupt file is not worth failing an upload over — it simply has
            // no searchable text.
            Log::warning('Could not extract text', ['extension' => $extension, 'error' => $e->getMessage()]);

            return '';
        }

        return $this->tidy($text);
    }

    private function fromPdf(string $contents): string
    {
        // The parser wants a real file on disk.
        $tmp = tempnam(sys_get_temp_dir(), 'extract_');

        try {
            file_put_contents($tmp, $contents);

            return (new Parser)->parseFile($tmp)->getText();
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Read the XML parts of an Office file and keep only what a person would see.
     *
     * @param  array<int, string>  $entries  exact members to read
     * @param  string|null  $prefix  every member under this folder, too
     */
    private function fromZippedXml(string $contents, array $entries, ?string $prefix = null): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'extract_');

        try {
            file_put_contents($tmp, $contents);

            $zip = new \ZipArchive;
            if ($zip->open($tmp) !== true) {
                return '';
            }

            $names = $entries;
            if ($prefix !== null) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = (string) $zip->getNameIndex($i);
                    if (str_starts_with($name, $prefix) && str_ends_with($name, '.xml')) {
                        $names[] = $name;
                    }
                }
            }

            $text = '';
            foreach ($names as $name) {
                $xml = $zip->getFromName($name);
                if ($xml === false) {
                    continue;
                }

                $text .= ' '.$this->textOfXml($xml);

                if (mb_strlen($text) > self::MAX_CHARS) {
                    break;
                }
            }

            $zip->close();

            return $text;
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * The words inside an Office XML part.
     *
     * Paragraph and row ends become newlines first, otherwise every sentence in
     * the document runs into the next one and the search matches phrases that
     * were never written.
     */
    private function textOfXml(string $xml): string
    {
        $xml = preg_replace('#</w:p>|</a:p>|</w:tr>|</row>#i', "\n", $xml) ?? $xml;
        $xml = preg_replace('#<w:tab[^>]*/>|<w:br[^>]*/>#i', ' ', $xml) ?? $xml;
        $xml = preg_replace('#<[^>]+>#', ' ', $xml) ?? $xml;

        return html_entity_decode($xml, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function tidy(string $text): string
    {
        // Collapse the whitespace the tag stripping leaves behind, but keep the
        // line breaks that carry the shape of the document.
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s*\n\s*/u', "\n", $text) ?? $text;
        $text = trim($text);

        return mb_strlen($text) > self::MAX_CHARS ? mb_substr($text, 0, self::MAX_CHARS) : $text;
    }
}
