<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;

trait HasMeta
{
    public function meta()
    {
        return $this->morphOne(Meta::class, 'model');
    }

    protected function metaData(): Attribute
    {
        return Attribute::make(fn () => $this->meta?->meta);
    }

    protected function getArrayableAppends()
    {
        $this->appends = ['meta_data'];

        return parent::getArrayableAppends();
    }
}
