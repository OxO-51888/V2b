<?php

namespace Tests\Unit;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\TicketService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Config\Repository as Config;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

class TicketAiPersistenceTest extends TestCase
{
    private $database;
    private $container;

    protected function setUp(): void
    {
        $this->container = new Container();
        Container::setInstance($this->container);
        $this->container->instance('config', new Config(['v2board' => ['ticket_ai_auto_reply_enable' => 1]]));
        $this->database = new Manager($this->container);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->database->bootEloquent();
        $this->container->instance('db', $this->database->getDatabaseManager());
        $this->container->instance('cache', new Repository(new ArrayStore()));
        $this->container->instance('log', new NullLogger());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->container);
        $schema = $this->database->getConnection()->getSchemaBuilder();
        foreach (['v2_ticket', 'v2_ticket_message', 'v2_user'] as $table) {
            $schema->create($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->increments('id');
                $blueprint->integer('created_at')->nullable();
                $blueprint->integer('updated_at')->nullable();
                if ($table === 'v2_user') {
                    $blueprint->string('email');
                    $blueprint->boolean('is_admin')->default(0);
                } else {
                    $blueprint->integer('user_id');
                    if ($table === 'v2_ticket') {
                        $blueprint->string('subject')->default('test');
                        $blueprint->integer('level')->default(1);
                        $blueprint->integer('status')->default(0);
                        $blueprint->integer('reply_status')->default(0);
                    } else {
                        $blueprint->integer('ticket_id');
                        $blueprint->text('message');
                    }
                }
            });
        }
        $this->database->getConnection()->table('v2_user')->insert([
            ['id' => 1, 'email' => 'fixture@example.invalid', 'is_admin' => 0],
            ['id' => 2, 'email' => 'staff@example.invalid', 'is_admin' => 1]
        ]);
        // Suppress notifications at the in-memory cache boundary, never use mail transport.
        $this->container['cache']->put('ticket_sendEmailNotify_1', 1, 1800);
    }

    protected function tearDown(): void
    {
        $this->database->getDatabaseManager()->disconnect();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
    }

    private function fixture($message = '节点测试失败')
    {
        $ticket = Ticket::create(['user_id' => 1]);
        $userMessage = TicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => 1, 'message' => $message]);
        return [$ticket, $userMessage];
    }

    private function persist($ticket, $messageId)
    {
        $method = new ReflectionMethod(TicketService::class, 'createAiReplyMessage');
        $method->setAccessible(true);
        return $method->invoke(new TicketService(), $ticket, $messageId, 2, '亲亲，我是 AI 小助手。请核对节点测试的具体提示。');
    }

    public function test_already_answered_ticket_is_not_generated_or_replied_again(): void
    {
        [$ticket, $message] = $this->fixture();
        TicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => 2, 'message' => '人工已回复']);
        $this->assertFalse((new TicketService())->autoReplyByAi($ticket));
        $this->assertFalse($this->persist($ticket, $message->id));
        $this->assertSame(2, TicketMessage::count());
    }

    public function test_new_user_message_cancels_outdated_draft(): void
    {
        [$ticket, $message] = $this->fixture();
        TicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => 1, 'message' => '转人工']);
        $this->assertFalse($this->persist($ticket, $message->id));
        $this->assertSame(2, TicketMessage::count());
    }

    public function test_closed_or_disabled_ticket_is_not_written(): void
    {
        [$ticket, $message] = $this->fixture();
        $ticket->update(['status' => 1]);
        $this->assertFalse($this->persist($ticket, $message->id));
        $ticket->update(['status' => 0]);
        $this->container['config']->set('v2board.ticket_ai_auto_reply_enable', 0);
        $this->assertFalse($this->persist($ticket, $message->id));
        $this->assertSame(1, TicketMessage::count());
    }

    public function test_second_publication_for_same_message_is_rejected(): void
    {
        [$ticket, $message] = $this->fixture();
        $this->assertTrue($this->persist($ticket, $message->id));
        $this->assertFalse($this->persist($ticket, $message->id));
        $this->assertSame(2, TicketMessage::count());
    }

    public function test_handoff_and_withdrawal_stop_before_model_call(): void
    {
        foreach (['请转人工', '申请提现100 USDT', '订单没到账'] as $question) {
            [$ticket] = $this->fixture($question);
            $this->assertFalse((new TicketService())->autoReplyByAi($ticket));
        }
        $this->assertSame(3, TicketMessage::count());
    }

    public function test_context_retains_version_before_the_last_eight_messages(): void
    {
        [$ticket] = $this->fixture('macOS Clash Party 2.0.2');
        for ($i = 0; $i < 20; $i++) {
            TicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => $i % 2 ? 1 : 2, 'message' => '历史消息' . $i]);
        }
        $context = (new TicketService())->buildAiTicketContext($ticket, '现在不能安装', 'test');
        $this->assertSame(21, count($context['ticket']['messages']));
        $this->assertSame('macOS Clash Party 2.0.2', $context['ticket']['messages'][0]['message']);
    }

    public function test_failed_insert_rolls_back_the_publication_transaction(): void
    {
        [$ticket, $message] = $this->fixture();
        $connection = $this->database->getConnection();
        $connection->unprepared("CREATE TRIGGER fail_reply BEFORE INSERT ON v2_ticket_message BEGIN SELECT RAISE(FAIL, 'fixture failure'); END");
        try {
            $this->persist($ticket, $message->id);
            $this->fail('Expected insert failure');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertSame(0, $connection->transactionLevel());
            $this->assertSame(1, TicketMessage::count());
            $this->assertSame(0, (int)$ticket->fresh()->reply_status);
        }
    }

    public function test_empty_preview_uses_latest_user_message_not_staff_reply(): void
    {
        [$ticket] = $this->fixture('Clash Verge 2.5.2 TLS handshake timeout');
        TicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => 2, 'message' => '亲亲，我是 AI 小助手。旧回复']);
        foreach (['', null, '  '] as $question) {
            $context = (new TicketService())->buildAiTicketContext($ticket, $question, 'admin_preview');
            $this->assertSame('Clash Verge 2.5.2 TLS handshake timeout', $context['question']);
        }
    }
}
