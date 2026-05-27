<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Incidents\Models\IncidentTypeField;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class IncidentTypeFieldController extends Controller
{
    private const SUPPORTED_INPUT_TYPES = [
        'text',
        'textarea',
        'number',
        'select',
        'multiselect',
        'group',
    ];

    private const GROUP_PRESETS = [
        'person' => [
            'label' => 'Person',
            'repeatable' => false,
        ],
        'address' => [
            'label' => 'Address',
            'repeatable' => false,
        ],
        'missingPerson' => [
            'label' => 'Missing Person',
            'repeatable' => true,
        ],
        'evacuee' => [
            'label' => 'Evacuee',
            'repeatable' => true,
        ],
        'family' => [
            'label' => 'Family',
            'repeatable' => true,
        ],
        'casualtyPatient' => [
            'label' => 'Casualty / Patient',
            'repeatable' => true,
        ],
        'infrastructureDamage' => [
            'label' => 'Infrastructure Damage',
            'repeatable' => true,
        ],
        'shelterDamage' => [
            'label' => 'Shelter Damage',
            'repeatable' => true,
        ],
        'roadAccessStatus' => [
            'label' => 'Road / Access Status',
            'repeatable' => true,
        ],
        'vehicleInvolved' => [
            'label' => 'Vehicle Involved',
            'repeatable' => true,
        ],
    ];

    public function index(Request $request): JsonResponse
    {
        $query = IncidentTypeField::query()
            ->with('incidentType.category')
            ->join('incident_types', 'incident_type_fields.incident_type_id', '=', 'incident_types.id')
            ->join('incident_categories', 'incident_types.incident_category_id', '=', 'incident_categories.id')
            ->orderBy('incident_categories.sort_order')
            ->orderBy('incident_categories.name')
            ->orderBy('incident_types.name')
            ->orderBy('incident_type_fields.sort_order')
            ->orderBy('incident_type_fields.id')
            ->select('incident_type_fields.*');

        if ($request->filled('incident_type_id')) {
            $query->where('incident_type_fields.incident_type_id', (int) $request->integer('incident_type_id'));
        }

        return response()->json([
            'items' => $query->get()->map(fn (IncidentTypeField $field) => $this->serializeField($field))->values()->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);
        $field = IncidentTypeField::query()->create($validated);

        return response()->json([
            'ok' => true,
            'field' => $this->serializeField($field->fresh('incidentType.category')),
        ], 201);
    }

    public function update(Request $request, IncidentTypeField $field): JsonResponse
    {
        $validated = $this->validatePayload($request, $field);
        $field->fill($validated)->save();

        return response()->json([
            'ok' => true,
            'field' => $this->serializeField($field->fresh('incidentType.category')),
        ]);
    }

    public function destroy(IncidentTypeField $field): JsonResponse
    {
        $detailCount = DB::table('incident_type_details')
            ->where('field_id', $field->id)
            ->count();

        if ($detailCount > 0) {
            return response()->json([
                'ok' => false,
                'message' => "Delete blocked for {$field->field_label}.",
                'references' => [[
                    'table' => 'incident_type_details',
                    'column' => 'field_id',
                    'label' => 'Incident type detail records',
                    'count' => $detailCount,
                ]],
            ], 409);
        }

        $field->delete();

        return response()->json([
            'ok' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, ?IncidentTypeField $field = null): array
    {
        $validated = $request->validate([
            'incident_type_id' => ['required', 'integer', 'exists:incident_types,id'],
            'field_key' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9_]+$/',
                Rule::unique('incident_type_fields', 'field_key')
                    ->where(fn ($query) => $query->where('incident_type_id', (int) $request->integer('incident_type_id')))
                    ->ignore($field?->id),
            ],
            'field_label' => ['required', 'string', 'max:255'],
            'input_type' => ['required', 'string', 'max:50', Rule::in(self::SUPPORTED_INPUT_TYPES)],
            'options' => ['nullable', 'array'],
            'options.*' => ['nullable', 'string', 'max:255'],
            'config' => ['nullable', 'array'],
            'config.preset' => ['nullable', 'string', Rule::in(array_keys(self::GROUP_PRESETS))],
            'default_value' => ['nullable'],
            'placeholder' => ['nullable', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:50'],
            'is_required' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'min' => ['nullable', 'numeric'],
            'max' => ['nullable', 'numeric'],
            'step' => ['nullable', 'numeric', 'gt:0'],
        ]);

        return [
            'incident_type_id' => (int) $validated['incident_type_id'],
            'field_key' => (string) $validated['field_key'],
            'field_label' => (string) $validated['field_label'],
            'input_type' => (string) $validated['input_type'],
            'options_json' => $validated['input_type'] === 'group' ? null : (array_values(array_filter(array_map(
                static fn ($value) => trim((string) $value),
                $validated['options'] ?? []
            ), static fn ($value) => $value !== '')) ?: null),
            'config_json' => $this->buildConfig((string) $validated['input_type'], $validated['config'] ?? []),
            'default_value' => $validated['input_type'] !== 'group' && array_key_exists('default_value', $validated) && $validated['default_value'] !== null
                ? (string) $validated['default_value']
                : null,
            'placeholder' => $validated['input_type'] !== 'group' ? ($validated['placeholder'] ?? null) : null,
            'unit' => $validated['input_type'] === 'number' ? ($validated['unit'] ?? null) : null,
            'is_required' => (bool) ($validated['is_required'] ?? false),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'min' => $validated['input_type'] === 'number' && array_key_exists('min', $validated) && $validated['min'] !== null ? (float) $validated['min'] : null,
            'max' => $validated['input_type'] === 'number' && array_key_exists('max', $validated) && $validated['max'] !== null ? (float) $validated['max'] : null,
            'step' => $validated['input_type'] === 'number' && array_key_exists('step', $validated) && $validated['step'] !== null ? (float) $validated['step'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null
     */
    private function buildConfig(string $inputType, array $config): ?array
    {
        if ($inputType !== 'group') {
            return null;
        }

        $presetName = (string) ($config['preset'] ?? '');
        $preset = self::GROUP_PRESETS[$presetName] ?? null;

        if ($preset === null) {
            abort(422, 'A supported group preset is required.');
        }

        return [
            'preset' => $presetName,
            'preset_label' => $preset['label'],
            'repeatable' => (bool) $preset['repeatable'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeField(IncidentTypeField $field): array
    {
        $config = $field->config_json ?? [];

        return [
            'id' => $field->id,
            'incident_type_id' => $field->incident_type_id,
            'incident_type_name' => $field->incidentType?->name,
            'category_name' => $field->incidentType?->category?->name,
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
            'created_at' => $field->created_at?->toIso8601String(),
        ];
    }
}
