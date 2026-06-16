<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionRule extends Model
{
    protected $table = 'v2_subscription_rule';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'enabled' => 'integer',
        'condition_value' => 'integer',
        'sort' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];
}
