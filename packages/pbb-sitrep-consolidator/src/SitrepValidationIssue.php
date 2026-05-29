<?php

namespace Pbb\Sitreps\Consolidation;

final class SitrepValidationIssue
{
    public function __construct(
        public readonly string $severity,
        public readonly string $code,
        public readonly string $message,
        public readonly ?string $path = null,
        public readonly mixed $value = null,
        public readonly ?int $sourceIndex = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'severity' => $this->severity,
            'code' => $this->code,
            'message' => $this->message,
            'path' => $this->path,
            'value' => $this->value,
            'source_index' => $this->sourceIndex,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
