<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\PlanService;

class PlanController extends Controller
{
    private const PERIODS = [
        'month_price',
        'quarter_price',
        'half_year_price',
        'year_price',
        'two_year_price',
        'three_year_price',
        'onetime_price',
    ];

    private const PUBLIC_FIELDS = [
        'id',
        'name',
        'content',
        'transfer_enable',
        'speed_limit',
        'device_limit',
        'month_price',
        'quarter_price',
        'half_year_price',
        'year_price',
        'two_year_price',
        'three_year_price',
        'onetime_price',
    ];

    public function fetch()
    {
        $plans = Plan::query()
            ->select(array_merge(self::PUBLIC_FIELDS, ['capacity_limit']))
            ->where('show', 1)
            ->where(function ($query) {
                foreach (self::PERIODS as $period) {
                    // NULL disables a period; zero is a valid free price.
                    $query->orWhere($period, '>=', 0);
                }
            })
            ->orderBy('sort', 'ASC')
            ->orderBy('id', 'ASC')
            ->get();

        $counts = $plans->contains(function ($plan) {
            return $plan->capacity_limit !== null;
        }) ? PlanService::countActiveUsers() : [];

        $data = $plans->map(function ($plan) use ($counts) {
            // Capacity and active-user counts are only used to derive this flag.
            $public = $plan->only(self::PUBLIC_FIELDS);
            $activeUsers = (int) ($counts[$plan->id]->count ?? 0);
            $public['available'] = $plan->capacity_limit === null
                || (int) $plan->capacity_limit > $activeUsers;
            return $public;
        })->values();

        return response([
            'data' => $data,
            'currency' => config('v2board.currency', 'CNY'),
            'currency_symbol' => config('v2board.currency_symbol', '¥'),
        ]);
    }
}
