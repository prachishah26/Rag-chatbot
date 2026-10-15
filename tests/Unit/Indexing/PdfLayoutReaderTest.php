<?php

namespace Tests\Unit\Indexing;

use App\Services\Rag\Indexing\PdfLayoutReader;
use App\Services\Rag\Indexing\TextRun;
use PHPUnit\Framework\TestCase;

class PdfLayoutReaderTest extends TestCase
{
    private const XML = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<pdf2xml producer="poppler" version="24.02.0">
<page number="1" position="absolute" top="0" left="0" height="842" width="595">
	<fontspec id="0" size="18" family="ABCDEF+Helvetica-Bold" color="#000000"/>
	<fontspec id="1" size="10.5" family="Times" color="#000000"/>
<text top="50" left="72" width="200" height="22" font="0"><b>Fiscal &amp; Monetary Policy</b></text>
<text top="100.5" left="72" width="300" height="12" font="1">Prices &quot;rose&quot; by 5&#37; in O&#8217;Hare.</text>
<text top="120" left="72" width="10" height="12" font="1">   </text>
<text top="130" left="72" width="10" height="12" font="9">Unknown font</text>
</page>
<page number="2" position="absolute" top="0" left="0" height="842" width="595">
<text top="60" left="80" width="150" height="12" font="1"><i>Second</i> page</text>
</page>
</pdf2xml>
XML;

    public function test_it_parses_runs_per_page_with_font_and_position(): void
    {
        $pages = (new PdfLayoutReader())->parse(self::XML);

        $this->assertSame([1, 2], array_keys($pages));
        $this->assertCount(2, $pages[1]);
        $this->assertEquals(
            new TextRun(1, 50.0, 72.0, 200.0, 22.0, 18.0, 'Helvetica-Bold', 'Fiscal & Monetary Policy'),
            $pages[1][0]
        );
        $this->assertEquals(
            new TextRun(1, 100.5, 72.0, 300.0, 12.0, 10.5, 'Times', 'Prices "rose" by 5% in O’Hare.'),
            $pages[1][1]
        );
        $this->assertEquals(new TextRun(2, 60.0, 80.0, 150.0, 12.0, 10.5, 'Times', 'Second page'), $pages[2][0]);
    }

    public function test_the_subset_prefix_is_stripped_from_font_names(): void
    {
        $pages = (new PdfLayoutReader())->parse(self::XML);

        $this->assertSame('Helvetica-Bold', $pages[1][0]->font);
    }

    public function test_empty_xml_gives_no_pages(): void
    {
        $this->assertSame([], (new PdfLayoutReader())->parse(''));
    }
}
