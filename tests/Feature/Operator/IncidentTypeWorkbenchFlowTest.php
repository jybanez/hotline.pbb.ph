<?php

namespace Tests\Feature\Operator;

use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IncidentTypeWorkbenchFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_operator_can_sync_selected_incident_types_details_and_resources(): void
    {
        $caller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incidentId = DB::table('incidents')->insertGetId([
            'citizen_id' => $caller->id,
            'actual_citizen_name' => $caller->name,
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => 'Normal',
            'called_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $categoryId = DB::table('incident_categories')->insertGetId([
            'name' => 'Medical',
            'description' => null,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $incidentTypeId = DB::table('incident_types')->insertGetId([
            'incident_category_id' => $categoryId,
            'name' => 'Vehicle Collision',
            'description' => 'Collision details',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $fieldId = DB::table('incident_type_fields')->insertGetId([
            'incident_type_id' => $incidentTypeId,
            'field_key' => 'patients',
            'field_label' => 'Patients',
            'input_type' => 'number',
            'options_json' => json_encode([]),
            'default_value' => 1,
            'placeholder' => 'Enter patient count',
            'unit' => 'persons',
            'is_required' => true,
            'sort_order' => 10,
            'min' => 1,
            'max' => 50,
            'step' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resourceCategoryId = DB::table('resource_type_categories')->insertGetId([
            'name' => 'Vehicle',
            'description' => null,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resourceTypeId = DB::table('resource_types')->insertGetId([
            'category_id' => $resourceCategoryId,
            'name' => 'Ambulance',
            'unit_label' => 'unit',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('incident_type_default_resources')->insert([
            'incident_type_id' => $incidentTypeId,
            'resource_type_id' => $resourceTypeId,
            'quantity_required' => 1,
            'notes' => null,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($operator)
            ->postJson("/api/operator/incidents/{$incidentId}/incident-type-details", [
                'items' => [
                    [
                        'incident_type_id' => $incidentTypeId,
                        'detail_entries' => [
                            [
                                'field_id' => $fieldId,
                                'field_key' => 'patients',
                                'field_value' => '2',
                            ],
                        ],
                        'resources_needed' => [
                            [
                                'resource_type_id' => $resourceTypeId,
                                'quantity_needed' => 3,
                                'notes' => 'High priority',
                            ],
                        ],
                    ],
                ],
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('incident.incident_types.0.id', $incidentTypeId)
            ->assertJsonPath('incident.incident_type_details.0.field_id', $fieldId)
            ->assertJsonPath('incident.incident_type_details.0.field_value', '2')
            ->assertJsonPath('incident.incident_resources_needed.0.resource_type_id', $resourceTypeId)
            ->assertJsonPath('incident.incident_resources_needed.0.quantity_needed', 3);

        $this->assertDatabaseHas('incident_incident_type', [
            'incident_id' => $incidentId,
            'incident_type_id' => $incidentTypeId,
        ]);

        $this->assertDatabaseHas('incident_type_details', [
            'incident_id' => $incidentId,
            'incident_type_id' => $incidentTypeId,
            'field_id' => $fieldId,
            'field_value' => '2',
        ]);

        $this->assertDatabaseHas('incident_resources_needed', [
            'incident_id' => $incidentId,
            'incident_type_id' => $incidentTypeId,
            'resource_type_id' => $resourceTypeId,
            'quantity_required' => 3,
            'notes' => 'High priority',
        ]);

        $this->actingAs($operator)
            ->getJson("/api/operator/incidents/{$incidentId}")
            ->assertOk()
            ->assertJsonCount(1, 'incident_types')
            ->assertJsonCount(1, 'incident_type_details')
            ->assertJsonCount(1, 'incident_resources_needed');
    }

    public function test_operator_can_sync_group_preset_incident_type_detail(): void
    {
        $caller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incidentId = DB::table('incidents')->insertGetId([
            'citizen_id' => $caller->id,
            'actual_citizen_name' => $caller->name,
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => 'Normal',
            'called_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $categoryId = DB::table('incident_categories')->insertGetId([
            'name' => 'Search',
            'description' => null,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $incidentTypeId = DB::table('incident_types')->insertGetId([
            'incident_category_id' => $categoryId,
            'name' => 'Missing Person',
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $config = [
            'preset' => 'missingPerson',
            'preset_label' => 'Missing Person',
            'repeatable' => true,
        ];

        $fieldId = DB::table('incident_type_fields')->insertGetId([
            'incident_type_id' => $incidentTypeId,
            'field_key' => 'missing_people',
            'field_label' => 'Missing People',
            'input_type' => 'group',
            'options_json' => null,
            'config_json' => json_encode($config),
            'default_value' => null,
            'placeholder' => null,
            'unit' => null,
            'is_required' => true,
            'sort_order' => 1,
            'min' => null,
            'max' => null,
            'step' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $value = json_encode([
            [
                'name' => 'Juan Dela Cruz',
                'last_seen_location' => 'Riverside',
            ],
        ]);

        $this->actingAs($operator)
            ->postJson("/api/operator/incidents/{$incidentId}/incident-type-details", [
                'items' => [
                    [
                        'incident_type_id' => $incidentTypeId,
                        'detail_entries' => [
                            [
                                'field_id' => $fieldId,
                                'field_key' => 'missing_people',
                                'field_value' => $value,
                            ],
                        ],
                        'resources_needed' => [],
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('incident.incident_types.0.fields.0.input_type', 'group')
            ->assertJsonPath('incident.incident_types.0.fields.0.config.preset', 'missingPerson')
            ->assertJsonPath('incident.incident_types.0.fields.0.fields', [])
            ->assertJsonPath('incident.incident_type_details.0.field_value', $value)
            ->assertJsonPath('incident.incident_type_details.0.config.preset', 'missingPerson');

        $this->assertDatabaseHas('incident_type_details', [
            'incident_id' => $incidentId,
            'incident_type_id' => $incidentTypeId,
            'field_id' => $fieldId,
            'field_value' => $value,
        ]);
    }

    public function test_sync_can_clear_selected_incident_types_and_related_rows(): void
    {
        $caller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incidentId = DB::table('incidents')->insertGetId([
            'citizen_id' => $caller->id,
            'actual_citizen_name' => $caller->name,
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => 'Normal',
            'called_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $categoryId = DB::table('incident_categories')->insertGetId([
            'name' => 'Medical',
            'description' => null,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $incidentTypeId = DB::table('incident_types')->insertGetId([
            'incident_category_id' => $categoryId,
            'name' => 'Vehicle Collision',
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $fieldId = DB::table('incident_type_fields')->insertGetId([
            'incident_type_id' => $incidentTypeId,
            'field_key' => 'patients',
            'field_label' => 'Patients',
            'input_type' => 'number',
            'options_json' => json_encode([]),
            'default_value' => null,
            'placeholder' => null,
            'unit' => null,
            'is_required' => false,
            'sort_order' => 10,
            'min' => null,
            'max' => null,
            'step' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resourceCategoryId = DB::table('resource_type_categories')->insertGetId([
            'name' => 'Vehicle',
            'description' => null,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resourceTypeId = DB::table('resource_types')->insertGetId([
            'category_id' => $resourceCategoryId,
            'name' => 'Ambulance',
            'unit_label' => 'unit',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('incident_incident_type')->insert([
            'incident_id' => $incidentId,
            'incident_type_id' => $incidentTypeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('incident_type_details')->insert([
            'incident_id' => $incidentId,
            'incident_type_id' => $incidentTypeId,
            'field_id' => $fieldId,
            'field_label' => 'Patients',
            'field_key' => 'patients',
            'field_value' => '2',
            'input_type' => 'number',
            'options_json' => json_encode([]),
            'unit' => null,
            'placeholder' => null,
            'is_required' => false,
            'sort_order' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('incident_resources_needed')->insert([
            'incident_id' => $incidentId,
            'incident_type_id' => $incidentTypeId,
            'resource_type_id' => $resourceTypeId,
            'quantity_required' => 1,
            'notes' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($operator)
            ->postJson("/api/operator/incidents/{$incidentId}/incident-type-details", [
                'items' => [],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(0, 'incident.incident_types')
            ->assertJsonCount(0, 'incident.incident_type_details')
            ->assertJsonCount(0, 'incident.incident_resources_needed');

        $this->assertDatabaseMissing('incident_incident_type', [
            'incident_id' => $incidentId,
            'incident_type_id' => $incidentTypeId,
        ]);

        $this->assertDatabaseMissing('incident_type_details', [
            'incident_id' => $incidentId,
            'incident_type_id' => $incidentTypeId,
        ]);

        $this->assertDatabaseMissing('incident_resources_needed', [
            'incident_id' => $incidentId,
            'incident_type_id' => $incidentTypeId,
        ]);
    }

    public function test_operator_can_attach_update_and_remove_incident_type_through_narrow_endpoints(): void
    {
        $caller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incidentId = DB::table('incidents')->insertGetId([
            'citizen_id' => $caller->id,
            'actual_citizen_name' => $caller->name,
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => 'Normal',
            'called_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $categoryId = DB::table('incident_categories')->insertGetId([
            'name' => 'Medical',
            'description' => null,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $incidentTypeId = DB::table('incident_types')->insertGetId([
            'incident_category_id' => $categoryId,
            'name' => 'Vehicle Collision',
            'description' => 'Collision details',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $fieldId = DB::table('incident_type_fields')->insertGetId([
            'incident_type_id' => $incidentTypeId,
            'field_key' => 'patients',
            'field_label' => 'Patients',
            'input_type' => 'number',
            'options_json' => json_encode([]),
            'default_value' => null,
            'placeholder' => 'Enter patient count',
            'unit' => 'persons',
            'is_required' => true,
            'sort_order' => 10,
            'min' => 1,
            'max' => 50,
            'step' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resourceCategoryId = DB::table('resource_type_categories')->insertGetId([
            'name' => 'Vehicle',
            'description' => null,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resourceTypeId = DB::table('resource_types')->insertGetId([
            'category_id' => $resourceCategoryId,
            'name' => 'Ambulance',
            'unit_label' => 'unit',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('incident_type_default_resources')->insert([
            'incident_type_id' => $incidentTypeId,
            'resource_type_id' => $resourceTypeId,
            'quantity_required' => 1,
            'notes' => null,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($operator)
            ->postJson("/api/operator/incidents/{$incidentId}/incident-types/{$incidentTypeId}")
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('incident_type.incident_type_id', $incidentTypeId);

        $this->assertDatabaseHas('incident_incident_type', [
            'incident_id' => $incidentId,
            'incident_type_id' => $incidentTypeId,
        ]);

        $this->actingAs($operator)
            ->postJson("/api/operator/incidents/{$incidentId}/incident-types/{$incidentTypeId}/details", [
                'field_id' => $fieldId,
                'field_key' => 'patients',
                'field_value' => '3',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('detail.field_value', '3');

        $this->actingAs($operator)
            ->postJson("/api/operator/incidents/{$incidentId}/incident-types/{$incidentTypeId}/resources/{$resourceTypeId}", [
                'quantity_needed' => 4,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('resource.quantity_needed', 4);

        $this->assertDatabaseHas('incident_type_details', [
            'incident_id' => $incidentId,
            'incident_type_id' => $incidentTypeId,
            'field_id' => $fieldId,
            'field_value' => '3',
        ]);

        $this->assertDatabaseHas('incident_resources_needed', [
            'incident_id' => $incidentId,
            'incident_type_id' => $incidentTypeId,
            'resource_type_id' => $resourceTypeId,
            'quantity_required' => 4,
        ]);

        $this->actingAs($operator)
            ->deleteJson("/api/operator/incidents/{$incidentId}/incident-types/{$incidentTypeId}")
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('incident_type_id', $incidentTypeId);

        $this->assertDatabaseMissing('incident_incident_type', [
            'incident_id' => $incidentId,
            'incident_type_id' => $incidentTypeId,
        ]);

        $this->assertDatabaseMissing('incident_type_details', [
            'incident_id' => $incidentId,
            'incident_type_id' => $incidentTypeId,
        ]);

        $this->assertDatabaseMissing('incident_resources_needed', [
            'incident_id' => $incidentId,
            'incident_type_id' => $incidentTypeId,
        ]);
    }

    public function test_narrow_incident_type_endpoints_can_clear_detail_and_resource_values(): void
    {
        $caller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incidentId = DB::table('incidents')->insertGetId([
            'citizen_id' => $caller->id,
            'actual_citizen_name' => $caller->name,
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => 'Normal',
            'called_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $categoryId = DB::table('incident_categories')->insertGetId([
            'name' => 'Disaster',
            'description' => null,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $incidentTypeId = DB::table('incident_types')->insertGetId([
            'incident_category_id' => $categoryId,
            'name' => 'Landslide',
            'description' => 'Landslide details',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $fieldId = DB::table('incident_type_fields')->insertGetId([
            'incident_type_id' => $incidentTypeId,
            'field_key' => 'blocked_roads',
            'field_label' => 'Blocked Roads',
            'input_type' => 'textarea',
            'options_json' => json_encode([]),
            'default_value' => null,
            'placeholder' => null,
            'unit' => null,
            'is_required' => false,
            'sort_order' => 10,
            'min' => null,
            'max' => null,
            'step' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resourceCategoryId = DB::table('resource_type_categories')->insertGetId([
            'name' => 'Heavy Equipment',
            'description' => null,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resourceTypeId = DB::table('resource_types')->insertGetId([
            'category_id' => $resourceCategoryId,
            'name' => 'Bulldozer',
            'unit_label' => 'unit',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('incident_type_default_resources')->insert([
            'incident_type_id' => $incidentTypeId,
            'resource_type_id' => $resourceTypeId,
            'quantity_required' => 1,
            'notes' => null,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($operator)
            ->postJson("/api/operator/incidents/{$incidentId}/incident-types/{$incidentTypeId}/details", [
                'field_id' => $fieldId,
                'field_key' => 'blocked_roads',
                'field_value' => 'Northbound road closed',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->actingAs($operator)
            ->postJson("/api/operator/incidents/{$incidentId}/incident-types/{$incidentTypeId}/resources/{$resourceTypeId}", [
                'quantity_needed' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->actingAs($operator)
            ->postJson("/api/operator/incidents/{$incidentId}/incident-types/{$incidentTypeId}/details", [
                'field_id' => $fieldId,
                'field_key' => 'blocked_roads',
                'field_value' => '',
            ])
            ->assertOk()
            ->assertJsonPath('detail', null);

        $this->actingAs($operator)
            ->postJson("/api/operator/incidents/{$incidentId}/incident-types/{$incidentTypeId}/resources/{$resourceTypeId}", [
                'quantity_needed' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('resource', null);

        $this->assertDatabaseMissing('incident_type_details', [
            'incident_id' => $incidentId,
            'incident_type_id' => $incidentTypeId,
            'field_id' => $fieldId,
        ]);

        $this->assertDatabaseMissing('incident_resources_needed', [
            'incident_id' => $incidentId,
            'incident_type_id' => $incidentTypeId,
            'resource_type_id' => $resourceTypeId,
        ]);
    }

    public function test_workbench_payload_uses_catalog_incident_type_id_and_keeps_pivot_id_separate(): void
    {
        $caller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incidentId = DB::table('incidents')->insertGetId([
            'citizen_id' => $caller->id,
            'actual_citizen_name' => $caller->name,
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => 'Normal',
            'called_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $categoryId = DB::table('incident_categories')->insertGetId([
            'name' => 'Medical',
            'description' => null,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $incidentTypeId = DB::table('incident_types')->insertGetId([
            'incident_category_id' => $categoryId,
            'name' => 'Rescue',
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pivotId = DB::table('incident_incident_type')->insertGetId([
            'incident_id' => $incidentId,
            'incident_type_id' => $incidentTypeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($operator)
            ->getJson("/api/operator/incidents/{$incidentId}")
            ->assertOk()
            ->assertJsonPath('incident_types.0.id', $incidentTypeId)
            ->assertJsonPath('incident_types.0.incident_type_id', $incidentTypeId)
            ->assertJsonPath('incident_types.0.pivot.id', $pivotId);
    }

    public function test_narrow_detail_endpoint_accepts_field_key_without_field_id(): void
    {
        $caller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incidentId = DB::table('incidents')->insertGetId([
            'citizen_id' => $caller->id,
            'actual_citizen_name' => $caller->name,
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => 'Normal',
            'called_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $categoryId = DB::table('incident_categories')->insertGetId([
            'name' => 'Medical',
            'description' => null,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $incidentTypeId = DB::table('incident_types')->insertGetId([
            'incident_category_id' => $categoryId,
            'name' => 'Medical Emergency',
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('incident_type_fields')->insert([
            'incident_type_id' => $incidentTypeId,
            'field_key' => 'type',
            'field_label' => 'Type',
            'input_type' => 'select',
            'options_json' => json_encode(['Animal Bite']),
            'default_value' => null,
            'placeholder' => null,
            'unit' => null,
            'is_required' => true,
            'sort_order' => 1,
            'min' => null,
            'max' => null,
            'step' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($operator)
            ->postJson("/api/operator/incidents/{$incidentId}/incident-types/{$incidentTypeId}/details", [
                'field_key' => 'type',
                'field_value' => 'Animal Bite',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('detail.field_key', 'type')
            ->assertJsonPath('detail.field_value', 'Animal Bite');

        $this->assertDatabaseHas('incident_type_details', [
            'incident_id' => $incidentId,
            'incident_type_id' => $incidentTypeId,
            'field_key' => 'type',
            'field_value' => 'Animal Bite',
        ]);
    }
}
