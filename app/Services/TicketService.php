<?php
namespace App\Services;


use App\Jobs\SendEmailJob;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class TicketService {
    public function reply($ticket, $message, $userId)
    {
        DB::beginTransaction();
        $ticketMessage = TicketMessage::create([
            'user_id' => $userId,
            'ticket_id' => $ticket->id,
            'message' => $message
        ]);
        if ($userId !== $ticket->user_id) {
            $ticket->reply_status = 1;
        } else {
            $ticket->reply_status = 0;
        }
        if (!$ticketMessage || !$ticket->save()) {
            DB::rollback();
            return false;
        }
        DB::commit();
        return $ticketMessage;
    }

    public function replyByAdmin($ticketId, $message, $userId):void
    {
        $ticket = Ticket::where('id', $ticketId)
            ->first();
        if (!$ticket) {
            abort(500, '工单不存在');
        }
        
        DB::beginTransaction();
        $ticketMessage = TicketMessage::create([
            'user_id' => $userId,
            'ticket_id' => $ticket->id,
            'message' => $message
        ]);
        $ticket->status = 0;
        if ($userId !== $ticket->user_id) {
            $ticket->reply_status = 1;
        } else {
            $ticket->reply_status = 0;
        }
        $ticket->touch();
        if (!$ticketMessage || !$ticket->save()) {
            DB::rollback();
            abort(500, '工单回复失败');
        }
        DB::commit();
        $this->sendEmailNotify($ticket, $ticketMessage);
    }

    public function autoReplyByAi(Ticket $ticket, $question = '', $source = 'user_ticket')
    {
        if (!(int)config('v2board.ticket_ai_auto_reply_enable', 0)) {
            return false;
        }
        if ((int)$ticket->status !== 0) {
            return false;
        }

        $lastUserMessage = TicketMessage::where('ticket_id', $ticket->id)
            ->where('user_id', $ticket->user_id)
            ->orderBy('id', 'DESC')
            ->first();
        if (!$lastUserMessage) {
            return false;
        }

        $cacheKey = 'TICKET_AI_AUTO_REPLY_' . $ticket->id . '_' . $lastUserMessage->id;
        if (!Cache::add($cacheKey, 1, 600)) {
            return false;
        }

        $adminId = User::where('is_admin', 1)->orderBy('id')->value('id');
        if (!$adminId) {
            Log::warning('ticket AI auto reply skipped: admin user not found', [
                'ticket_id' => $ticket->id
            ]);
            return false;
        }

        $lastUserMessageId = $lastUserMessage->id;
        $context = $this->buildAiTicketContext($ticket, $question ?: $lastUserMessage->message, $source);

        $aiRiskService = new AiRiskService();
        $skipReason = $aiRiskService->ticketAutoReplySkipReason($context);
        if ($skipReason) {
            Log::info('ticket AI auto reply skipped by policy', [
                'ticket_id' => $ticket->id,
                'reason' => $skipReason
            ]);
            return false;
        }

        try {
            $draft = $aiRiskService->generateTicketReplyDraft($context, (array)config('v2board', []));
            $blockReason = $aiRiskService->ticketAutoPublishBlockReason($draft, $context);
            if ($blockReason) {
                Log::warning('ticket AI auto reply blocked by publish gate', [
                    'ticket_id' => $ticket->id,
                    'reason' => $blockReason
                ]);
                return false;
            }
            $this->createAiReplyMessage($ticket, $lastUserMessageId, $adminId, $draft);
            return true;
        } catch (Throwable $exception) {
            Log::warning('ticket AI auto reply failed', [
                'ticket_id' => $ticket->id,
                'message' => $exception->getMessage()
            ]);
            return false;
        }
    }

    private function createAiReplyMessage(Ticket $ticket, $lastUserMessageId, $adminId, $message): void
    {
        DB::beginTransaction();
        $freshTicket = Ticket::where('id', $ticket->id)->first();
        if (!$freshTicket) {
            DB::rollback();
            return;
        }

        $hasNewerUserMessage = TicketMessage::where('ticket_id', $freshTicket->id)
            ->where('user_id', $freshTicket->user_id)
            ->where('id', '>', $lastUserMessageId)
            ->exists();
        if ($hasNewerUserMessage) {
            DB::rollback();
            return;
        }

        $ticketMessage = TicketMessage::create([
            'user_id' => $adminId,
            'ticket_id' => $freshTicket->id,
            'message' => $message
        ]);
        $freshTicket->status = 0;
        $freshTicket->reply_status = 1;
        $freshTicket->touch();

        if (!$ticketMessage || !$freshTicket->save()) {
            DB::rollback();
            return;
        }
        DB::commit();
        $this->sendEmailNotify($freshTicket, $ticketMessage);
    }

    private function buildAiTicketContext(Ticket $ticket, $question, $source)
    {
        $user = User::where('id', $ticket->user_id)->first();
        $messages = TicketMessage::where('ticket_id', $ticket->id)
            ->orderBy('id', 'DESC')
            ->limit(8)
            ->get()
            ->reverse()
            ->map(function ($message) use ($ticket) {
                return [
                    'from' => (int)$message->user_id === (int)$ticket->user_id ? 'user' : 'staff',
                    'message' => mb_substr((string)$message->message, 0, 800),
                    'created_at' => $message->created_at
                ];
            })
            ->values()
            ->all();

        return [
            'question' => mb_substr((string)$question, 0, 1200),
            'source' => $source,
            'ticket' => [
                'id' => $ticket->id,
                'subject' => $ticket->subject,
                'level' => $ticket->level,
                'status' => $ticket->status,
                'user_email' => $user ? $user->email : '',
                'messages' => $messages
            ]
        ];
    }

    // 半小时内不再重复通知
    private function sendEmailNotify(Ticket $ticket, TicketMessage $ticketMessage)
    {
        $user = User::find($ticket->user_id);
        $cacheKey = 'ticket_sendEmailNotify_' . $ticket->user_id;
        if (!Cache::get($cacheKey)) {
            Cache::put($cacheKey, 1, 1800);
            SendEmailJob::dispatch([
                'email' => $user->email,
                'subject' => '您在' . config('v2board.app_name', 'V2Board') . '的工单得到了回复',
                'template_name' => 'notify',
                'template_value' => [
                    'name' => config('v2board.app_name', 'V2Board'),
                    'url' => config('v2board.app_url'),
                    'content' => "主题：{$ticket->subject}\r\n回复内容：{$ticketMessage->message}"
                ]
            ]);
        }
    }
}
