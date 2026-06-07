<?php

namespace Pbb\Sitreps\Viewer;

final class SitrepViewOptions
{
    public function __construct(
        public readonly bool $fullDocument = true,
        public readonly bool $inlineCss = true,
        public readonly bool $preview = false,
        public readonly bool $pdf = false,
        public readonly string $layout = 'document',
        public readonly string $titleSuffix = 'PBB SITREP',
        public readonly string $lang = 'en',
    ) {
    }

    /**
     * @param array<string, mixed>|self|null $options
     */
    public static function from(array|self|null $options): self
    {
        if ($options instanceof self) {
            return $options;
        }

        $options ??= [];

        return new self(
            fullDocument: (bool) ($options['full_document'] ?? $options['fullDocument'] ?? true),
            inlineCss: (bool) ($options['inline_css'] ?? $options['inlineCss'] ?? true),
            preview: (bool) ($options['preview'] ?? false),
            pdf: (bool) ($options['pdf'] ?? false),
            layout: self::layout((string) ($options['layout'] ?? 'document')),
            titleSuffix: (string) ($options['title_suffix'] ?? $options['titleSuffix'] ?? 'PBB SITREP'),
            lang: (string) ($options['lang'] ?? 'en'),
        );
    }

    private static function layout(string $layout): string
    {
        $layout = strtolower(trim(str_replace('-', '_', $layout)));

        return match ($layout) {
            'compact', 'section', 'brief' => $layout,
            default => 'document',
        };
    }
}
