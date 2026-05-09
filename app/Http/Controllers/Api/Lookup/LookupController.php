<?php

namespace App\Http\Controllers\Api\Lookup;

use App\Domain\Incidents\Models\IncidentCategory;
use App\Domain\Incidents\Models\IncidentType;
use App\Domain\Teams\Models\ResourceType;
use App\Domain\Teams\Models\Team;
use App\Domain\Teams\Models\TeamCategory;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class LookupController extends Controller
{
    public function incidentCategories(): JsonResponse
    {
        $items = IncidentCategory::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (IncidentCategory $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'description' => $category->description,
                'sort_order' => $category->sort_order,
            ])
            ->values()
            ->all();

        return response()->json([
            'items' => $items,
        ]);
    }

    public function incidentTypes(): JsonResponse
    {
        $items = IncidentType::query()
            ->with(['category', 'fields', 'defaultResources.resourceType.category'])
            ->join('incident_categories', 'incident_types.incident_category_id', '=', 'incident_categories.id')
            ->orderBy('incident_categories.sort_order')
            ->orderBy('incident_categories.name')
            ->orderBy('incident_types.name')
            ->select('incident_types.*')
            ->get()
            ->map(fn (IncidentType $type): array => [
                'id' => $type->id,
                'category_id' => $type->incident_category_id,
                'category_name' => $type->category?->name,
                'name' => $type->name,
                'description' => $type->description,
                'resource_defaults' => $type->defaultResources
                    ->map(fn ($default): array => [
                        'id' => $default->id,
                        'incident_type_id' => $default->incident_type_id,
                        'resource_type_id' => $default->resource_type_id,
                        'sort_order' => $default->sort_order,
                        'resource_type' => $default->resourceType ? [
                            'id' => $default->resourceType->id,
                            'category_id' => $default->resourceType->category_id,
                            'category_name' => $default->resourceType->category?->name,
                            'name' => $default->resourceType->name,
                            'unit_label' => $default->resourceType->unit_label,
                        ] : null,
                    ])
                    ->values()
                    ->all(),
                'fields' => $type->fields
                    ->map(function ($field): array {
                        $config = $field->config_json ?? [];

                        return [
                            'id' => $field->id,
                            'incident_type_id' => $field->incident_type_id,
                            'field_key' => $field->field_key,
                            'field_label' => $field->field_label,
                            'input_type' => $field->input_type,
                            'options' => $field->options_json ?? [],
                            'config' => $config,
                            'preset' => $config['preset'] ?? null,
                            'preset_label' => $config['preset_label'] ?? null,
                            'repeatable' => (bool) ($config['repeatable'] ?? false),
                            'fields' => $config['fields'] ?? [],
                            'default_value' => $field->default_value,
                            'placeholder' => $field->placeholder,
                            'unit' => $field->unit,
                            'is_required' => (bool) $field->is_required,
                            'sort_order' => $field->sort_order,
                            'min' => $field->min,
                            'max' => $field->max,
                            'step' => $field->step,
                        ];
                    })
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();

        return response()->json([
            'items' => $items,
        ]);
    }

    public function resourceTypes(): JsonResponse
    {
        $items = ResourceType::query()
            ->with('category')
            ->join('resource_type_categories', 'resource_types.category_id', '=', 'resource_type_categories.id')
            ->orderBy('resource_type_categories.sort_order')
            ->orderBy('resource_type_categories.name')
            ->orderBy('resource_types.name')
            ->select('resource_types.*')
            ->get()
            ->map(fn (ResourceType $resourceType): array => [
                'id' => $resourceType->id,
                'category_id' => $resourceType->category_id,
                'category_name' => $resourceType->category?->name,
                'name' => $resourceType->name,
                'unit_label' => $resourceType->unit_label,
            ])
            ->values()
            ->all();

        return response()->json([
            'items' => $items,
        ]);
    }

    public function teamCategories(): JsonResponse
    {
        $items = TeamCategory::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (TeamCategory $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'description' => $category->description,
                'sort_order' => $category->sort_order,
            ])
            ->values()
            ->all();

        return response()->json([
            'items' => $items,
        ]);
    }

    public function teams(): JsonResponse
    {
        $items = Team::query()
            ->with('category')
            ->join('team_categories', 'teams.team_category_id', '=', 'team_categories.id')
            ->orderBy('team_categories.sort_order')
            ->orderBy('team_categories.name')
            ->orderBy('teams.name')
            ->select('teams.*')
            ->get()
            ->map(fn (Team $team): array => [
                'id' => $team->id,
                'team_category_id' => $team->team_category_id,
                'category_name' => $team->category?->name,
                'name' => $team->name,
                'status' => $team->status,
            ])
            ->values()
            ->all();

        return response()->json([
            'items' => $items,
        ]);
    }
}
