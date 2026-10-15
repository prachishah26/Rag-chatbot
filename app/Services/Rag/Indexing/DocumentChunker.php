<?php

namespace App\Services\Rag\Indexing;

use App\Services\Rag\DocumentParser;

/**
 * The offline half of the pipeline, once per document:
 * columns in reading order → running headers dropped → text repaired → headings from fonts →
 * footnotes linked → chart pages described → sections → parent/child chunks.
 */
class DocumentChunker
{
    public function __construct(
        private DocumentParser $parser,
        private PdfLayoutReader $layout,
        private OutlineDetector $outline,
        private FootnoteLinker $footnotes,
        private VisualPageDetector $visualPages,
        private VisionDescriber $vision,
        private StructureBuilder $structure,
        private ChunkBuilder $chunks,
        private RunningText $runningText = new RunningText(),
        private TextRepairer $repairer = new TextRepairer(),
    ) {}

    /** @return array<int, Chunk> */
    public function chunk(string $path, string $mimeType, string $extension): array
    {
        $pageTexts = $this->parser->pages($path, $mimeType, $extension);
        $isPdf = $extension === 'pdf' || $mimeType === 'application/pdf';
        $runs = $isPdf ? $this->layout->read($path) : [];

        $running = $this->runningText->detect($runs);
        $pageTexts = array_map(
            fn (string $text) => $this->runningText->strip($text, $running),
            $pageTexts
        );
        $pageTexts = $this->repairer->repairPages($pageTexts);

        $outline = $this->outline->detect($runs);
        $markers = $this->footnotes->markers($runs);
        $endnotes = $this->footnotes->endnotes($pageTexts, $outline);
        $visuals = $isPdf ? $this->vision->describe($path, $this->visualPages->select($runs)) : [];

        $sections = $this->structure->build($pageTexts, $outline, $markers, $visuals);

        return $this->chunks->build($sections, $endnotes);
    }
}
