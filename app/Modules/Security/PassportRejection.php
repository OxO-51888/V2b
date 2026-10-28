<?php

namespace App\Modules\Security;

use Symfony\Component\HttpKernel\Exception\HttpException;

enum PassportRejection
{
    case RegisterRateLimited;
    case InvalidCaptcha;
    case EmailNotAllowed;
    case EmailAliasNotAllowed;
    case RegistrationClosed;
    case InviteRequired;
    case EmailAlreadyExists;
    case InvalidInvite;
    case LoginRateLimited;
    case InvalidCredentials;
    case AccountSuspended;
    case InvalidEmailCode;
    case EmailNotRegistered;
    case InvalidEmailFormat;
    case EmailRegistered;
    case ResetRateLimited;
    case EmailRecentlySent;
    case RequestRateLimited;

    public function reject(array $replace = []): never
    {
        [$status, $message] = match ($this) {
            self::RegisterRateLimited => [429, 'Register frequently, please try again after :minute minute'],
            self::InvalidCaptcha => [422, 'Invalid code is incorrect'],
            self::EmailNotAllowed => [422, 'Email suffix is not in the Whitelist'],
            self::EmailAliasNotAllowed => [422, 'Gmail alias is not supported'],
            self::RegistrationClosed => [422, 'Registration has closed'],
            self::InviteRequired => [422, 'You must use the invitation code to register'],
            self::EmailAlreadyExists => [409, 'Email already exists'],
            self::InvalidInvite => [422, 'Invalid invitation code'],
            self::LoginRateLimited => [429, 'There are too many password errors, please try again after :minute minutes.'],
            self::InvalidCredentials => [422, 'Incorrect email or password'],
            self::AccountSuspended => [422, 'Your account has been suspended'],
            self::InvalidEmailCode => [422, 'Incorrect email verification code'],
            self::EmailNotRegistered => [422, 'This email is not registered in the system'],
            self::InvalidEmailFormat => [422, 'Email format is incorrect'],
            self::EmailRegistered => [409, 'This email is registered'],
            self::ResetRateLimited => [429, 'Reset failed, Please try again later'],
            self::EmailRecentlySent => [429, 'Email verification code has been sent, please request again later'],
            self::RequestRateLimited => [429, 'Too many requests, please try again later.'],
        };

        throw new HttpException($status, __($message, $replace));
    }
}
