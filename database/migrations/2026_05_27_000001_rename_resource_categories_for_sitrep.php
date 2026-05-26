<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @var array<string, array{name: string, description: string, old_description: string}>
     */
    private array $renames = [
        'Rescue' => [
            'name' => 'Rescue and Extraction',
            'description' => 'Water, rope, and technical rescue or extraction resources.',
            'old_description' => 'Water, rope, and technical rescue equipment.',
        ],
        'Relief Supplies' => [
            'name' => 'Food and Relief Supplies',
            'description' => 'Food, water, and relief supplies for affected families.',
            'old_description' => 'Food, water, and relief supplies for affected families.',
        ],
        'Shelter' => [
            'name' => 'Shelter Support',
            'description' => 'Temporary shelter and shelter support materials.',
            'old_description' => 'Temporary shelter and shelter support materials.',
        ],
        'Welfare' => [
            'name' => 'Welfare / Social Services',
            'description' => 'Social welfare and family support responders.',
            'old_description' => 'Social welfare and family support responders.',
        ],
        'Medical' => [
            'name' => 'Medical Response',
            'description' => 'Medical transport, response teams, and treatment supplies.',
            'old_description' => 'Medical transport, response teams, and treatment supplies.',
        ],
        'Rescue Tools' => [
            'name' => 'Specialized Rescue Equipment',
            'description' => 'Specialized rescue, extrication, access, and field support tools.',
            'old_description' => 'Specialized rescue, extrication, and access tools.',
        ],
        'Public Safety' => [
            'name' => 'Public Safety / Traffic Control',
            'description' => 'Law enforcement, traffic control, perimeter, and public-order resources.',
            'old_description' => 'Law enforcement, perimeter, and public-order resources.',
        ],
        'Heavy Equipment' => [
            'name' => 'Heavy Equipment / Clearing',
            'description' => 'Heavy equipment for debris, road, and structural clearing operations.',
            'old_description' => 'Heavy equipment for debris, road, and structural operations.',
        ],
        'Search & Assessment' => [
            'name' => 'Search and Damage Assessment',
            'description' => 'Search, field assessment, and damage assessment resources.',
            'old_description' => 'Search, field assessment, and reconnaissance resources.',
        ],
        'Surveillance' => [
            'name' => 'Aerial / Field Reconnaissance',
            'description' => 'Aerial, field, and remote observation resources.',
            'old_description' => 'Aerial and remote observation resources.',
        ],
        'Utilities' => [
            'name' => 'Utility Restoration',
            'description' => 'Utility repair and service restoration resources.',
            'old_description' => 'Utility repair and service restoration resources.',
        ],
        'Fire' => [
            'name' => 'Fire / Water Tanker Support',
            'description' => 'Fire suppression and water tanker support resources.',
            'old_description' => 'Fire suppression and water supply resources.',
        ],
        'Sanitation' => [
            'name' => 'Water and Sanitation',
            'description' => 'Potable water, sanitation, and hygiene support resources.',
            'old_description' => 'Sanitation and potable water support.',
        ],
    ];

    public function up(): void
    {
        foreach ($this->renames as $from => $to) {
            DB::table('resource_type_categories')
                ->where('name', $from)
                ->update([
                    'name' => $to['name'],
                    'description' => $to['description'],
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        foreach ($this->renames as $from => $to) {
            DB::table('resource_type_categories')
                ->where('name', $to['name'])
                ->update([
                    'name' => $from,
                    'description' => $to['old_description'],
                    'updated_at' => now(),
                ]);
        }
    }
};
