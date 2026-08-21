<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Giftcard extends Model
{
    public const REDEEM_LIMIT_UNLIMITED = 0;
    public const REDEEM_LIMIT_MONTHLY = 1;
    public const REDEEM_LIMIT_ONCE = 2;
    public const REDEEM_PERIOD_ONCE = 'once';

    protected $table = 'v2_giftcard';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'used_user_ids' => 'array',
        'redeem_limit' => 'integer'
    ];
}
