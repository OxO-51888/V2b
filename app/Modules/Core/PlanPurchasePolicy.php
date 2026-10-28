<?php

namespace App\Modules\Core;

use App\Models\User;

/** Shared by order creation and the read-only client renewal options. */
class PlanPurchasePolicy
{
    public const PERIODS = ['month_price', 'quarter_price', 'half_year_price', 'year_price',
        'two_year_price', 'three_year_price', 'onetime_price', 'reset_price'];

    private $users;

    public function __construct(?UserService $users = null)
    {
        $this->users = $users ?? new UserService();
    }

    public function assertPurchasable(User $user, PlanService $plans, string $period): void
    {
        $reason = $this->denialReason($user, $plans, $period);
        if ($reason !== null) abort(500, __($reason));
    }

    /** Preserve the existing OrderController decision order and business rules. */
    public function denialReason(User $user, PlanService $plans, string $period): ?string
    {
        $plan = $plans->plan;
        if (!$plan) return 'Subscription plan does not exist';
        if (!in_array($period, self::PERIODS, true)) {
            return 'This payment period cannot be purchased, please choose another period';
        }
        if ($user->plan_id !== $plan->id && !$plans->haveCapacity() && $period !== 'reset_price') {
            return 'Current product is sold out';
        }
        // The gateway previously rejected invalid/negative prices. Keep that
        // protection in the authoritative rule now that the gateway delegates it.
        $price = $plan[$period];
        if (!is_numeric($price) || !is_finite((float) $price) || (float) $price < 0) {
            return 'This payment period cannot be purchased, please choose another period';
        }
        if ($period === 'reset_price' && (!$this->users->isAvailable($user) || $plan->id !== $user->plan_id)) {
            return 'Subscription has expired or no active subscription, unable to purchase Data Reset Package';
        }
        if (((!$plan->show && !$plan->renew) || (!$plan->show && $user->plan_id !== $plan->id)) && $period !== 'reset_price') {
            return 'This subscription has been sold out, please choose another subscription';
        }
        if (!$plan->renew && $user->plan_id == $plan->id && $period !== 'reset_price') {
            return 'This subscription cannot be renewed, please change to another subscription';
        }
        if (!$plan->show && $plan->renew && !$this->users->isAvailable($user)) {
            return 'This subscription has expired, please change to another subscription';
        }
        return null;
    }

    public function currentOptions(User $user): array
    {
        $empty = ['plan' => null, 'periods' => [], 'canRenew' => false, 'canReset' => false, 'reason' => '当前没有可续费的套餐'];
        if ((int) $user->plan_id <= 0) return $empty;
        $plans = new PlanService((int) $user->plan_id);
        if (!$plans->plan) return $empty;
        $result = $empty;
        $result['plan'] = $plans->plan;
        if ($this->users->isNotCompleteOrderByUserId((int) $user->id)) {
            $result['reason'] = '请先处理未完成的订单';
            return $result;
        }
        foreach (self::PERIODS as $period) {
            if ($this->denialReason($user, $plans, $period) === null) $result['periods'][] = $period;
        }
        $result['canReset'] = in_array('reset_price', $result['periods'], true);
        $result['canRenew'] = count(array_diff($result['periods'], ['reset_price'])) > 0;
        $result['reason'] = $result['periods'] ? '' : '当前套餐暂不可续费或重置流量';
        return $result;
    }
}
