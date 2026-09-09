<?php

namespace App\Modules\Shared\Services;

use Illuminate\Support\Facades\Log;

/**
 * Fills {{contact.*}} placeholders inside a Word or Excel file.
 *
 * The awkward part is not the substitution, it is finding the placeholder at
 * all. Word stores text as a sequence of "runs", and it splits them wherever it
 * feels like — a spell-check mark, a stray formatting change, or simply how the
 * typing happened. So `{{contact.company}}` is routinely stored as
 *
 *     <w:t>{{contact.</w:t></w:r><w:r><w:t>company}}</w:t>
 *
 * and a search for the literal string finds nothing. The document looks right to
 * a person and the placeholder silently survives into the contract they send.
 *
 * The fix is to match across the tags and replace the whole span. That stays
 * well-formed because a placeholder crossing N runs contains exactly N-1 balanced
 * close/open pairs, which cancel when the span goes. The result is parsed before
 * it is written, and a file that would not parse is left exactly as it was.
 */
class OfficeTemplateFiller
{
    /** Which part of each format holds the text a person typed. */
    private const PARTS = [
        'docx' => ['word/document.xml', 'word/header1.xml', 'word/header2.xml', 'word/footer1.xml', 'word/footer2.xml'],
        'xlsx' => ['xl/sharedStrings.xml'],
    ];

    /**
     * `{{ token }}`, allowing any tags between the braces.
     *
     * Bounded to 400 characters so a stray `{{` in a long document cannot make
     * the engine walk the whole file looking for a close that never comes.
     */
    private const PLACEHOLDER = '/\{\{((?:[^<>{}]|<[^>]*>){0,400}?)\}\}/u';

    /**
     * @param  callable(string): ?string  $resolve  token name to value, null to leave alone
     * @return string the file with placeholders filled, or unchanged on any doubt
     */
    public function fill(string $extension, string $contents, callable $resolve): string
    {
        $parts = self::PARTS[strtolower($extension)] ?? null;

        if ($parts === null) {
            return $contents;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'tpl_');

        try {
            file_put_contents($tmp, $contents);

            $zip = new \ZipArchive;
            if ($zip->open($tmp) !== true) {
                return $contents;
            }

            $changed = false;
            foreach ($parts as $part) {
                $xml = $zip->getFromName($part);

                if ($xml === false) {
                    continue;
                }

                $filled = $this->fillXml($xml, $resolve);

                if ($filled !== null && $filled !== $xml) {
                    $zip->addFromString($part, $filled);
                    $changed = true;
                }
            }

            $zip->close();

            return $changed ? (string) file_get_contents($tmp) : $contents;
        } catch (\Throwable $e) {
            Log::warning('Could not fill a document template', ['error' => $e->getMessage()]);

            return $contents;
        } finally {
            @unlink($tmp);
        }
    }

    /** @param  callable(string): ?string  $resolve */
    private function fillXml(string $xml, callable $resolve): ?string
    {
        $filled = preg_replace_callback(self::PLACEHOLDER, function (array $match) use ($resolve): string {
            // Whatever tags Word put in the middle are not part of the name.
            $token = trim(strip_tags($match[1]));
            $value = $resolve($token);

            // An unknown token is left exactly as it was: a template with a typo
            // should show the typo, not quietly lose the line.
            return $value === null
                ? $match[0]
                : htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
        }, $xml);

        if ($filled === null) {
            return null;
        }

        // The whole point of removing tags is that the pairs cancel. If they did
        // not, the file is corrupt and the original is the better answer.
        return $this->parses($filled) ? $filled : null;
    }

    private function parses(string $xml): bool
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $ok = $document->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $ok !== false;
    }
}
