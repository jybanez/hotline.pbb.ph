<?php

namespace App\Support\Incidents;

use App\Domain\Incidents\Models\Incident;
use App\Domain\Incidents\Models\IncidentResourceNeeded;
use App\Domain\Incidents\Models\IncidentType;
use App\Domain\Incidents\Models\IncidentTypeDetail;
use App\Domain\Incidents\Models\IncidentTypeField;
use App\Domain\Teams\Models\ResourceType;
use App\Domain\Users\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class IncidentTypeWorkbenchService
{
    public function attach(User $operator, Incident $incident, IncidentType $incidentType): IncidentType
    {
        $this->authorizeOperator($operator, $incident);

        $incident->incidentTypes()->syncWithoutDetaching([$incidentType->id]);

        return $this->loadAttachedIncidentType($incident, $incidentType->id);
    }

    public function remove(User $operator, Incident $incident, IncidentType $incidentType): void
    {
        $this->authorizeOperator($operator, $incident);

        DB::transaction(function () use ($incident, $incidentType): void {
            $incident->incidentTypeDetails()
                ->where('incident_type_id', $incidentType->id)
                ->delete();

            $incident->incidentResourcesNeeded()
                ->where('incident_type_id', $incidentType->id)
                ->delete();

            $incident->incidentTypes()->detach($incidentType->id);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function saveDetail(User $operator, Incident $incident, IncidentType $incidentType, array $attributes): ?IncidentTypeDetail
    {
        $this->authorizeOperator($operator, $incident);

        $attachedType = $this->ensureIncidentTypeAttached($incident, $incidentType);
        $field = $this->resolveIncidentTypeField($attachedType, $attributes);

        if ($field === null) {
            throw new RuntimeException('The selected incident field is invalid.');
        }

        $fieldValue = $this->normalizeFieldValue($attributes['field_value'] ?? null, $field->input_type);

        if ($fieldValue === null) {
            $incident->incidentTypeDetails()
                ->where('incident_type_id', $incidentType->id)
                ->where('field_id', $field->id)
                ->delete();

            return null;
        }

        /** @var IncidentTypeDetail $detail */
        $detail = IncidentTypeDetail::query()->updateOrCreate(
            [
                'incident_id' => $incident->id,
                'incident_type_id' => $incidentType->id,
                'field_id' => $field->id,
            ],
            [
                'field_label' => $field->field_label,
                'field_key' => $field->field_key,
                'field_value' => $fieldValue,
                'input_type' => $field->input_type,
                'options_json' => $field->options_json ?? [],
                'config_json' => $field->config_json ?? [],
                'unit' => $field->unit,
                'placeholder' => $field->placeholder,
                'is_required' => (bool) $field->is_required,
                'sort_order' => (int) $field->sort_order,
            ],
        );

        return $detail->fresh();
    }

    public function saveResource(
        User $operator,
        Incident $incident,
        IncidentType $incidentType,
        ResourceType $resourceType,
        mixed $quantity,
        mixed $notes = null,
    ): ?IncidentResourceNeeded {
        $this->authorizeOperator($operator, $incident);

        $attachedType = $this->ensureIncidentTypeAttached($incident, $incidentType);

        if (! $this->allowsResourceType($incident, $attachedType, $resourceType->id)) {
            throw new RuntimeException('The selected incident resource is invalid.');
        }

        $quantityNeeded = $this->normalizeQuantity($quantity);

        if ($quantityNeeded <= 0) {
            $incident->incidentResourcesNeeded()
                ->where('incident_type_id', $incidentType->id)
                ->where('resource_type_id', $resourceType->id)
                ->delete();

            return null;
        }

        /** @var IncidentResourceNeeded $resource */
        $resource = IncidentResourceNeeded::query()->updateOrCreate(
            [
                'incident_id' => $incident->id,
                'incident_type_id' => $incidentType->id,
                'resource_type_id' => $resourceType->id,
            ],
            [
                'quantity_required' => $quantityNeeded,
                'notes' => $this->normalizeOptionalString($notes),
            ],
        );

        return $resource->fresh();
    }

    /**
     * @param  array<int, mixed>  $items
     */
    public function sync(User $operator, Incident $incident, array $items): Incident
    {
        $this->authorizeOperator($operator, $incident);

        $normalizedItems = $this->normalizeItems($items);
        $incidentTypeIds = array_values(array_unique(array_map(
            static fn (array $item): int => (int) $item['incident_type_id'],
            $normalizedItems,
        )));

        $incidentTypes = IncidentType::query()
            ->with(['fields', 'defaultResources'])
            ->whereIn('id', $incidentTypeIds)
            ->get()
            ->keyBy('id');

        if (count($incidentTypeIds) !== $incidentTypes->count()) {
            throw new RuntimeException('One or more selected incident types are invalid.');
        }

        $resourceTypeIds = collect($normalizedItems)
            ->flatMap(fn (array $item) => $item['resources_needed'])
            ->map(fn (array $resource): int => (int) $resource['resource_type_id'])
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($resourceTypeIds !== []) {
            $resourceTypeCount = ResourceType::query()
                ->whereIn('id', $resourceTypeIds)
                ->count();

            if ($resourceTypeCount !== count($resourceTypeIds)) {
                throw new RuntimeException('One or more selected resource types are invalid.');
            }
        }

        DB::transaction(function () use ($incident, $normalizedItems, $incidentTypes): void {
            $incident->incidentTypes()->sync($incidentTypes->keys()->all());

            DB::table('incident_type_details')
                ->where('incident_id', $incident->id)
                ->delete();

            DB::table('incident_resources_needed')
                ->where('incident_id', $incident->id)
                ->delete();

            $detailRows = [];
            $resourceRows = [];
            $now = now();

            foreach ($normalizedItems as $item) {
                /** @var IncidentType $incidentType */
                $incidentType = $incidentTypes->get((int) $item['incident_type_id']);
                $fieldsById = $incidentType->fields->keyBy('id');
                $fieldsByKey = $incidentType->fields->keyBy('field_key');

                foreach ($item['detail_entries'] as $entry) {
                    $field = null;
                    $fieldId = (int) ($entry['field_id'] ?? 0);
                    $fieldKey = trim((string) ($entry['field_key'] ?? ''));

                    if ($fieldId > 0) {
                        $field = $fieldsById->get($fieldId);
                    }

                    if ($field === null && $fieldKey !== '') {
                        $field = $fieldsByKey->get($fieldKey);
                    }

                    if ($field === null) {
                        continue;
                    }

                    $fieldValue = $this->normalizeFieldValue($entry['field_value'] ?? null, $field->input_type);

                    if ($fieldValue === null) {
                        continue;
                    }

                    $detailRows[] = [
                        'incident_id' => $incident->id,
                        'incident_type_id' => $incidentType->id,
                        'field_id' => $field->id,
                        'field_label' => $field->field_label,
                        'field_key' => $field->field_key,
                        'field_value' => $fieldValue,
                        'input_type' => $field->input_type,
                        'options_json' => json_encode($field->options_json ?? []),
                        'config_json' => json_encode($field->config_json ?? []),
                        'unit' => $field->unit,
                        'placeholder' => $field->placeholder,
                        'is_required' => (bool) $field->is_required,
                        'sort_order' => (int) $field->sort_order,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                foreach ($item['resources_needed'] as $resource) {
                    $resourceTypeId = (int) ($resource['resource_type_id'] ?? 0);
                    $quantityNeeded = $this->normalizeQuantity(
                        $resource['quantity_needed'] ?? $resource['quantity_required'] ?? null,
                    );

                    if ($resourceTypeId <= 0 || $quantityNeeded <= 0) {
                        continue;
                    }

                    $resourceRows[] = [
                        'incident_id' => $incident->id,
                        'incident_type_id' => $incidentType->id,
                        'resource_type_id' => $resourceTypeId,
                        'quantity_required' => $quantityNeeded,
                        'notes' => $this->normalizeOptionalString($resource['notes'] ?? null),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            if ($detailRows !== []) {
                DB::table('incident_type_details')->insert($detailRows);
            }

            if ($resourceRows !== []) {
                DB::table('incident_resources_needed')->insert($resourceRows);
            }
        });

        return $incident->fresh();
    }

    private function authorizeOperator(User $operator, Incident $incident): void
    {
        if ((int) $incident->operator_id !== (int) $operator->id) {
            throw new RuntimeException('You cannot manage incident types for this incident.');
        }
    }

    private function loadAttachedIncidentType(Incident $incident, int $incidentTypeId): IncidentType
    {
        $attached = $incident->incidentTypes()
            ->with(['category', 'fields', 'defaultResources.resourceType.category'])
            ->where('incident_types.id', $incidentTypeId)
            ->first();

        if (! $attached instanceof IncidentType) {
            throw new RuntimeException('The selected incident type is invalid.');
        }

        return $attached;
    }

    private function ensureIncidentTypeAttached(Incident $incident, IncidentType $incidentType): IncidentType
    {
        $incident->incidentTypes()->syncWithoutDetaching([$incidentType->id]);

        return $this->loadAttachedIncidentType($incident, $incidentType->id);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function resolveIncidentTypeField(IncidentType $incidentType, array $attributes): ?IncidentTypeField
    {
        $fieldId = (int) ($attributes['field_id'] ?? 0);
        $fieldKey = Str::lower(trim((string) ($attributes['field_key'] ?? '')));
        $fields = $incidentType->fields;

        if ($fieldId > 0) {
            $match = $fields->firstWhere('id', $fieldId);

            if ($match instanceof IncidentTypeField) {
                return $match;
            }
        }

        if ($fieldKey === '') {
            return null;
        }

        $match = $fields->first(fn (IncidentTypeField $field): bool => Str::lower($field->field_key) === $fieldKey);

        return $match instanceof IncidentTypeField ? $match : null;
    }

    private function allowsResourceType(Incident $incident, IncidentType $incidentType, int $resourceTypeId): bool
    {
        if ($resourceTypeId <= 0) {
            return false;
        }

        $hasDefault = $incidentType->defaultResources
            ->contains(fn ($resource): bool => (int) $resource->resource_type_id === $resourceTypeId);

        if ($hasDefault) {
            return true;
        }

        return $incident->incidentResourcesNeeded()
            ->where('incident_type_id', $incidentType->id)
            ->where('resource_type_id', $resourceTypeId)
            ->exists();
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizeItems(array $items): array
    {
        return collect($items)
            ->filter(fn ($item): bool => is_array($item))
            ->map(function (array $item): array {
                $incidentTypeId = (int) ($item['incident_type_id'] ?? $item['id'] ?? 0);

                return [
                    'incident_type_id' => $incidentTypeId,
                    'detail_entries' => collect($item['detail_entries'] ?? [])
                        ->filter(fn ($entry): bool => is_array($entry))
                        ->values()
                        ->all(),
                    'resources_needed' => collect($item['resources_needed'] ?? [])
                        ->filter(fn ($resource): bool => is_array($resource))
                        ->values()
                        ->all(),
                ];
            })
            ->filter(fn (array $item): bool => $item['incident_type_id'] > 0)
            ->unique('incident_type_id')
            ->values()
            ->all();
    }

    private function normalizeFieldValue(mixed $value, ?string $inputType): ?string
    {
        if (is_array($value)) {
            $value = implode(',', array_map(static fn ($item): string => trim((string) $item), $value));
        }

        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        if ($normalized === '') {
            return null;
        }

        if (strtolower((string) $inputType) === 'number' && ! is_numeric($normalized)) {
            return null;
        }

        return $normalized;
    }

    private function normalizeQuantity(mixed $value): int
    {
        $normalized = (int) $value;

        return $normalized > 0 ? $normalized : 0;
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized !== '' ? $normalized : null;
    }
}
