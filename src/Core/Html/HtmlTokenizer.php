<?php

declare(strict_types=1);

namespace App\Modules\ForgeWire\Core\Html;

/**
 * Lightweight HTML tokenizer for ForgeWire component markup.
 *
 * Parses an HTML string once into a token stream and exposes helpers
 * for querying fw:* attributes and extracting element boundaries.
 *
 * This is not a full HTML parser; it understands just enough to walk
 * tags, attributes, comments and text so that ForgeWire can locate
 * components, actions, targets and dependency declarations.
 */
final class HtmlTokenizer
{
    /** @var array<int, array<string, mixed>> */
    private array $tokens = [];

    private string $html;
    private int $length;

    public function __construct(string $html)
    {
        $this->html = $html;
        $this->length = strlen($html);
        $this->tokenize();
    }

    /**
     * Return all parsed tokens.
     *
     * Each token contains:
     *  - type: 'open' | 'close' | 'self-closing' | 'comment' | 'doctype' | 'text'
     *  - tag: lowercase tag name (for tag tokens)
     *  - attributes: array<string, string|null>
     *  - start: byte offset where the token starts in the source HTML
     *  - end: byte offset where the token ends (exclusive)
     *  - raw: source substring from start to end
     *  - pair: for 'open'/'close' tokens, the index of the matching token
     */
    public function tokens(): array
    {
        return $this->tokens;
    }

    /**
     * Find tag token indices that carry a given attribute.
     *
     * @return array<int>
     */
    public function findTagIndicesByAttribute(string $attribute, ?string $value = null): array
    {
        $indices = [];
        foreach ($this->tokens as $i => $token) {
            if (!$this->isTagToken($token)) {
                continue;
            }
            if (!array_key_exists($attribute, $token['attributes'])) {
                continue;
            }
            if ($value !== null && $token['attributes'][$attribute] !== $value) {
                continue;
            }
            $indices[] = $i;
        }
        return $indices;
    }

    /**
     * Get the value of an attribute on a tag token, or null if absent.
     */
    public function getAttribute(int $index, string $attribute): ?string
    {
        $token = $this->tokens[$index] ?? null;
        if ($token === null || !$this->isTagToken($token)) {
            return null;
        }
        return $token['attributes'][$attribute] ?? null;
    }

    /**
     * Extract the full element starting at an opening/self-closing token index.
     * Returns null if the index is not an opening tag or boundaries cannot be found.
     */
    public function extractElement(int $openIndex): ?string
    {
        $open = $this->tokens[$openIndex] ?? null;
        if ($open === null || !$this->isOpenOrSelfClosing($open)) {
            return null;
        }

        if ($open['type'] === 'self-closing') {
            return $open['raw'];
        }

        $pairIndex = $open['pair'] ?? null;
        if ($pairIndex === null) {
            return null;
        }

        $close = $this->tokens[$pairIndex];
        $start = $open['start'];
        $end = $close['end'];

        return substr($this->html, $start, $end - $start);
    }

    /**
     * Extract the full element for the first tag that has the given attribute/value.
     */
    public function extractFirstElementByAttribute(string $attribute, string $value): ?string
    {
        $indices = $this->findTagIndicesByAttribute($attribute, $value);
        foreach ($indices as $index) {
            $element = $this->extractElement($index);
            if ($element !== null) {
                return $element;
            }
        }
        return null;
    }

    /**
     * Return the byte range [start, end) of the full element at the given index.
     * Returns null if the index is not an opening/self-closing tag or has no pair.
     *
     * @return array{start: int, end: int}|null
     */
    public function getElementByteRange(int $index): ?array
    {
        $token = $this->tokens[$index] ?? null;
        if ($token === null || !$this->isOpenOrSelfClosing($token)) {
            return null;
        }
        if ($token['type'] === 'self-closing') {
            return ['start' => $token['start'], 'end' => $token['end']];
        }
        $pairIndex = $token['pair'] ?? null;
        if ($pairIndex === null) {
            return null;
        }
        $closeToken = $this->tokens[$pairIndex] ?? null;
        if ($closeToken === null) {
            return null;
        }
        return ['start' => $token['start'], 'end' => $closeToken['end']];
    }

    /**
     * Inject an attribute into the element at the given token index.
     * Returns the modified element HTML, or null if the index is not a tag
     * token or the attribute already exists.
     */
    public function injectAttribute(int $index, string $name, string $value): ?string
    {
        $token = $this->tokens[$index] ?? null;
        if ($token === null || !$this->isOpenOrSelfClosing($token)) {
            return null;
        }

        if (array_key_exists($name, $token['attributes'])) {
            return null;
        }

        $attrStr = ' ' . $name . '="' . htmlspecialchars((string) $value) . '"';
        $tagHtml = $token['raw'];

        $tagStartInRaw = 1;
        $rawLen = strlen($tagHtml);
        while ($tagStartInRaw < $rawLen && ctype_space($tagHtml[$tagStartInRaw])) {
            $tagStartInRaw++;
        }
        $insertPos = $tagStartInRaw + strlen($token['tag']);

        if ($token['type'] === 'self-closing') {
            return substr($tagHtml, 0, $insertPos) . $attrStr . substr($tagHtml, $insertPos);
        }

        $modifiedOpen = substr($tagHtml, 0, $insertPos) . $attrStr . substr($tagHtml, $insertPos);

        $pairIndex = $token['pair'] ?? null;
        if ($pairIndex === null) {
            return $modifiedOpen;
        }

        $closeToken = $this->tokens[$pairIndex] ?? null;
        if ($closeToken === null) {
            return $modifiedOpen;
        }

        $content = substr($this->html, $token['end'], $closeToken['start'] - $token['end']);

        return $modifiedOpen . $content . $closeToken['raw'];
    }

    /**
     * Extract all elements that have the given attribute.
     * If $value is provided, only elements whose attribute exactly matches are returned.
     *
     * @return array<int, string>
     */
    public function extractElementsByAttribute(string $attribute, ?string $value = null): array
    {
        $indices = $this->findTagIndicesByAttribute($attribute, $value);
        $elements = [];
        foreach ($indices as $index) {
            $element = $this->extractElement($index);
            if ($element !== null) {
                $elements[$index] = $element;
            }
        }
        return $elements;
    }

    /**
     * Collect the values of every occurrence of an attribute.
     *
     * @return array<int, string>
     */
    public function collectAttributeValues(string $attribute): array
    {
        $values = [];
        foreach ($this->tokens as $i => $token) {
            if (!$this->isTagToken($token)) {
                continue;
            }
            if (array_key_exists($attribute, $token['attributes'])) {
                $values[$i] = (string) $token['attributes'][$attribute];
            }
        }
        return $values;
    }

    private function tokenize(): void
    {
        $pos = 0;
        $tokenIndex = 0;

        while ($pos < $this->length) {
            $lt = strpos($this->html, '<', $pos);

            if ($lt === false) {
                // Remaining text
                $this->addTextToken($pos, $this->length);
                break;
            }

            if ($lt > $pos) {
                $this->addTextToken($pos, $lt);
                $tokenIndex++;
            }

            if ($lt + 4 <= $this->length && substr($this->html, $lt, 4) === '<!--') {
                $commentEnd = strpos($this->html, '-->', $lt + 4);
                if ($commentEnd === false) {
                    $commentEnd = $this->length;
                    $end = $this->length;
                } else {
                    $end = $commentEnd + 3;
                }
                $this->tokens[$tokenIndex] = [
                    'type' => 'comment',
                    'tag' => null,
                    'attributes' => [],
                    'start' => $lt,
                    'end' => $end,
                    'raw' => substr($this->html, $lt, $end - $lt),
                    'pair' => null,
                ];
                $pos = $end;
                $tokenIndex++;
                continue;
            }

            if ($lt + 2 <= $this->length && $this->html[$lt + 1] === '!') {
                $doctypeEnd = strpos($this->html, '>', $lt + 2);
                if ($doctypeEnd === false) {
                    $doctypeEnd = $this->length - 1;
                }
                $end = $doctypeEnd + 1;
                $this->tokens[$tokenIndex] = [
                    'type' => 'doctype',
                    'tag' => null,
                    'attributes' => [],
                    'start' => $lt,
                    'end' => $end,
                    'raw' => substr($this->html, $lt, $end - $lt),
                    'pair' => null,
                ];
                $pos = $end;
                $tokenIndex++;
                continue;
            }

            if ($lt + 2 <= $this->length && $this->html[$lt + 1] === '/') {
                // Closing tag
                $gt = strpos($this->html, '>', $lt + 2);
                if ($gt === false) {
                    // Malformed; treat rest as text
                    $this->addTextToken($lt, $this->length);
                    break;
                }
                $end = $gt + 1;
                $raw = substr($this->html, $lt, $end - $lt);
                $tag = $this->parseCloseTagName($raw);
                $this->tokens[$tokenIndex] = [
                    'type' => 'close',
                    'tag' => $tag,
                    'attributes' => [],
                    'start' => $lt,
                    'end' => $end,
                    'raw' => $raw,
                    'pair' => null,
                ];
                $pos = $end;
                $tokenIndex++;
                continue;
            }

            // Opening / self-closing tag
            $tagInfo = $this->parseTag($lt);
            if ($tagInfo === null) {
                // Malformed; treat as text and move on
                $this->addTextToken($lt, $lt + 1);
                $pos = $lt + 1;
                $tokenIndex++;
                continue;
            }

            $this->tokens[$tokenIndex] = [
                'type' => $tagInfo['selfClosing'] ? 'self-closing' : 'open',
                'tag' => $tagInfo['tag'],
                'attributes' => $tagInfo['attributes'],
                'start' => $lt,
                'end' => $tagInfo['end'],
                'raw' => $tagInfo['raw'],
                'pair' => null,
            ];

            $pos = $tagInfo['end'];
            $tokenIndex++;
        }

        $this->linkPairs();
    }

    private function addTextToken(int $start, int $end): void
    {
        $this->tokens[] = [
            'type' => 'text',
            'tag' => null,
            'attributes' => [],
            'start' => $start,
            'end' => $end,
            'raw' => substr($this->html, $start, $end - $start),
            'pair' => null,
        ];
    }

    private function parseCloseTagName(string $raw): string
    {
        if (preg_match('/<\/\s*([a-zA-Z0-9_-]+)/i', $raw, $m)) {
            return strtolower($m[1]);
        }
        return '';
    }

    /**
     * @return array{tag: string, attributes: array<string, string|null>, selfClosing: bool, end: int, raw: string}|null
     */
    private function parseTag(int $start): ?array
    {
        $pos = $start + 1;
        $len = $this->length;

        // Skip whitespace after '<'
        while ($pos < $len && ctype_space($this->html[$pos])) {
            $pos++;
        }

        if ($pos >= $len || !preg_match('/[a-zA-Z0-9_-]/', $this->html[$pos])) {
            return null;
        }

        $tagStart = $pos;
        while ($pos < $len && preg_match('/[a-zA-Z0-9_-]/', $this->html[$pos])) {
            $pos++;
        }
        $tag = strtolower(substr($this->html, $tagStart, $pos - $tagStart));

        $attributes = [];
        $selfClosing = false;

        while ($pos < $len) {
            $ch = $this->html[$pos];

            if ($ch === '>') {
                $end = $pos + 1;
                break;
            }

            if ($ch === '/' && $pos + 1 < $len && $this->html[$pos + 1] === '>') {
                $selfClosing = true;
                $end = $pos + 2;
                break;
            }

            if (ctype_space($ch)) {
                $pos++;
                continue;
            }

            $attrResult = $this->parseAttribute($pos);
            if ($attrResult === null) {
                // Skip unknown character to avoid infinite loop
                $pos++;
                continue;
            }

            $attributes[$attrResult['name']] = $attrResult['value'];
            $pos = $attrResult['end'];
        }

        if (!isset($end)) {
            return null;
        }

        $raw = substr($this->html, $start, $end - $start);

        return [
            'tag' => $tag,
            'attributes' => $attributes,
            'selfClosing' => $selfClosing,
            'end' => $end,
            'raw' => $raw,
        ];
    }

    /**
     * @return array{name: string, value: string|null, end: int}|null
     */
    private function parseAttribute(int $start): ?array
    {
        $pos = $start;
        $len = $this->length;

        // Attribute name
        $nameStart = $pos;
        while ($pos < $len && preg_match('/[^\s=\/>"\']/', $this->html[$pos])) {
            $pos++;
        }

        if ($pos === $nameStart) {
            return null;
        }

        $name = strtolower(substr($this->html, $nameStart, $pos - $nameStart));

        // Skip whitespace before '='
        while ($pos < $len && ctype_space($this->html[$pos])) {
            $pos++;
        }

        if ($pos >= $len || $this->html[$pos] !== '=') {
            return ['name' => $name, 'value' => null, 'end' => $pos];
        }

        $pos++; // skip '='

        // Skip whitespace after '='
        while ($pos < $len && ctype_space($this->html[$pos])) {
            $pos++;
        }

        if ($pos >= $len) {
            return ['name' => $name, 'value' => '', 'end' => $pos];
        }

        $quote = $this->html[$pos];
        if ($quote === '"' || $quote === "'") {
            $pos++;
            $valueStart = $pos;
            $valueEnd = strpos($this->html, $quote, $pos);
            if ($valueEnd === false) {
                $valueEnd = $len;
                $pos = $len;
            } else {
                $pos = $valueEnd + 1;
            }
            $value = substr($this->html, $valueStart, $valueEnd - $valueStart);
            return ['name' => $name, 'value' => $value, 'end' => $pos];
        }

        // Unquoted value
        $valueStart = $pos;
        while ($pos < $len && !ctype_space($this->html[$pos]) && $this->html[$pos] !== '>' && $this->html[$pos] !== '/') {
            $pos++;
        }
        $value = substr($this->html, $valueStart, $pos - $valueStart);
        return ['name' => $name, 'value' => $value, 'end' => $pos];
    }

    private function linkPairs(): void
    {
        $stack = [];
        $selfClosingTags = [
            'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
            'link', 'meta', 'param', 'source', 'track', 'wbr',
        ];

        foreach ($this->tokens as $i => $token) {
            if ($token['type'] === 'open') {
                $tag = $token['tag'];
                if (in_array($tag, $selfClosingTags, true)) {
                    $this->tokens[$i]['type'] = 'self-closing';
                    continue;
                }
                $stack[] = ['index' => $i, 'tag' => $tag];
            } elseif ($token['type'] === 'close') {
                $tag = $token['tag'];
                while (!empty($stack)) {
                    $open = array_pop($stack);
                    if ($open['tag'] === $tag) {
                        $this->tokens[$open['index']]['pair'] = $i;
                        $this->tokens[$i]['pair'] = $open['index'];
                        break;
                    }
                }
            }
        }
    }

    private function isTagToken(array $token): bool
    {
        return in_array($token['type'], ['open', 'close', 'self-closing'], true);
    }

    private function isOpenOrSelfClosing(array $token): bool
    {
        return in_array($token['type'], ['open', 'self-closing'], true);
    }
}
