<?php

namespace Pbb\Sitreps\Viewer;

final class SitrepViewer
{
    /**
     * @param array<string, mixed>|string $sitrep
     * @param array<string, mixed>|SitrepViewOptions|null $options
     */
    public function render(array|string $sitrep, array|SitrepViewOptions|null $options = null): string
    {
        $payload = SitrepPayload::from($sitrep);
        $viewOptions = SitrepViewOptions::from($options);
        $fragment = (new SitrepDocumentRenderer())->render($payload, $viewOptions);

        if (! $viewOptions->fullDocument) {
            return $fragment;
        }

        $classes = ['pbb-sitrep-viewer-body'];
        if ($viewOptions->pdf) {
            $classes[] = 'is-pdf';
        }
        if ($viewOptions->layout !== 'document') {
            $classes[] = 'is-layout-'.$viewOptions->layout;
        }

        $style = $viewOptions->inlineCss ? '<style>'.PHP_EOL.$this->css().PHP_EOL.'</style>'.PHP_EOL : '';
        $title = Html::text($payload->get('title')).' | '.Html::text($viewOptions->titleSuffix);

        return '<!doctype html>'.PHP_EOL
            .'<html lang="'.Html::escape($viewOptions->lang).'">'.PHP_EOL
            .'<head>'.PHP_EOL
            .'<meta charset="utf-8">'.PHP_EOL
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'.PHP_EOL
            .'<title>'.$title.'</title>'.PHP_EOL
            .$style
            .'</head>'.PHP_EOL
            .'<body class="'.Html::escape(implode(' ', $classes)).'">'.PHP_EOL
            .$fragment.PHP_EOL
            .'</body>'.PHP_EOL
            .'</html>'.PHP_EOL;
    }

    /**
     * Render one official SITREP section as an embeddable fragment.
     *
     * @param array<string, mixed>|string $sitrep
     */
    public function renderSection(array|string $sitrep, string $section, array|SitrepViewOptions|null $options = null): string
    {
        return (new SitrepDocumentRenderer())->renderSection(SitrepPayload::from($sitrep), $section, SitrepViewOptions::from($options));
    }

    /**
     * Render selected official SITREP sections as one embeddable fragment.
     *
     * @param array<string, mixed>|string $sitrep
     * @param array<int, string> $sections
     */
    public function renderSections(array|string $sitrep, array $sections, array|SitrepViewOptions|null $options = null): string
    {
        return (new SitrepDocumentRenderer())->renderSections(SitrepPayload::from($sitrep), $sections, SitrepViewOptions::from($options));
    }

    /**
     * @return array<int, string>
     */
    public function sectionNames(): array
    {
        return SitrepDocumentRenderer::sectionNames();
    }

    /**
     * Build framework-agnostic visualization datasets for the full SITREP.
     *
     * @param array<string, mixed>|string $sitrep
     * @return array<string, mixed>
     */
    public function visualizationData(array|string $sitrep): array
    {
        return (new SitrepVisualizationDataBuilder())->build(SitrepPayload::from($sitrep));
    }

    /**
     * Build framework-agnostic visualization datasets for one SITREP section.
     *
     * @param array<string, mixed>|string $sitrep
     * @return array<string, mixed>
     */
    public function visualizationSection(array|string $sitrep, string $section): array
    {
        return (new SitrepVisualizationDataBuilder())->section(SitrepPayload::from($sitrep), $section);
    }

    public function css(): string
    {
        $path = dirname(__DIR__).DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.'sitrep-viewer.css';

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function schemaReference(): array
    {
        return SitrepSchemaReference::sections();
    }

    public function schemaReferenceHtml(): string
    {
        return SitrepSchemaReference::html();
    }
}
