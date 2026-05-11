<?php

namespace App\Domain\Shared\Concerns;

use Illuminate\Database\Eloquent\Model;

trait SynchronizesCitizenIdentity
{
    protected static function bootSynchronizesCitizenIdentity(): void
    {
        static::saving(function (Model $model): void {
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
}
