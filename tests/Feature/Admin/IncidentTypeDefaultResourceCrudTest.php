<?php

namespace Tests\Feature\Admin;

use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IncidentTypeDefaultResourceCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_admin_can_create_update_and_view_incident_type_default_resources(): void
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

        $resourceTypeId = DB::table('resource_types')->insertGetId([
            'category_id' => DB::table('resource_type_categories')->insertGetId([
                'name' => 'Medical',
                'description' => null,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'name' => 'Ambulance',
            'unit_label' => 'unit',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $create = $this->actingAs($admin)->postJson("/api/admin/incident-types/{$typeId}/default-resources", [
            'resource_type_id' => $resourceTypeId,
            'quantity_required' => 2,
            'notes' => 'Prefer nearest available unit.',
            'sort_order' => 1,
        ]);

        $create
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('default_resource.quantity_required', 2)
            ->assertJsonPath('default_resource.resource_name', 'Ambulance');

        $defaultId = $create->json('default_resource.id');

        $this->actingAs($admin)
            ->postJson("/api/admin/incident-types/{$typeId}/default-resources/{$defaultId}", [
                'resource_type_id' => $resourceTypeId,
                'quantity_required' => 3,
                'notes' => 'Escalate for mass casualty.',
                'sort_order' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('default_resource.quantity_required', 3)
            ->assertJsonPath('default_resource.sort_order', 2);

        $this->actingAs($admin)
            ->getJson("/api/admin/incident-types/{$typeId}")
            ->assertOk()
            ->assertJsonCount(1, 'default_required_resources')
            ->assertJsonPath('default_required_resources.0.quantity_required', 3);
    }

    public function test_admin_cannot_duplicate_default_resource_for_same_incident_type(): void
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
            'name' => 'Rescue',
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resourceTypeId = DB::table('resource_types')->insertGetId([
            'category_id' => DB::table('resource_type_categories')->insertGetId([
                'name' => 'Rescue',
                'description' => null,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'name' => 'Rope',
            'unit_label' => 'coil',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('incident_type_default_resources')->insert([
            'incident_type_id' => $typeId,
            'resource_type_id' => $resourceTypeId,
            'quantity_required' => 1,
            'notes' => null,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->postJson("/api/admin/incident-types/{$typeId}/default-resources", [
                'resource_type_id' => $resourceTypeId,
                'quantity_required' => 2,
                'notes' => null,
                'sort_order' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['resource_type_id']);
    }

    public function test_admin_can_delete_incident_type_default_resource(): void
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
            'name' => 'Vehicle Fire',
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $defaultId = DB::table('incident_type_default_resources')->insertGetId([
            'incident_type_id' => $typeId,
            'resource_type_id' => DB::table('resource_types')->insertGetId([
                'category_id' => DB::table('resource_type_categories')->insertGetId([
                    'name' => 'Fire',
                    'description' => null,
                    'sort_order' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]),
                'name' => 'Fire Engine',
                'unit_label' => 'unit',
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'quantity_required' => 1,
            'notes' => null,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/incident-types/{$typeId}/default-resources/{$defaultId}")
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('incident_type_default_resources', [
            'id' => $defaultId,
        ]);
    }
}
