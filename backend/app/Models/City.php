<?php

namespace App\Models;

use Database\Factories\CityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * `status` is not mass assignable: it changes only through explicit,
 * audited transitions (Sprint 5).
 */
#[Fillable(['slug', 'name', 'country_code', 'currency_code', 'timezone'])]
class City extends Model
{
    /** @use HasFactory<CityFactory> */
    use HasFactory, HasUlids;
}
