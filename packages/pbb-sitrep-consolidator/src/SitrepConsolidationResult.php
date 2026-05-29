<?php

namespace Pbb\Sitreps\Consolidation;

final class SitrepConsolidationResult
{
    /**
     * @param array<string, mixed>|null $sitrep
     * @param SitrepValidationIssue[] $issues
     * @param array<int, array<string, mixed>> $sourceIndex
     */
    public function __construct(
        public readonly bool $ok,
        public readonly ?array $sitrep,
        public readonly array $issues = [],
        public readonly array $sourceIndex = [],
    ) {
    }

    /**
     * @return SitrepValidationIssue[]
     */
    public function errors(): array
    {
        return array_values(array_filter(
            $this->issues,
            static fn (SitrepValidationIssue $issue): bool => $issue->severity === 'error',
        ));
    }

    /**
     * @return SitrepValidationIssue[]
     */
    public function warnings(): array
    {
        return array_values(array_filter(
            $this->issues,
            static fn (SitrepValidationIssue $issue): bool => $issue->severity === 'warning',
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'sitrep' => $this->sitrep,
            'warnings' => array_map(
                static fn (SitrepValidationIssue $issue): array => $issue->toArray(),
                $this->warnings(),
            ),
            'errors' => array_map(
                static fn (SitrepValidationIssue $issue): array => $issue->toArray(),
                $this->errors(),
            ),
            'source_index' => $this->sourceIndex,
        ];
    }
}
