<?php

namespace App\Http\Controllers\V1\Passport;

use App\Http\Controllers\Controller;
use App\Http\Requests\Passport\CommSendEmailVerify;
use App\Models\InviteCode;
use App\Modules\Security\EmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommController extends Controller
{
    public function __construct(private readonly EmailVerificationService $verification)
    {
    }

    public function sendEmailVerify(CommSendEmailVerify $request): JsonResponse
    {
        $input = $request->validated();
        $this->verification->send(
            $input['email'],
            isset($input['isforget']) ? (bool) $input['isforget'] : null,
            (string) $request->ip(),
            $input['recaptcha_data'] ?? null,
        );

        return response()->json(['data' => true]);
    }

    public function pv(Request $request)
    {
        $inviteCode = InviteCode::where('code', $request->input('invite_code'))->first();
        if ($inviteCode) {
            $inviteCode->pv = $inviteCode->pv + 1;
            $inviteCode->save();
        }

        return response([
            'data' => true
        ]);
    }

}
