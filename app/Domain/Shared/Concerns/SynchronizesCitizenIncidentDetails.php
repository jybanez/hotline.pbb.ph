<?php

namespace App\Domain\Shared\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

trait SynchronizesCitizenIncidentDetails
{
    /**
     * @var array<string, bool>
     */
    private static array $citizenIncidentDetailColumnCache = [];

    protected static function bootSynchronizesCitizenIncidentDetails(): void
    {
        static::saving(function (Model $model): void {
            $attributes = $model->getAttributes();
            $table = $model->getTable();

            foreach (self::citizenIncidentDetailPairs() as $citizenColumn => $callerColumn) {
                if (! self::citizenIncidentDetailColumnExists($table, $citizenColumn)) {
                    unset($model->{$citizenColumn});

                    continue;
                }

                $citizenValue = $attributes[$citizenColumn] ?? null;
                $callerValue = $attributes[$callerColumn] ?? null;
                $hasCallerColumn = Schema::hasColumn($table, $callerColumn);

                if ($citizenValue === null && $callerValue !== null) {
                    $model->setAttribute($citizenColumn, $callerValue);

                    continue;
                }

                if ($hasCallerColumn && $callerValue === null && $citizenValue !== null) {
                    $model->setAttribute($callerColumn, $citizenValue);
                }

                if (! $hasCallerColumn) {
                    unset($model->{$callerColumn});
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

    private static function citizenIncidentDetailColumnExists(string $table, string $column): bool
    {
        $key = "{$table}.{$column}";

        return self::$citizenIncidentDetailColumnCache[$key] ??= Schema::hasColumn($table, $column);
    }
}
