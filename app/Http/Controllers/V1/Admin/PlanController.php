<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PlanSave;
use App\Http\Requests\Admin\PlanSort;
use App\Http\Requests\Admin\PlanUpdate;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlanController extends Controller
{
    public function fetch(Request $request)
    {
        $counts = PlanService::countActiveUsers();
        $plans = Plan::orderBy('sort', 'ASC')->get();
        foreach ($plans as $k => $v) {
            $plans[$k]->count = 0;
            foreach ($counts as $kk => $vv) {
                if ($plans[$k]->id === $counts[$kk]->plan_id) $plans[$k]->count = $counts[$kk]->count;
            }
        }
        return response([
            'data' => $plans
        ]);
    }

    public function save(PlanSave $request)
    {
        $params = $request->validated();
        if ($request->input('id')) {
            $plan = Plan::find($request->input('id'));
            if (!$plan) {
                abort(500, '该订阅不存在');
            }
            DB::beginTransaction();
            try {
                if ($request->input('force_update')) {
                    User::where('plan_id', $plan->id)->update([
                        'group_id' => $params['group_id'],
                        'device_limit' => $params['device_limit'],
                        'speed_limit' => $params['speed_limit']
                    ]);
                    $savedPlan = array_replace($plan->getAttributes(), $params);
                    // Preserve accumulated quota, including when changing the plan's prices.
                    if (!$this->isOneTimePlan($plan->getAttributes()) && !$this->isOneTimePlan($savedPlan)) {
                        User::where('plan_id', $plan->id)
                            ->whereNotNull('expired_at')
                            ->update(['transfer_enable' => $params['transfer_enable'] * 1073741824]);
                    }
                }
                $plan->update($params);
            } catch (\Exception $e) {
                DB::rollBack();
                abort(500, '保存失败');
            }
            DB::commit();
            return response([
                'data' => true
            ]);
        }
        if (!Plan::create($params)) {
            abort(500, '创建失败');
        }
        return response([
            'data' => true
        ]);
    }

    private function isOneTimePlan(array $plan): bool
    {
        if (!isset($plan['onetime_price'])) return false;
        foreach (['month_price', 'quarter_price', 'half_year_price', 'year_price', 'two_year_price', 'three_year_price'] as $period) {
            if (isset($plan[$period])) return false;
        }
        return true;
    }

    public function drop(Request $request)
    {
        if (Order::where('plan_id', $request->input('id'))->first()) {
            abort(500, '该订阅下存在订单无法删除');
        }
        if (User::where('plan_id', $request->input('id'))->first()) {
            abort(500, '该订阅下存在用户无法删除');
        }
        if ($request->input('id')) {
            $plan = Plan::find($request->input('id'));
            if (!$plan) {
                abort(500, '该订阅ID不存在');
            }
        }
        return response([
            'data' => $plan->delete()
        ]);
    }

    public function update(PlanUpdate $request)
    {
        $updateData = $request->only([
            'show',
            'renew'
        ]);

        $plan = Plan::find($request->input('id'));
        if (!$plan) {
            abort(500, '该订阅不存在');
        }

        try {
            $plan->update($updateData);
        } catch (\Exception $e) {
            abort(500, '保存失败');
        }

        return response([
            'data' => true
        ]);
    }

    public function sort(PlanSort $request)
    {
        DB::beginTransaction();
        foreach ($request->input('plan_ids') as $k => $v) {
            if (!Plan::find($v)->update(['sort' => $k + 1])) {
                DB::rollBack();
                abort(500, '保存失败');
            }
        }
        DB::commit();
        return response([
            'data' => true
        ]);
    }
}
