<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GiftcardRedemption extends Model
{
    protected $table = 'v2_giftcard_redemption';
    protected $guarded = ['id'];
    public $timestamps = false;
}
