<?php

namespace App\Console\Commands;

use App\Services\AuthService;
use App\Utils\Helper;
use Illuminate\Console\Command;
use App\Models\User;

class ResetPassword extends Command
{
    protected $builder;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reset:password {email}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '重置用户密码';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $user = User::where('email', $this->argument('email'))->first();
        if (!$user) abort(500, '邮箱不存在');
        $password = Helper::guid(false);
        (new AuthService($user))->resetPassword($password);
        $this->info("!!!重置成功!!!");
        $this->info("新密码为：{$password}，请尽快修改密码。");
    }
}
