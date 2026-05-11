<?php

namespace App\Domain\Shared\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

trait SynchronizesCitizenIdentity
{
    /**
     * @var array<string, bool>
     */
    private static array $citizenIdentityColumnCache = [];

    protected static function bootSynchronizesCitizenIdentity(): void
    {
        static::saving(function (Model $model): void {
            if (! self::citizenIdentityColumnExists($model->getTable())) {
                unset($model->citizen_id);

                return;
            }

            $attributes = $model->getAttributes();
            $callerId = $attributes['caller_id'] ?? null;
            $citizenId = $attributes['citizen_id'] ?? null;

            if ($citizenId === null && $callerId !== null) {
                $model->setAttribute('citizen_id', $callerId);

                return;
            }

            if ($callerId === null && $citizenId !== null) {
                $model->setAttribute('caller_id', $citizenId);
            }
        });
    }

    private static function citizenIdentityColumnExists(string $table): bool
    {
        return self::$citizenIdentityColumnCache[$table] ??= Schema::hasColumn($table, 'citizen_id');
    }
}
