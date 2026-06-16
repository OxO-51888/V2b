<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SubscriptionRuleSave extends FormRequest
{
    public function rules()
    {
        $typeRule = 'in:pull_frequency,ip_spread,ip_multi_user,node_alive_ip_over_limit,direct_ip_host,head_method_probe,ua_scanner,ua_blacklist,ua_social,ua_browser,ua_cli_fetch,ua_api_fetch,ua_converter,ua_vendor,empty_user_agent,converter_query,header_browser_context,flag_ua_mismatch,untrusted_proxy_header';

        return [
            'name' => 'nullable|max:255',
            'type' => $this->input('id') ? 'nullable|' . $typeRule : 'required|' . $typeRule,
            'condition_value' => 'nullable|integer|min:0',
            'action' => 'required|in:audit,record,notify_admin,rate_limit,empty_subscription,reset_subscribe,block,no_nodes,ai_review',
            'enabled' => 'nullable|boolean',
            'sort' => 'nullable|integer|min:0',
            'remark' => 'nullable|max:1024'
        ];
    }

    public function messages()
    {
        return [
            'name.required' => 'Rule name is required',
            'type.required' => 'Rule type is required',
            'type.in' => 'Rule type is invalid',
            'condition_value.integer' => 'Condition value must be a number',
            'action.required' => 'Rule action is required',
            'action.in' => 'Rule action is invalid'
        ];
    }
}
