<?php

use App\Domain\Calls\Models\CallSession;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Incidents\Models\IncidentCategory;
use App\Domain\Incidents\Models\IncidentResourceNeeded;
use App\Domain\Incidents\Models\IncidentType;
use App\Domain\Incidents\Models\IncidentTypeDetail;
use App\Domain\Incidents\Models\IncidentTypeField;
use App\Domain\Shared\Enums\AlertLevel;
use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Teams\Models\ResourceType;
use App\Domain\Teams\Models\Team;
use App\Domain\Teams\Models\TeamAssignment;
use App\Domain\Teams\Models\TeamAssignmentAllocatedResource;
use App\Domain\Teams\Models\TeamCategory;
use App\Domain\Users\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$samples = [
    [
        'sample_id' => 'GDLPE-SITREP-001',
        'reported_at' => '2026-05-24T05:42:00+08:00',
        'location_text' => 'Sitio Banawa, Barangay Guadalupe, Cebu City',
        'status' => 'Active',
        'priority' => 'High',
        'summary' => 'Rainwater runoff entered several roadside homes near a drainage channel.',
        'types' => [
            [
                'name' => 'Flood',
                'details' => [
                    ['road_access', 'Road / Access Status', 'roadAccessStatus', ['status' => 'Limited', 'route_location' => 'Sitio Banawa inner road', 'obstruction_type' => 'Knee-deep runoff; motorcycles have difficulty passing', 'cleared' => false]],
                ],
                'resources' => [['Rope', 1], ['Life Vest', 6], ['LifeBuoy Ring', 2]],
            ],
            [
                'name' => 'Family Displacement',
                'details' => [
                    ['affected_families', 'Affected Families', 'family', ['families' => 7, 'member_count' => 29, 'children_count' => 9, 'senior_citizens' => 3, 'temporary_shelter_needed' => true, 'displaced' => true]],
                ],
                'resources' => [['Social Welfare Team', 1], ['Food & Water Supplies', 7], ['Hygiene Kit', 7], ['Sleeping Kit', 7]],
            ],
        ],
        'assignments' => [
            ['Barangay Rescue Team', 'on_scene', 'Sample Rescue Lead', [['Life Vest', 6], ['LifeBuoy Ring', 2]]],
            ['Social Welfare Response Team', 'requested', 'Sample Welfare Lead', [['Food & Water Supplies', 7], ['Hygiene Kit', 7]]],
        ],
    ],
    [
        'sample_id' => 'GDLPE-SITREP-002',
        'reported_at' => '2026-05-24T06:30:00+08:00',
        'location_text' => 'Guadalupe Church area, Barangay Guadalupe, Cebu City',
        'status' => 'Active',
        'priority' => 'Medium',
        'summary' => 'Motorcycle skidded on wet pavement; rider sustained arm and leg injuries.',
        'types' => [[
            'name' => 'Road Accident',
            'details' => [
                ['vehicles_involved', 'Vehicles Involved', 'vehicleInvolved', [['vehicle_type' => 'Motorcycle', 'plate_number' => 'Unknown', 'damage' => 'Side scratches and broken mirror']]],
                ['patient_details', 'Patient Details', 'casualtyPatient', [['name' => 'Unknown male rider', 'age' => 'Approx. 25', 'condition' => 'Conscious, arm and leg abrasions', 'triage_category' => 'minor']]],
                ['road_access', 'Road / Access Status', 'roadAccessStatus', ['status' => 'Limited', 'route_location' => 'Guadalupe Church area', 'obstruction_type' => 'Traffic slowing near response area', 'cleared' => false]],
            ],
            'resources' => [['Ambulance', 1], ['Hydraulic Combi-tool', 1], ['Hydraulic Power Pack', 1]],
        ]],
        'assignments' => [['Barangay EMS Team', 'en_route', 'Sample EMS Lead', [['Ambulance', 1], ['Medical Supplies', 1]]]],
    ],
    [
        'sample_id' => 'GDLPE-SITREP-003',
        'reported_at' => '2026-05-24T07:18:00+08:00',
        'location_text' => 'Sitio Kalubihan, Barangay Guadalupe, Cebu City',
        'status' => 'Active',
        'priority' => 'High',
        'summary' => 'Retaining wall crack reported behind two houses after overnight rain.',
        'types' => [[
            'name' => 'Shelter Damage',
            'details' => [['shelter_damage', 'Shelter Damage Details', 'shelterDamage', ['damaged_structures' => 2, 'damage_level' => 'moderate', 'habitable' => 'needs assessment', 'notes' => 'Cracks visible along rear wall; residents report soil movement.']]],
            'resources' => [['Structural Assessment Team', 1], ['Tents', 2], ['Sleeping Kit', 8]],
        ]],
        'assignments' => [['Search and Assessment Team', 'accepted', 'Sample Assessment Lead', [['Structural Assessment Team', 1]]]],
    ],
    [
        'sample_id' => 'GDLPE-SITREP-004',
        'reported_at' => '2026-05-24T08:07:00+08:00',
        'location_text' => 'Guadalupe public market vicinity, Barangay Guadalupe, Cebu City',
        'status' => 'Active',
        'priority' => 'Medium',
        'summary' => 'Public disturbance between vendors caused crowding and temporary sidewalk blockage.',
        'types' => [[
            'name' => 'Public Disturbance / Riot',
            'details' => [
                ['disturbance_type', 'Disturbance type', 'select', 'Verbal conflict'],
                ['weapons_seen', 'Weapons seen', 'select', 'No'],
                ['immediate_danger', 'Immediate danger', 'select', 'No'],
                ['access_blocked', 'Road or access blocked', 'select', [['status' => 'Limited', 'route_location' => 'Guadalupe public market sidewalk', 'obstruction_type' => 'Crowding and temporary sidewalk blockage', 'cleared' => false]]],
            ],
            'resources' => [['Police Unit', 1], ['Crowd Control Team', 1], ['Perimeter Barrier', 1]],
        ]],
        'assignments' => [['Barangay Public Safety Team', 'on_scene', 'Sample Public Safety Lead', [['Police Unit', 1]]]],
    ],
    [
        'sample_id' => 'GDLPE-SITREP-005',
        'reported_at' => '2026-05-24T09:55:00+08:00',
        'location_text' => 'V. Rama Avenue near Guadalupe boundary, Barangay Guadalupe, Cebu City',
        'status' => 'Deferred',
        'priority' => 'Medium',
        'summary' => 'Drainage cover collapsed, creating a road hazard near pedestrian crossing.',
        'types' => [[
            'name' => 'Infrastructure Damage',
            'details' => [
                ['infrastructure_damage_details', 'Infrastructure Damage Details', 'infrastructureDamage', ['asset_type' => 'Drainage cover', 'damage_level' => 'moderate', 'damage' => 'Broken concrete cover with exposed opening', 'public_safety_risk' => 'high for pedestrians']],
                ['road_access', 'Road / Access Status', 'roadAccessStatus', ['status' => 'Limited', 'route_location' => 'V. Rama Avenue shoulder', 'obstruction_type' => 'One side of road shoulder affected; needs barricade', 'cleared' => false]],
            ],
            'resources' => [['Assessment Team', 1], ['Road Breaker', 1], ['Dump Truck', 1]],
        ]],
        'assignments' => [['Utilities Response Team', 'requested', 'Sample Utilities Lead', [['Utility Repair Team', 1]]]],
    ],
    [
        'sample_id' => 'GDLPE-SITREP-006',
        'reported_at' => '2026-05-24T11:12:00+08:00',
        'location_text' => 'Sitio Baksan, Barangay Guadalupe, Cebu City',
        'status' => 'Active',
        'priority' => 'Critical',
        'summary' => 'Landslide debris reached the back portion of a residential structure.',
        'types' => [
            [
                'name' => 'Landslide',
                'details' => [
                    ['infrastructure_damage', 'Infrastructure Damage', 'infrastructureDamage', ['asset_type' => 'Slope and residential access path', 'damage_level' => 'moderate', 'damage' => 'Soil and debris movement behind houses', 'public_safety_risk' => 'high']],
                    ['road_access', 'Road / Access Status', 'roadAccessStatus', ['status' => 'Blocked', 'route_location' => 'Sitio Baksan narrow access path', 'obstruction_type' => 'Mud and loose soil', 'cleared' => false]],
                ],
                'resources' => [['Backhoe', 1], ['Dump Truck', 1], ['Drone', 1]],
            ],
            [
                'name' => 'Family Displacement',
                'details' => [['affected_families', 'Affected Families', 'family', ['families' => 4, 'member_count' => 17, 'temporary_shelter_needed' => true, 'displaced' => true]]],
                'resources' => [['Social Welfare Team', 1], ['Food & Water Supplies', 4], ['Sleeping Kit', 4]],
            ],
        ],
        'assignments' => [['Search and Assessment Team', 'on_scene', 'Sample Assessment Lead', [['Drone', 1], ['Assessment Team', 1]]]],
    ],
    [
        'sample_id' => 'GDLPE-SITREP-007',
        'reported_at' => '2026-05-24T13:35:00+08:00',
        'location_text' => 'Nichols Heights area, Barangay Guadalupe, Cebu City',
        'status' => 'Active',
        'priority' => 'High',
        'summary' => 'Elderly resident reported missing after leaving home during heavy rain.',
        'types' => [['name' => 'Missing Person', 'details' => [], 'resources' => [['Search Team', 2], ['Drone', 1], ['Search Lights', 1]]]],
        'assignments' => [['Search and Assessment Team', 'en_route', 'Sample Search Lead', [['Search Team', 1], ['Drone', 1]]]],
    ],
    [
        'sample_id' => 'GDLPE-SITREP-008',
        'reported_at' => '2026-05-24T15:20:00+08:00',
        'location_text' => 'Guadalupe Elementary School access road, Barangay Guadalupe, Cebu City',
        'status' => 'Resolved',
        'priority' => 'Medium',
        'summary' => 'Minor vehicle collision near school access road; no serious injuries reported.',
        'types' => [[
            'name' => 'Road Accident',
            'details' => [
                ['vehicles_involved', 'Vehicles Involved', 'vehicleInvolved', [['vehicle_type' => 'Private car', 'damage' => 'Minor bumper damage'], ['vehicle_type' => 'Tricycle', 'damage' => 'Side panel damage']]],
                ['patient_details', 'Patient Details', 'casualtyPatient', [['name' => 'Unknown tricycle passenger', 'condition' => 'Dizziness, no visible wound', 'triage_category' => 'minor']]],
                ['road_access', 'Road / Access Status', 'roadAccessStatus', ['status' => 'Clear', 'route_location' => 'Guadalupe Elementary School access road', 'obstruction_type' => 'Vehicles moved to roadside; traffic normalized', 'cleared' => true]],
            ],
            'resources' => [['Ambulance', 1]],
        ]],
        'assignments' => [['Barangay EMS Team', 'completed', 'Sample EMS Lead', [['Ambulance', 1]]]],
    ],
    [
        'sample_id' => 'GDLPE-SITREP-009',
        'reported_at' => '2026-05-24T17:03:00+08:00',
        'location_text' => 'Punta Princesa-Guadalupe connector, Barangay Guadalupe, Cebu City',
        'status' => 'Active',
        'priority' => 'Medium',
        'summary' => 'Snatching incident reported near roadside store; suspects fled on motorcycle.',
        'types' => [[
            'name' => 'Robbery',
            'details' => [['vehicles_involved', 'Vehicles Involved', 'vehicleInvolved', [['vehicle_type' => 'Motorcycle', 'plate_number' => 'Unknown', 'description' => 'Used by two fleeing suspects']]]],
            'resources' => [['Police Unit', 2], ['Investigation Team', 1]],
        ]],
        'assignments' => [['Barangay Public Safety Team', 'accepted', 'Sample Public Safety Lead', [['Police Unit', 2], ['Investigation Team', 1]]]],
    ],
    [
        'sample_id' => 'GDLPE-SITREP-010',
        'reported_at' => '2026-05-24T18:48:00+08:00',
        'location_text' => 'Interior road near Guadalupe barangay hall, Barangay Guadalupe, Cebu City',
        'status' => 'Active',
        'priority' => 'High',
        'summary' => 'Power line sparking after contact with fallen branch; nearby residents staying clear.',
        'types' => [
            ['name' => 'Power Outage', 'details' => [], 'resources' => [['Utility Repair Team', 1]]],
            ['name' => 'Infrastructure Damage', 'details' => [['infrastructure_damage_details', 'Infrastructure Damage Details', 'infrastructureDamage', ['asset_type' => 'Electrical line', 'damage_level' => 'high', 'damage' => 'Sparking line near fallen branch', 'public_safety_risk' => 'high']]], 'resources' => [['Assessment Team', 1]]],
        ],
        'assignments' => [
            ['Utilities Response Team', 'en_route', 'Sample Utilities Lead', [['Utility Repair Team', 1]]],
            ['Barangay Public Safety Team', 'requested', 'Sample Public Safety Lead', [['Perimeter Barrier', 2]]],
        ],
    ],
    [
        'sample_id' => 'GDLPE-SITREP-011',
        'reported_at' => '2026-05-25T00:22:00+08:00',
        'location_text' => 'Sitio Kamanggahan, Barangay Guadalupe, Cebu City',
        'status' => 'Active',
        'priority' => 'Critical',
        'summary' => 'House fire in narrow interior area; adjacent homes exposed.',
        'types' => [
            ['name' => 'Building/House Fire', 'details' => [], 'resources' => [['Fire Truck', 3], ['Water Tanker', 1]]],
            ['name' => 'Shelter Damage', 'details' => [['shelter_damage', 'Shelter Damage Details', 'shelterDamage', ['damaged_structures' => 3, 'destroyed_structures' => 1, 'damage_level' => 'severe', 'habitable' => false]]], 'resources' => [['Structural Assessment Team', 1], ['Tents', 2], ['Sleeping Kit', 12], ['Family Clothing Kit', 12]]],
        ],
        'assignments' => [['Barangay Fire Response Team', 'on_scene', 'Sample Fire Lead', [['Fire Truck', 1], ['Water Tanker', 1]]]],
    ],
    [
        'sample_id' => 'GDLPE-SITREP-012',
        'reported_at' => '2026-05-25T04:36:00+08:00',
        'location_text' => 'Upper Guadalupe hillside, Barangay Guadalupe, Cebu City',
        'status' => 'Active',
        'priority' => 'High',
        'summary' => 'Residents felt ground shaking and reported cracks on a small retaining wall.',
        'types' => [
            ['name' => 'Earthquake', 'details' => [
                ['infrastructure_damage', 'Infrastructure Damage', 'infrastructureDamage', ['asset_type' => 'Retaining wall and footpath', 'damage_level' => 'moderate', 'damage' => 'New cracks observed after shaking', 'public_safety_risk' => 'moderate']],
                ['road_access', 'Road / Access Status', 'roadAccessStatus', ['status' => 'Limited', 'route_location' => 'Upper Guadalupe hillside footpath', 'obstruction_type' => 'Footpath narrowed near cracked retaining wall', 'cleared' => false]],
            ], 'resources' => [['Drone', 1], ['Satellite Phone', 1]]],
            ['name' => 'Shelter Damage', 'details' => [['shelter_damage', 'Shelter Damage Details', 'shelterDamage', ['damaged_structures' => 1, 'damage_level' => 'minor to moderate', 'habitable' => 'needs assessment']]], 'resources' => [['Structural Assessment Team', 1]]],
        ],
        'assignments' => [['Search and Assessment Team', 'accepted', 'Sample Assessment Lead', [['Drone', 1], ['Assessment Team', 1]]]],
    ],
    [
        'sample_id' => 'GDLPE-SITREP-013',
        'reported_at' => '2026-05-25T06:05:00+08:00',
        'location_text' => 'Banawa-Guadalupe road section, Barangay Guadalupe, Cebu City',
        'status' => 'Active',
        'priority' => 'Medium',
        'summary' => 'Large pothole and eroded shoulder reported after heavy runoff.',
        'types' => [[
            'name' => 'Infrastructure Damage',
            'details' => [
                ['infrastructure_damage_details', 'Infrastructure Damage Details', 'infrastructureDamage', ['asset_type' => 'Road shoulder', 'damage_level' => 'moderate', 'damage' => 'Eroded shoulder and pothole', 'public_safety_risk' => 'moderate']],
                ['road_access', 'Road / Access Status', 'roadAccessStatus', ['status' => 'Limited', 'route_location' => 'Banawa-Guadalupe road section', 'obstruction_type' => 'One side requires warning marker', 'cleared' => false]],
            ],
            'resources' => [['Assessment Team', 1], ['Backhoe', 1], ['Dump Truck', 1], ['Road Breaker', 1]],
        ]],
        'assignments' => [['Search and Assessment Team', 'requested', 'Sample Assessment Lead', [['Assessment Team', 1]]]],
    ],
    [
        'sample_id' => 'GDLPE-SITREP-014',
        'reported_at' => '2026-05-25T07:44:00+08:00',
        'location_text' => 'Residential area near Guadalupe River tributary, Barangay Guadalupe, Cebu City',
        'status' => 'Active',
        'priority' => 'High',
        'summary' => 'Water intrusion damaged household items and forced families to move belongings outside.',
        'types' => [
            ['name' => 'Flood', 'details' => [['road_access', 'Road / Access Status', 'roadAccessStatus', ['status' => 'Blocked', 'route_location' => 'Interior pathway near Guadalupe River tributary', 'obstruction_type' => 'Interior pathway flooded; foot access only', 'cleared' => false]]], 'resources' => [['Rope', 1], ['Life Vest', 8]]],
            ['name' => 'Family Displacement', 'details' => [['affected_families', 'Affected Families', 'family', ['families' => 11, 'member_count' => 44, 'children_count' => 15, 'temporary_shelter_needed' => 'standby', 'displaced' => true]]], 'resources' => [['Social Welfare Team', 1], ['Food & Water Supplies', 11], ['Hygiene Kit', 11], ['Kitchen Kit', 6]]],
        ],
        'assignments' => [['Social Welfare Response Team', 'en_route', 'Sample Welfare Lead', [['Food & Water Supplies', 11], ['Hygiene Kit', 11]]]],
    ],
    [
        'sample_id' => 'GDLPE-SITREP-015',
        'reported_at' => '2026-05-25T09:10:00+08:00',
        'location_text' => 'Guadalupe jeepney stop area, Barangay Guadalupe, Cebu City',
        'status' => 'Active',
        'priority' => 'Medium',
        'summary' => 'Two-person fight near transport stop; crowd formed and temporarily blocked loading area.',
        'types' => [[
            'name' => 'Public Disturbance / Riot',
            'details' => [
                ['disturbance_type', 'Disturbance type', 'select', 'Physical fight'],
                ['weapons_seen', 'Weapons seen', 'select', 'Unknown'],
                ['immediate_danger', 'Immediate danger', 'select', 'Yes'],
                ['access_blocked', 'Road or access blocked', 'select', [['status' => 'Limited', 'route_location' => 'Guadalupe jeepney stop loading area', 'obstruction_type' => 'Crowd temporarily blocked loading area', 'cleared' => false]]],
            ],
            'resources' => [['Police Unit', 2], ['Crowd Control Team', 1], ['Perimeter Barrier', 2]],
        ]],
        'assignments' => [['Crowd Control Team', 'on_scene', 'Sample Crowd Control Lead', [['Crowd Control Team', 1], ['Perimeter Barrier', 2]]]],
    ],
    [
        'sample_id' => 'GDLPE-SITREP-016',
        'reported_at' => '2026-05-25T10:28:00+08:00',
        'location_text' => 'Interior neighborhood near Guadalupe Church, Barangay Guadalupe, Cebu City',
        'status' => 'Active',
        'priority' => 'High',
        'summary' => 'Domestic violence report with immediate safety concern for spouse and child.',
        'types' => [['name' => 'Domestic Violence', 'details' => [], 'resources' => [['Police Unit', 1], ['Social Welfare Team', 1]]]],
        'assignments' => [
            ['Barangay Public Safety Team', 'accepted', 'Sample Public Safety Lead', [['Police Unit', 1]]],
            ['Social Welfare Response Team', 'requested', 'Sample Welfare Lead', [['Social Welfare Team', 1]]],
        ],
    ],
    [
        'sample_id' => 'GDLPE-SITREP-017',
        'reported_at' => '2026-05-25T12:16:00+08:00',
        'location_text' => 'Barangay Guadalupe uphill road, Cebu City',
        'status' => 'Active',
        'priority' => 'Medium',
        'summary' => 'Water supply interruption reported by multiple households in uphill area.',
        'types' => [['name' => 'Water Supply Issue', 'details' => [], 'resources' => [['Utility Repair Team', 1], ['Potable Water', 10], ['Water Tanker', 1]]]],
        'assignments' => [['Utilities Response Team', 'accepted', 'Sample Utilities Lead', [['Utility Repair Team', 1], ['Potable Water', 10], ['Water Tanker', 1]]]],
    ],
    [
        'sample_id' => 'GDLPE-SITREP-018',
        'reported_at' => '2026-05-25T14:40:00+08:00',
        'location_text' => 'Guadalupe residential compound near Banawa access, Barangay Guadalupe, Cebu City',
        'status' => 'Active',
        'priority' => 'High',
        'summary' => 'Aggressive dog attack injured one pedestrian; animal still loose in compound.',
        'types' => [[
            'name' => 'Animal Attack',
            'details' => [['patient_details', 'Patient Details', 'casualtyPatient', [['name' => 'Unknown female pedestrian', 'condition' => 'Dog bite wound on lower leg', 'triage_category' => 'urgent']]]],
            'resources' => [['Ambulance', 1], ['Animal Control Team', 1]],
        ]],
        'assignments' => [
            ['Barangay EMS Team', 'en_route', 'Sample EMS Lead', [['Ambulance', 1], ['Medical Supplies', 1]]],
            ['Barangay Public Safety Team', 'requested', 'Sample Public Safety Lead', [['Police Unit', 1]]],
        ],
    ],
    [
        'sample_id' => 'GDLPE-SITREP-019',
        'reported_at' => '2026-05-25T16:02:00+08:00',
        'location_text' => 'Guadalupe mini-grocery area, Barangay Guadalupe, Cebu City',
        'status' => 'Discarded',
        'priority' => 'Low',
        'summary' => 'Reported suspicious package was checked and confirmed to be unattended household items.',
        'types' => [['name' => 'Bomb Threat', 'details' => [], 'resources' => [['Police Unit', 1], ['Perimeter Barrier', 1], ['Investigation Team', 1]]]],
        'assignments' => [['Barangay Public Safety Team', 'cancelled', 'Sample Public Safety Lead', [['Police Unit', 1]], 'requested', 'false_alarm']],
    ],
    [
        'sample_id' => 'GDLPE-SITREP-020',
        'reported_at' => '2026-05-25T19:26:00+08:00',
        'location_text' => 'Upper Sitio Banawa, Barangay Guadalupe, Cebu City',
        'status' => 'Active',
        'priority' => 'Critical',
        'summary' => 'Rescue request for resident trapped on second floor by rising water near creek overflow.',
        'types' => [
            ['name' => 'Rescue', 'details' => [['patient_details', 'Patient Details', 'casualtyPatient', [['name' => 'Unknown adult resident', 'condition' => 'Stranded, no injury reported', 'triage_category' => 'monitor']]]], 'resources' => [['Rescue Team', 1], ['Ambulance', 1], ['Rope', 2], ['Body Harness', 1], ['Harness Cable', 1]]],
            ['name' => 'Flood', 'details' => [['road_access', 'Road / Access Status', 'roadAccessStatus', ['status' => 'Blocked', 'route_location' => 'Upper Sitio Banawa lower access path', 'obstruction_type' => 'Creek overflow blocked lower access path; approach from upper road required', 'cleared' => false]]], 'resources' => [['Life Vest', 6], ['LifeBuoy Ring', 2]]],
        ],
        'assignments' => [
            ['Barangay Rescue Team', 'en_route', 'Sample Rescue Lead', [['Rescue Team', 1], ['Rope', 2], ['Body Harness', 1], ['Harness Cable', 1]]],
            ['Barangay EMS Team', 'requested', 'Sample EMS Lead', [['Ambulance', 1]]],
        ],
    ],
    [
        'sample_id' => 'GDLPE-SITREP-021',
        'reported_at' => '2026-05-24T10:42:00+08:00',
        'resolved_at' => '2026-05-24T11:28:00+08:00',
        'location_text' => 'Guadalupe public market loading area, Barangay Guadalupe, Cebu City',
        'status' => 'Resolved',
        'priority' => 'Medium',
        'summary' => 'Pedestrian slipped on wet pavement near market loading area; minor injury treated on site.',
        'types' => [[
            'name' => 'Medical Emergency',
            'details' => [['patient_details', 'Patient Details', 'casualtyPatient', [['name' => 'Unknown female adult', 'age' => 'Approx. 45', 'condition' => 'Minor ankle pain after slipping', 'triage_category' => 'minor']]]],
            'resources' => [['Ambulance', 1], ['Medical Response Team', 1], ['Medical Supplies', 1]],
        ]],
        'assignments' => [['Barangay EMS Team', 'completed', 'Sample EMS Lead', [['Medical Response Team', 1], ['Medical Supplies', 1]]]],
    ],
    [
        'sample_id' => 'GDLPE-SITREP-022',
        'reported_at' => '2026-05-24T16:15:00+08:00',
        'resolved_at' => '2026-05-24T17:06:00+08:00',
        'location_text' => 'Interior road near Guadalupe barangay hall, Barangay Guadalupe, Cebu City',
        'status' => 'Resolved',
        'priority' => 'Medium',
        'summary' => 'Low-hanging electrical service wire reported near pedestrian path; area secured and utility team corrected hazard.',
        'types' => [
            ['name' => 'Power Outage', 'details' => [], 'resources' => [['Utility Repair Team', 1]]],
            [
                'name' => 'Infrastructure Damage',
                'details' => [
                    ['infrastructure_damage_details', 'Infrastructure Damage Details', 'infrastructureDamage', ['asset_type' => 'Electrical service wire', 'damage' => 'Low-hanging line near pedestrian path', 'damage_level' => 'moderate', 'public_safety_risk' => 'moderate']],
                    ['road_access', 'Road / Access Status', 'roadAccessStatus', ['status' => 'Clear', 'route_location' => 'Interior road near Guadalupe barangay hall', 'obstruction_type' => 'Pedestrian path reopened after utility correction.', 'cleared' => true]],
                ],
                'resources' => [['Assessment Team', 1]],
            ],
        ],
        'assignments' => [
            ['Utilities Response Team', 'completed', 'Sample Utilities Lead', [['Utility Repair Team', 1]]],
            ['Barangay Public Safety Team', 'completed', 'Sample Public Safety Lead', [['Perimeter Barrier', 1]]],
        ],
    ],
    [
        'sample_id' => 'GDLPE-SITREP-023',
        'reported_at' => '2026-05-25T08:22:00+08:00',
        'resolved_at' => '2026-05-25T09:40:00+08:00',
        'location_text' => 'Sitio Banawa access road, Barangay Guadalupe, Cebu City',
        'status' => 'Resolved',
        'priority' => 'Medium',
        'summary' => 'Small motorcycle crash on uphill access road; rider assessed and released to family.',
        'types' => [[
            'name' => 'Road Accident',
            'details' => [
                ['vehicles_involved', 'Vehicles Involved', 'vehicleInvolved', [['vehicle_type' => 'Motorcycle', 'plate_number' => 'Unknown', 'damage' => 'Minor side damage']]],
                ['patient_details', 'Patient Details', 'casualtyPatient', [['name' => 'Unknown male rider', 'age' => 'Approx. 34', 'condition' => 'Minor abrasions, conscious and stable', 'triage_category' => 'minor']]],
                ['road_access', 'Road / Access Status', 'roadAccessStatus', ['status' => 'Clear', 'route_location' => 'Sitio Banawa access road', 'obstruction_type' => 'Motorcycle moved aside; road fully passable.', 'cleared' => true]],
            ],
            'resources' => [['Ambulance', 1]],
        ]],
        'assignments' => [['Barangay EMS Team', 'completed', 'Sample EMS Lead', [['Ambulance', 1], ['Medical Supplies', 1]]]],
    ],
    [
        'sample_id' => 'GDLPE-SITREP-024',
        'reported_at' => '2026-05-25T13:08:00+08:00',
        'resolved_at' => '2026-05-25T14:12:00+08:00',
        'location_text' => 'Guadalupe Church vicinity, Barangay Guadalupe, Cebu City',
        'status' => 'Resolved',
        'priority' => 'Low',
        'summary' => 'Brief verbal altercation after parking dispute; parties separated and area normalized.',
        'types' => [[
            'name' => 'Public Disturbance / Riot',
            'details' => [
                ['disturbance_type', 'Disturbance type', 'select', 'Verbal conflict'],
                ['weapons_seen', 'Weapons seen', 'select', 'No'],
                ['immediate_danger', 'Immediate danger', 'select', 'No'],
                ['access_blocked', 'Road or access blocked', 'select', 'No'],
            ],
            'resources' => [['Police Unit', 1], ['Crowd Control Team', 1], ['Perimeter Barrier', 1]],
        ]],
        'assignments' => [['Barangay Public Safety Team', 'completed', 'Sample Public Safety Lead', [['Police Unit', 1]]]],
    ],
    [
        'sample_id' => 'GDLPE-SITREP-025',
        'reported_at' => '2026-05-25T17:35:00+08:00',
        'resolved_at' => '2026-05-25T18:50:00+08:00',
        'location_text' => 'Upper Guadalupe residential cluster, Barangay Guadalupe, Cebu City',
        'status' => 'Resolved',
        'priority' => 'Medium',
        'summary' => 'Temporary water supply interruption reported by households; potable water delivered while service pressure recovered.',
        'types' => [[
            'name' => 'Water Supply Issue',
            'details' => [],
            'resources' => [['Utility Repair Team', 1], ['Potable Water', 10], ['Water Tanker', 1]],
        ]],
        'assignments' => [['Utilities Response Team', 'completed', 'Sample Utilities Lead', [['Utility Repair Team', 1], ['Potable Water', 10], ['Water Tanker', 1]]]],
    ],
    [
        'sample_id' => 'GDLPE-SITREP-026',
        'reported_at' => '2026-05-24T20:14:00+08:00',
        'resolved_at' => '2026-05-24T22:05:00+08:00',
        'location_text' => 'Sitio Banawa riverside homes, Barangay Guadalupe, Cebu City',
        'status' => 'Resolved',
        'priority' => 'High',
        'summary' => 'Short-duration creek overflow affected multiple households; families temporarily moved to higher ground and returned after water receded.',
        'types' => [
            [
                'name' => 'Flood',
                'details' => [['road_access', 'Road / Access Status', 'roadAccessStatus', ['status' => 'Clear', 'route_location' => 'Sitio Banawa riverside homes', 'obstruction_type' => 'Interior path was briefly flooded but reopened after water receded.', 'cleared' => true]]],
                'resources' => [['Rope', 1], ['Life Vest', 6], ['LifeBuoy Ring', 2]],
            ],
            [
                'name' => 'Family Displacement',
                'details' => [['affected_families', 'Affected Families', 'family', ['families' => 6, 'individuals' => 27, 'male' => 13, 'female' => 14, 'children' => 9, 'adults' => 14, 'senior_citizens' => 4, 'pregnant' => 1, 'persons_with_disability' => 1, 'temporary_shelter_needed' => false, 'returned_home' => true, 'notes' => 'Families moved belongings to higher ground; no overnight shelter required.']]],
                'resources' => [['Social Welfare Team', 1], ['Food & Water Supplies', 6], ['Hygiene Kit', 6]],
            ],
        ],
        'assignments' => [
            ['Barangay Rescue Team', 'completed', 'Sample Rescue Lead', [['Life Vest', 6], ['LifeBuoy Ring', 2]]],
            ['Social Welfare Response Team', 'completed', 'Sample Welfare Lead', [['Food & Water Supplies', 6], ['Hygiene Kit', 6]]],
        ],
    ],
    [
        'sample_id' => 'GDLPE-SITREP-027',
        'reported_at' => '2026-05-25T05:58:00+08:00',
        'resolved_at' => '2026-05-25T09:15:00+08:00',
        'location_text' => 'Upper Sitio Kalubihan, Barangay Guadalupe, Cebu City',
        'status' => 'Resolved',
        'priority' => 'High',
        'summary' => 'Minor slope movement prompted precautionary relocation of families from three houses until assessment cleared re-entry.',
        'types' => [
            [
                'name' => 'Landslide',
                'details' => [
                    ['infrastructure_damage', 'Infrastructure Damage', 'infrastructureDamage', ['asset_type' => 'Backyard slope and footpath', 'damage' => 'Minor soil movement behind houses', 'damage_level' => 'minor', 'public_safety_risk' => 'moderate until inspected']],
                    ['road_access', 'Road / Access Status', 'roadAccessStatus', ['status' => 'Clear', 'route_location' => 'Upper Sitio Kalubihan', 'obstruction_type' => 'Footpath reopened after assessment; no road blockage.', 'cleared' => true]],
                ],
                'resources' => [['Backhoe', 1], ['Dump Truck', 1], ['Drone', 1]],
            ],
            [
                'name' => 'Family Displacement',
                'details' => [['affected_families', 'Affected Families', 'family', ['families' => 3, 'individuals' => 16, 'male' => 7, 'female' => 9, 'children' => 6, 'adults' => 8, 'senior_citizens' => 2, 'pregnant' => 0, 'persons_with_disability' => 0, 'temporary_shelter_needed' => true, 'returned_home' => true, 'notes' => 'Families stayed at nearby relatives while assessment was ongoing.']]],
                'resources' => [['Social Welfare Team', 1], ['Food & Water Supplies', 3], ['Sleeping Kit', 3], ['Hygiene Kit', 3]],
            ],
        ],
        'assignments' => [
            ['Search and Assessment Team', 'completed', 'Sample Assessment Lead', [['Drone', 1], ['Assessment Team', 1]]],
            ['Social Welfare Response Team', 'completed', 'Sample Welfare Lead', [['Food & Water Supplies', 3], ['Sleeping Kit', 3], ['Hygiene Kit', 3]]],
        ],
    ],
    [
        'sample_id' => 'GDLPE-SITREP-028',
        'reported_at' => '2026-05-25T15:22:00+08:00',
        'resolved_at' => '2026-05-25T18:05:00+08:00',
        'location_text' => 'Residential row near Guadalupe River tributary, Barangay Guadalupe, Cebu City',
        'status' => 'Resolved',
        'priority' => 'Medium',
        'summary' => 'Roof leak and wall seepage affected several households after heavy rain; families received non-food items and remained in place.',
        'types' => [
            [
                'name' => 'Shelter Damage',
                'details' => [['shelter_damage', 'Shelter Damage Details', 'shelterDamage', ['damaged_structures' => 4, 'destroyed_structures' => 0, 'damage_severity' => 'minor_to_moderate', 'habitable' => true, 'notes' => 'Roof leaks and wall seepage reported; households stayed in homes after temporary repairs.']]],
                'resources' => [['Structural Assessment Team', 1], ['Sleeping Kit', 4], ['Hygiene Kit', 4], ['Family Clothing Kit', 4]],
            ],
            [
                'name' => 'Family Displacement',
                'details' => [['affected_families', 'Affected Families', 'family', ['families' => 4, 'individuals' => 19, 'male' => 10, 'female' => 9, 'children' => 7, 'adults' => 10, 'senior_citizens' => 2, 'pregnant' => 0, 'persons_with_disability' => 1, 'temporary_shelter_needed' => false, 'returned_home' => true, 'notes' => 'No evacuation required; non-food items issued to affected households.']]],
                'resources' => [['Social Welfare Team', 1], ['Food & Water Supplies', 4], ['Hygiene Kit', 4], ['Family Clothing Kit', 4]],
            ],
        ],
        'assignments' => [
            ['Search and Assessment Team', 'completed', 'Sample Assessment Lead', [['Structural Assessment Team', 1]]],
            ['Social Welfare Response Team', 'completed', 'Sample Welfare Lead', [['Hygiene Kit', 4], ['Family Clothing Kit', 4]]],
        ],
    ],
];

$teamCategories = [
    'Barangay Rescue Team' => 'Rescue and Medical',
    'Social Welfare Response Team' => 'Relief and Welfare',
    'Barangay EMS Team' => 'Medical Support',
    'Search and Assessment Team' => 'Rescue and Medical',
    'Barangay Public Safety Team' => 'Public Safety',
    'Utilities Response Team' => 'Infrastructure',
    'Crowd Control Team' => 'Public Safety',
    'Barangay Fire Response Team' => 'Infrastructure Support',
];

$operator = User::firstOrCreate(
    ['email' => 'guadalupe.sitrep.operator@pbb.local'],
    [
        'name' => 'Guadalupe SITREP Sample Operator',
        'password' => Hash::make('password'),
        'role' => 'operator',
        'status' => 'active',
    ]
);
$citizen = User::firstOrCreate(
    ['email' => 'guadalupe.sitrep.citizen@pbb.local'],
    [
        'name' => 'Guadalupe SITREP Sample Citizen',
        'password' => Hash::make('password'),
        'role' => 'citizen',
        'status' => 'active',
    ]
);

$priorityToAlert = fn (string $priority): string => match ($priority) {
    'Critical' => AlertLevel::Critical->value,
    'High' => AlertLevel::Elevated->value,
    default => AlertLevel::Normal->value,
};

$normalizeValue = static function (string $fieldKey, mixed $value): string {
    if (is_array($value)) {
        $isList = array_is_list($value);
        $rows = $isList ? $value : [$value];

        if (str_contains($fieldKey, 'road') || str_contains($fieldKey, 'access')) {
            $rows = array_map(static function (array $row): array {
                return [
                    'status' => $row['status'] ?? '',
                    'route_location' => $row['route_location'] ?? $row['location'] ?? '',
                    'obstruction_type' => $row['obstruction_type'] ?? $row['description'] ?? '',
                    'cleared' => $row['cleared'] ?? false,
                ];
            }, $rows);
        }

        return json_encode($rows, JSON_UNESCAPED_SLASHES);
    }

    return (string) $value;
};

$touchesForStatus = static function (string $status, Carbon $reportedAt): array {
    $assignedAt = $reportedAt->copy()->addMinutes(5);

    return match ($status) {
        'accepted' => ['assigned_at' => $assignedAt, 'accepted_at' => $assignedAt->copy()->addMinutes(5)],
        'en_route' => ['assigned_at' => $assignedAt, 'accepted_at' => $assignedAt->copy()->addMinutes(5), 'enroute_at' => $assignedAt->copy()->addMinutes(10)],
        'on_scene' => ['assigned_at' => $assignedAt, 'accepted_at' => $assignedAt->copy()->addMinutes(5), 'enroute_at' => $assignedAt->copy()->addMinutes(10), 'arrived_at' => $assignedAt->copy()->addMinutes(20)],
        'completed' => ['assigned_at' => $assignedAt, 'accepted_at' => $assignedAt->copy()->addMinutes(5), 'enroute_at' => $assignedAt->copy()->addMinutes(10), 'arrived_at' => $assignedAt->copy()->addMinutes(20), 'completed_at' => $assignedAt->copy()->addMinutes(45)],
        'cancelled' => ['assigned_at' => $assignedAt, 'cancelled_at' => $assignedAt->copy()->addMinutes(15)],
        default => ['assigned_at' => $assignedAt],
    };
};

DB::transaction(function () use ($samples, $teamCategories, $operator, $citizen, $priorityToAlert, $normalizeValue, $touchesForStatus): void {
    $sampleIds = array_column($samples, 'sample_id');
    $incidentIds = Incident::query()
        ->where(function ($query) use ($sampleIds): void {
            foreach ($sampleIds as $sampleId) {
                $query->orWhere('other_details', 'like', '%'.$sampleId.'%');
            }
        })
        ->pluck('id')
        ->all();

    if ($incidentIds !== []) {
        $assignmentIds = TeamAssignment::whereIn('incident_id', $incidentIds)->pluck('id')->all();
        TeamAssignmentAllocatedResource::whereIn('team_assignment_id', $assignmentIds)->delete();
        TeamAssignment::whereIn('incident_id', $incidentIds)->delete();
        IncidentResourceNeeded::whereIn('incident_id', $incidentIds)->delete();
        IncidentTypeDetail::whereIn('incident_id', $incidentIds)->delete();
        CallSession::whereIn('incident_id', $incidentIds)->delete();
        DB::table('incident_incident_type')->whereIn('incident_id', $incidentIds)->delete();
        Incident::whereIn('id', $incidentIds)->delete();
    }

    $category = IncidentCategory::firstOrCreate(
        ['name' => 'Sample SITREP'],
        ['description' => 'Sample incidents for SITREP generator review.', 'sort_order' => 999]
    );

    foreach ($teamCategories as $teamName => $categoryName) {
        $teamCategory = TeamCategory::firstOrCreate(['name' => $categoryName]);
        Team::firstOrCreate(
            ['name' => $teamName],
            ['team_category_id' => $teamCategory->id, 'status' => 'active']
        );
    }

    foreach ($samples as $index => $sample) {
        $reportedAt = Carbon::parse($sample['reported_at']);
        $resolvedAt = isset($sample['resolved_at'])
            ? Carbon::parse($sample['resolved_at'])
            : ($sample['status'] === 'Resolved' ? $reportedAt->copy()->addMinutes(45) : null);
        $incident = Incident::create([
            'citizen_id' => $citizen->id,
            'actual_citizen_name' => 'Sample Guadalupe Caller',
            'actual_citizen_relationship' => 'Reporter',
            'operator_id' => $operator->id,
            'status' => IncidentStatus::from($sample['status'])->value,
            'alert_level' => $priorityToAlert($sample['priority']),
            'latitude' => 10.3157 + ($index * 0.0001),
            'longitude' => 123.8854 + ($index * 0.0001),
            'citizen_location_accuracy' => 35,
            'citizen_location_captured_at' => $reportedAt,
            'location' => $sample['location_text'],
            'location_barangay' => 'Barangay Guadalupe',
            'location_citymunicipality' => 'Cebu City',
            'location_country' => 'Philippines',
            'other_details' => $sample['sample_id'].': '.$sample['summary'],
            'called_at' => $reportedAt,
            'resolved_at' => $resolvedAt,
            'created_at' => $reportedAt,
            'updated_at' => $resolvedAt ?? $reportedAt,
        ]);
        DB::table('incidents')->where('id', $incident->id)->update([
            'created_at' => $reportedAt,
            'resolved_at' => $resolvedAt,
            'updated_at' => $resolvedAt ?? $reportedAt,
        ]);

        CallSession::create([
            'incident_id' => $incident->id,
            'citizen_id' => $citizen->id,
            'status' => 'ended',
            'outcome' => 'ended_by_operator',
            'started_at' => $reportedAt,
            'answered_at' => $reportedAt->copy()->addMinute(),
            'ended_at' => $reportedAt->copy()->addMinutes(6),
            'created_at' => $reportedAt,
            'updated_at' => $reportedAt->copy()->addMinutes(6),
        ]);

        foreach ($sample['types'] as $typeIndex => $typePayload) {
            $incidentType = IncidentType::firstOrCreate(
                ['name' => $typePayload['name']],
                ['incident_category_id' => $category->id, 'description' => 'Sample SITREP incident type.']
            );
            $incident->incidentTypes()->syncWithoutDetaching([$incidentType->id]);

            foreach ($typePayload['details'] as $detailIndex => [$fieldKey, $fieldLabel, $inputType, $value]) {
                $field = IncidentTypeField::firstOrCreate(
                    ['incident_type_id' => $incidentType->id, 'field_key' => $fieldKey],
                    [
                        'field_label' => $fieldLabel,
                        'input_type' => $inputType,
                        'options_json' => null,
                        'config_json' => null,
                        'is_required' => false,
                        'sort_order' => $detailIndex + 1,
                    ]
                );

                IncidentTypeDetail::create([
                    'incident_id' => $incident->id,
                    'incident_type_id' => $incidentType->id,
                    'field_id' => $field->id,
                    'field_label' => $fieldLabel,
                    'field_key' => $fieldKey,
                    'field_value' => $normalizeValue($fieldKey, $value),
                    'input_type' => $inputType,
                    'options_json' => null,
                    'config_json' => null,
                    'unit' => null,
                    'placeholder' => null,
                    'is_required' => false,
                    'sort_order' => (($typeIndex + 1) * 100) + $detailIndex,
                    'created_at' => $reportedAt,
                    'updated_at' => $reportedAt,
                ]);
            }

            foreach ($typePayload['resources'] as [$resourceName, $quantity]) {
                $resource = ResourceType::where('name', $resourceName)->firstOrFail();
                IncidentResourceNeeded::create([
                    'incident_id' => $incident->id,
                    'incident_type_id' => $incidentType->id,
                    'resource_type_id' => $resource->id,
                    'quantity_required' => $quantity,
                    'notes' => 'Seeded from '.$sample['sample_id'],
                    'created_at' => $reportedAt,
                    'updated_at' => $reportedAt,
                ]);
            }
        }

        foreach ($sample['assignments'] as $assignmentPayload) {
            [$teamName, $assignmentStatus, $contactPerson, $allocatedResources] = $assignmentPayload;
            $team = Team::where('name', $teamName)->firstOrFail();
            $assignmentTimes = $touchesForStatus($assignmentStatus, $reportedAt);

            $assignment = TeamAssignment::create([
                'incident_id' => $incident->id,
                'team_id' => $team->id,
                'assigned_by_operator_id' => $operator->id,
                'status' => $assignmentStatus,
                'contact_person' => $contactPerson,
                'cancelled_from_status' => $assignmentPayload[4] ?? null,
                'cancel_reason_code' => $assignmentPayload[5] ?? null,
                'cancelled_by_operator_id' => $assignmentStatus === 'cancelled' ? $operator->id : null,
                ...$assignmentTimes,
                'created_at' => $assignmentTimes['assigned_at'],
                'updated_at' => $assignmentTimes['completed_at'] ?? $assignmentTimes['cancelled_at'] ?? $assignmentTimes['arrived_at'] ?? $assignmentTimes['enroute_at'] ?? $assignmentTimes['accepted_at'] ?? $assignmentTimes['assigned_at'],
            ]);

            foreach ($allocatedResources as [$resourceName, $quantity]) {
                $resource = ResourceType::where('name', $resourceName)->firstOrFail();
                TeamAssignmentAllocatedResource::create([
                    'team_assignment_id' => $assignment->id,
                    'resource_type_id' => $resource->id,
                    'quantity_allocated' => $quantity,
                    'created_at' => $assignment->created_at,
                    'updated_at' => $assignment->updated_at,
                ]);
            }
        }
    }
});

$sampleIds = array_column($samples, 'sample_id');
$incidentIds = Incident::query()
    ->where(function ($query) use ($sampleIds): void {
        foreach ($sampleIds as $sampleId) {
            $query->orWhere('other_details', 'like', '%'.$sampleId.'%');
        }
    })
    ->pluck('id')
    ->all();

echo json_encode([
    'sample_incidents_seeded' => count($incidentIds),
    'incident_id_min' => min($incidentIds),
    'incident_id_max' => max($incidentIds),
    'active' => Incident::whereIn('id', $incidentIds)->where('status', 'Active')->count(),
    'deferred' => Incident::whereIn('id', $incidentIds)->where('status', 'Deferred')->count(),
    'resolved' => Incident::whereIn('id', $incidentIds)->where('status', 'Resolved')->count(),
    'discarded' => Incident::whereIn('id', $incidentIds)->where('status', 'Discarded')->count(),
    'resource_units' => IncidentResourceNeeded::whereIn('incident_id', $incidentIds)->sum('quantity_required'),
], JSON_PRETTY_PRINT);
