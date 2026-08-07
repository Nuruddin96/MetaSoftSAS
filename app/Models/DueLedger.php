<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class DueLedger extends Model
{
    use BelongsToTenant;

    protected $table = 'due_ledger';
    protected $guarded = [];
}
