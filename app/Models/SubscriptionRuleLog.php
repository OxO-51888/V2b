<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionRuleLog extends Model
{
    protected $table = 'v2_subscription_rule_log';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'rule_id' => 'integer',
        'user_id' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];

    public function rule()
    {
        return $this->belongsTo(SubscriptionRule::class, 'rule_id', 'id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
