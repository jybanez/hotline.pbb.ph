<?php

namespace Tests\Feature\Admin;

use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IncidentTypeCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_admin_can_create_update_and_list_incident_types(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $categoryId = DB::table('incident_categories')->insertGetId([
            'name' => 'Medical',
            'description' => null,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $create = $this->actingAs($admin)->postJson('/api/admin/incident-types', [
            'incident_category_id' => $categoryId,
            'name' => 'Injury',
            'description' => 'Initial description',
        ]);

        $create
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('type.name', 'Injury')
            ->assertJsonPath('type.category_name', 'Medical');

        $typeId = $create->json('type.id');

        DB::table('incident_type_fields')->insert([
            'incident_type_id' => $typeId,
            'field_key' => 'injury_details',
            'field_label' => 'Injury Details',
            'input_type' => 'textarea',
            'options_json' => null,
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

        $resourceCategoryId = DB::table('resource_type_categories')->insertGetId([
            'name' => 'Medical',
            'description' => null,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resourceTypeId = DB::table('resource_types')->insertGetId([
            'category_id' => $resourceCategoryId,
            'name' => 'Ambulance',
            'unit_label' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('incident_type_default_resources')->insert([
            'incident_type_id' => $typeId,
            'resource_type_id' => $resourceTypeId,
            'quantity_required' => 1,
            'notes' => null,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->postJson("/api/admin/incident-types/{$typeId}", [
                'incident_category_id' => $categoryId,
                'name' => 'Serious Injury',
                'description' => 'Updated description',
            ])
            ->assertOk()
            ->assertJsonPath('type.name', 'Serious Injury')
            ->assertJsonPath('type.description', 'Updated description');

        $this->actingAs($admin)
            ->getJson('/api/admin/incident-types')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.name', 'Serious Injury')
            ->assertJsonPath('items.0.fields_count', 1)
            ->assertJsonPath('items.0.default_required_resources_count', 1);
    }

    public function test_admin_delete_is_blocked_when_incident_type_is_referenced(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $typeId = DB::table('incident_types')->insertGetId([
            'incident_category_id' => DB::table('incident_categories')->insertGetId([
                'name' => 'Weather',
                'description' => null,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'name' => 'Storm Surge',
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('incident_type_fields')->insert([
            'incident_type_id' => $typeId,
            'field_key' => 'wind_speed',
            'field_label' => 'Wind Speed',
            'input_type' => 'number',
            'options_json' => null,
            'default_value' => null,
            'placeholder' => null,
            'unit' => 'kph',
            'is_required' => true,
            'sort_order' => 1,
            'min' => null,
            'max' => null,
            'step' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/incident-types/{$typeId}")
            ->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('references.0.table', 'incident_type_fields');
    }

    public function test_admin_can_hard_delete_unreferenced_incident_type(): void
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

        $this->actingAs($admin)
            ->deleteJson("/api/admin/incident-types/{$typeId}")
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('incident_types', [
            'id' => $typeId,
        ]);
    }
}
