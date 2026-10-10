<?php

namespace App\Services\ClientGateway;

class GatewayException extends \RuntimeException
{
    public $status;
    public $errorCode;

    public function __construct(int $status, string $code, string $message)
    {
        parent::__construct($message);
        $this->status = $status;
        $this->errorCode = $code;
    }
}
