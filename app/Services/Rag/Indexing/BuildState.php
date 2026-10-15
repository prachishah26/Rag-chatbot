<?php

namespace App\Services\Rag\Indexing;

/** The heading stack and finished sections while StructureBuilder walks the pages. */
class BuildState
{
    /** @var array<int, array{heading: Heading, region: ?string}> */
    private array $stack = [];

    private ?string $chapter = null;

    /** @var array<int, Section> */
    private array $sections = [];

    private Section $current;

    public function __construct()
    {
        $this->current = new Section([], null, null, []);
    }

    public function openHeading(Heading $heading, ?string $region): void
    {
        $this->closeSection();

        if ($chapter = $heading->chapter()) {
            // A chapter opener resets the outline; headings that follow sit under it.
            $this->chapter = $chapter;
            $this->stack = [['heading' => new Heading($heading->page, $heading->top, $chapter, 0), 'region' => null]];
        } else {
            $this->stack = array_values(array_filter(
                $this->stack,
                fn (array $entry) => $entry['heading']->level < $heading->level
            ));
            $this->stack[] = ['heading' => $heading, 'region' => $region];
        }

        $path = array_map(fn (array $entry) => $entry['heading']->text, $this->stack);
        $regions = array_filter(array_column($this->stack, 'region'));

        $this->current = new Section($path, $this->chapter, $regions ? end($regions) : null, []);
    }

    public function addElement(Element $element): void
    {
        $this->current = $this->current->withElement($element);
    }

    public function currentHeading(): ?string
    {
        return $this->stack ? end($this->stack)['heading']->text : null;
    }

    /** @return array<int, Section> */
    public function finish(): array
    {
        $this->closeSection();

        return $this->sections;
    }

    private function closeSection(): void
    {
        if ($this->current->elements) {
            $this->sections[] = $this->current;
        }
    }
}
