<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class FacebookConnection extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $casts = [
        'user_access_token' => 'encrypted',
        'token_expires_at' => 'datetime',
    ];

    public function pages()
    {
        return $this->hasMany(FacebookPage::class);
    }
}
