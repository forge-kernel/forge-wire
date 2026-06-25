<?php

declare(strict_types=1);

namespace App\Modules\ForgeWire\Tests\Core\Html;

use App\Modules\ForgeTesting\Attributes\Group;
use App\Modules\ForgeTesting\Attributes\Test;
use App\Modules\ForgeTesting\TestCase;
use App\Modules\ForgeWire\Core\Html\HtmlTokenizer;

#[Group("forgewire-html")]
final class HtmlTokenizerTest extends TestCase
{
    #[Test("tokenizes a simple component tag")]
    public function tokenizes_simple_component_tag(): void
    {
        $html = '<div fw:id="counter" fw:click="increment">Count</div>';
        $tokenizer = new HtmlTokenizer($html);
        $tokens = $tokenizer->tokens();

        $this->assertCount(3, $tokens);
        $this->assertSame('open', $tokens[0]['type']);
        $this->assertSame('div', $tokens[0]['tag']);
        $this->assertSame('counter', $tokens[0]['attributes']['fw:id']);
        $this->assertSame('increment', $tokens[0]['attributes']['fw:click']);
        $this->assertSame('text', $tokens[1]['type']);
        $this->assertSame('close', $tokens[2]['type']);
        $this->assertSame('div', $tokens[2]['tag']);
    }

    #[Test("extracts a component element by fw:id")]
    public function extracts_component_element_by_id(): void
    {
        $html = '<section><div fw:id="counter">Count</div></section>';
        $tokenizer = new HtmlTokenizer($html);

        $element = $tokenizer->extractFirstElementByAttribute('fw:id', 'counter');
        $this->assertSame('<div fw:id="counter">Count</div>', $element);
    }

    #[Test("extracts nested component with matching tags")]
    public function extracts_nested_component(): void
    {
        $html = '<div fw:id="outer"><p><span fw:id="inner">Inner</span></p></div>';
        $tokenizer = new HtmlTokenizer($html);

        $element = $tokenizer->extractFirstElementByAttribute('fw:id', 'outer');
        $this->assertSame('<div fw:id="outer"><p><span fw:id="inner">Inner</span></p></div>', $element);

        $element = $tokenizer->extractFirstElementByAttribute('fw:id', 'inner');
        $this->assertSame('<span fw:id="inner">Inner</span>', $element);
    }

    #[Test("handles self-closing tags")]
    public function handles_self_closing_tags(): void
    {
        $html = '<input fw:id="search" fw:keydown.enter="search" />';
        $tokenizer = new HtmlTokenizer($html);

        $element = $tokenizer->extractFirstElementByAttribute('fw:id', 'search');
        $this->assertSame('<input fw:id="search" fw:keydown.enter="search" />', $element);
    }

    #[Test("extracts fw:target elements")]
    public function extracts_target_elements(): void
    {
        $html = '<div><span fw:target>One</span><span>Two</span></div>';
        $tokenizer = new HtmlTokenizer($html);

        $targets = $tokenizer->extractElementsByAttribute('fw:target');
        $this->assertCount(1, $targets);
        $this->assertSame('<span fw:target>One</span>', reset($targets));
    }

    #[Test("collects all fw:id values")]
    public function collects_all_fw_id_values(): void
    {
        $html = '<div fw:id="a"></div><span fw:id="b"></span><input fw:id="c" />';
        $tokenizer = new HtmlTokenizer($html);

        $values = $tokenizer->collectAttributeValues('fw:id');
        $this->assertSame(['a', 'b', 'c'], array_values($values));
    }

    #[Test("collects fw:uses values")]
    public function collects_uses_values(): void
    {
        $html = '<div fw:id="a" fw:uses="count, user"></div><div fw:id="b" fw:depends="total"></div>';
        $tokenizer = new HtmlTokenizer($html);

        $uses = array_values($tokenizer->collectAttributeValues('fw:uses'));
        $depends = array_values($tokenizer->collectAttributeValues('fw:depends'));

        $this->assertSame(['count, user'], $uses);
        $this->assertSame(['total'], $depends);
    }

    #[Test("ignores HTML comments")]
    public function ignores_html_comments(): void
    {
        $html = '<div><!-- comment --><span fw:id="x">X</span></div>';
        $tokenizer = new HtmlTokenizer($html);

        $element = $tokenizer->extractFirstElementByAttribute('fw:id', 'x');
        $this->assertSame('<span fw:id="x">X</span>', $element);
    }

    #[Test("handles single quotes and unquoted attributes")]
    public function handles_various_quote_styles(): void
    {
        $html = "<div fw:id='a' fw:click=b></div>";
        $tokenizer = new HtmlTokenizer($html);

        $this->assertSame('a', $tokenizer->getAttribute(0, 'fw:id'));
        $this->assertSame('b', $tokenizer->getAttribute(0, 'fw:click'));
    }

    #[Test("returns null for missing element")]
    public function returns_null_for_missing_element(): void
    {
        $html = '<div>No component</div>';
        $tokenizer = new HtmlTokenizer($html);

        $this->assertNull($tokenizer->extractFirstElementByAttribute('fw:id', 'missing'));
    }

    #[Test("extracts container by tag name and start position")]
    public function extracts_container_by_tag_and_start(): void
    {
        $html = '<main><div fw:id="a">A</div></main>';
        $tokenizer = new HtmlTokenizer($html);

        $tokens = $tokenizer->tokens();
        $mainIndex = null;
        foreach ($tokens as $i => $token) {
            if ($token['type'] === 'open' && $token['tag'] === 'main') {
                $mainIndex = $i;
                break;
            }
        }

        $this->assertNotNull($mainIndex);
        $this->assertSame('<main><div fw:id="a">A</div></main>', $tokenizer->extractElement($mainIndex));
    }
}
