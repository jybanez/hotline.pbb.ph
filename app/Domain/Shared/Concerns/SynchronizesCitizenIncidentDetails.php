<?php

namespace App\Domain\Shared\Concerns;

use Illuminate\Database\Eloquent\Model;

trait SynchronizesCitizenIncidentDetails
{
    protected static function bootSynchronizesCitizenIncidentDetails(): void
    {
        static::saving(function (Model $model): void {
            $attributes = $model->getAttributes();

            foreach (self::citizenIncidentDetailPairs() as $citizenColumn => $callerColumn) {
                $citizenValue = $attributes[$citizenColumn] ?? null;
                $callerValue = $attributes[$callerColumn] ?? null;

                if ($citizenValue === null && $callerValue !== null) {
                    $model->setAttribute($citizenColumn, $callerValue);

                    continue;
                }

                if ($callerValue === null && $citizenValue !== null) {
                    $model->setAttribute($callerColumn, $citizenValue);
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    private static function citizenIncidentDetailPairs(): array
    {
        return [
            'actual_citizen_name' => 'actual_caller_name',
            'actual_citizen_relationship' => 'actual_caller_relationship',
            'citizen_location_accuracy' => 'caller_location_accuracy',
            'citizen_altitude' => 'caller_altitude',
            'citizen_altitude_accuracy' => 'caller_altitude_accuracy',
            'citizen_heading' => 'caller_heading',
            'citizen_heading_source' => 'caller_heading_source',
            'citizen_location_captured_at' => 'caller_location_captured_at',
        ];
    }
}
