<?php

namespace App\Domain\Shared\Concerns;

use Illuminate\Database\Eloquent\Model;

trait SynchronizesCitizenIdentity
{
    protected static function bootSynchronizesCitizenIdentity(): void
    {
        static::saving(function (Model $model): void {
            $callerId = $model->getAttribute('caller_id');
            $citizenId = $model->getAttribute('citizen_id');

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
