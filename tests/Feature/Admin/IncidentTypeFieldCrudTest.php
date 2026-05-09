<?php

namespace Tests\Feature\Admin;

use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IncidentTypeFieldCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_admin_can_create_update_and_list_incident_type_fields(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $typeId = DB::table('incident_types')->insertGetId([
            'incident_category_id' => DB::table('incident_categories')->insertGetId([
                'name' => 'Medical',
                'description' => null,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'name' => 'Medical Emergency',
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $create = $this->actingAs($admin)->postJson('/api/admin/incident-type-fields', [
            'incident_type_id' => $typeId,
            'field_key' => 'patients',
            'field_label' => 'Patients',
            'input_type' => 'number',
            'options' => [],
            'default_value' => '1',
            'placeholder' => 'Enter patient count',
            'unit' => 'persons',
            'is_required' => true,
            'sort_order' => 1,
            'min' => 1,
            'max' => 50,
            'step' => 1,
        ]);

        $create
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('field.field_key', 'patients')
            ->assertJsonPath('field.is_required', true);

        $fieldId = $create->json('field.id');

        $this->actingAs($admin)
            ->postJson("/api/admin/incident-type-fields/{$fieldId}", [
                'incident_type_id' => $typeId,
                'field_key' => 'patient_count',
                'field_label' => 'Patient Count',
                'input_type' => 'number',
                'options' => [],
                'default_value' => '2',
                'placeholder' => 'Enter patient count',
                'unit' => 'persons',
                'is_required' => true,
                'sort_order' => 2,
                'min' => 1,
                'max' => 80,
                'step' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('field.field_key', 'patient_count')
            ->assertJsonPath('field.sort_order', 2);

        $this->actingAs($admin)
            ->getJson("/api/admin/incident-type-fields?incident_type_id={$typeId}")
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.field_key', 'patient_count');
    }

    public function test_admin_can_create_multiselect_incident_type_field(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $typeId = DB::table('incident_types')->insertGetId([
            'incident_category_id' => DB::table('incident_categories')->insertGetId([
                'name' => 'Rescue',
                'description' => null,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'name' => 'Search And Rescue',
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->postJson('/api/admin/incident-type-fields', [
            'incident_type_id' => $typeId,
            'field_key' => 'hazards',
            'field_label' => 'Hazards',
            'input_type' => 'multiselect',
            'options' => ['Flooding', 'Debris', 'Downed Power Lines'],
            'is_required' => false,
            'sort_order' => 1,
        ])
            ->assertCreated()
            ->assertJsonPath('field.input_type', 'multiselect')
            ->assertJsonPath('field.options.0', 'Flooding')
            ->assertJsonPath('field.options.2', 'Downed Power Lines');
    }

    public function test_admin_can_create_group_preset_incident_type_field_with_preset_metadata(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $typeId = DB::table('incident_types')->insertGetId([
            'incident_category_id' => DB::table('incident_categories')->insertGetId([
                'name' => 'Search',
                'description' => null,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'name' => 'Missing Person',
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $create = $this->actingAs($admin)->postJson('/api/admin/incident-type-fields', [
            'incident_type_id' => $typeId,
            'field_key' => 'missing_people',
            'field_label' => 'Missing People',
            'input_type' => 'group',
            'config' => [
                'preset' => 'missingPerson',
            ],
            'is_required' => true,
            'sort_order' => 1,
        ]);

        $create
            ->assertCreated()
            ->assertJsonPath('field.input_type', 'group')
            ->assertJsonPath('field.config.preset', 'missingPerson')
            ->assertJsonPath('field.config.repeatable', true)
            ->assertJsonPath('field.fields', [])
            ->assertJsonPath('field.repeatable', true);

        $this->assertArrayNotHasKey('fields', $create->json('field.config'));

        $fieldId = $create->json('field.id');

        $this->assertDatabaseHas('incident_type_fields', [
            'id' => $fieldId,
            'input_type' => 'group',
        ]);

        $this->actingAs($admin)
            ->getJson("/api/admin/incident-type-fields?incident_type_id={$typeId}")
            ->assertOk()
            ->assertJsonPath('items.0.config.preset', 'missingPerson')
            ->assertJsonPath('items.0.fields', []);
    }

    public function test_admin_cannot_create_unsupported_incident_type_field_input_type(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $typeId = DB::table('incident_types')->insertGetId([
            'incident_category_id' => DB::table('incident_categories')->insertGetId([
                'name' => 'Legacy',
                'description' => null,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'name' => 'Legacy Field Test',
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->postJson('/api/admin/incident-type-fields', [
            'incident_type_id' => $typeId,
            'field_key' => 'legacy_date',
            'field_label' => 'Legacy Date',
            'input_type' => 'date',
            'options' => [],
            'is_required' => false,
            'sort_order' => 1,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('input_type');
    }

    public function test_admin_delete_is_blocked_when_incident_type_field_is_referenced(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $typeId = DB::table('incident_types')->insertGetId([
            'incident_category_id' => DB::table('incident_categories')->insertGetId([
                'name' => 'Fire',
                'description' => null,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'name' => 'House Fire',
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $fieldId = DB::table('incident_type_fields')->insertGetId([
            'incident_type_id' => $typeId,
            'field_key' => 'occupants',
            'field_label' => 'Occupants',
            'input_type' => 'number',
            'options_json' => null,
            'default_value' => null,
            'placeholder' => null,
            'unit' => 'persons',
            'is_required' => true,
            'sort_order' => 1,
            'min' => null,
            'max' => null,
            'step' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $incidentId = DB::table('incidents')->insertGetId([
            'caller_id' => User::factory()->create(['role' => UserRole::Caller])->id,
            'actual_caller_name' => 'Caller One',
            'actual_caller_relationship' => 'self',
            'operator_id' => User::factory()->create(['role' => UserRole::Operator])->id,
            'status' => 'active',
            'alert_level' => 'normal',
            'latitude' => null,
            'longitude' => null,
            'location' => 'Barangay Hall',
            'location_road' => null,
            'location_suburb' => null,
            'location_barangay' => null,
            'location_citymunicipality' => null,
            'location_country' => null,
            'other_details' => null,
            'called_at' => now(),
            'resolved_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('incident_type_details')->insert([
            'incident_id' => $incidentId,
            'incident_type_id' => $typeId,
            'field_id' => $fieldId,
            'field_key' => 'occupants',
            'field_label' => 'Occupants',
            'field_value' => '3',
            'input_type' => 'number',
            'options_json' => null,
            'unit' => 'persons',
            'placeholder' => null,
            'is_required' => true,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/incident-type-fields/{$fieldId}")
            ->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('references.0.table', 'incident_type_details');
    }

    public function test_admin_can_hard_delete_unreferenced_incident_type_field(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $fieldId = DB::table('incident_type_fields')->insertGetId([
            'incident_type_id' => DB::table('incident_types')->insertGetId([
                'incident_category_id' => DB::table('incident_categories')->insertGetId([
                    'name' => 'Weather',
                    'description' => null,
                    'sort_order' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]),
                'name' => 'Flood',
                'description' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'field_key' => 'water_level',
            'field_label' => 'Water Level',
            'input_type' => 'number',
            'options_json' => null,
            'default_value' => null,
            'placeholder' => null,
            'unit' => 'cm',
            'is_required' => false,
            'sort_order' => 1,
            'min' => null,
            'max' => null,
            'step' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/incident-type-fields/{$fieldId}")
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('incident_type_fields', [
            'id' => $fieldId,
        ]);
    }
}
