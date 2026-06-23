<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\AiRiskService;
use App\Services\TicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class TicketController extends Controller
{
    public function fetch(Request $request)
    {
        if ($request->input('id')) {
            $ticket = Ticket::where('id', $request->input('id'))
                ->first();
            if (!$ticket) {
                abort(500, '工单不存在');
            }
            $ticket['message'] = TicketMessage::where('ticket_id', $ticket->id)->get();
            for ($i = 0; $i < count($ticket['message']); $i++) {
                if ($ticket['message'][$i]['user_id'] !== $ticket->user_id) {
                    $ticket['message'][$i]['is_me'] = true;
                } else {
                    $ticket['message'][$i]['is_me'] = false;
                }
            }
            return response([
                'data' => $ticket
            ]);
        }
        $current = $request->input('current') ? $request->input('current') : 1;
        $pageSize = $request->input('pageSize') >= 10 ? $request->input('pageSize') : 10;
        $model = Ticket::orderBy('updated_at', 'DESC');
        if ($request->input('status') !== NULL) {
            $model->where('status', $request->input('status'));
        }
        if ($request->input('reply_status') !== NULL) {
            $model->whereIn('reply_status', $request->input('reply_status'));
        }
        if ($request->input('email') !== NULL) {
            $user = User::where('email', $request->input('email'))->first();
            if ($user) $model->where('user_id', $user->id);
        }
        $total = $model->count();
        $res = $model->forPage($current, $pageSize)
            ->get();
        return response([
            'data' => $res,
            'total' => $total
        ]);
    }

    public function reply(Request $request)
    {
        if (empty($request->input('id'))) {
            abort(500, '参数错误');
        }
        if (empty($request->input('message'))) {
            abort(500, '消息不能为空');
        }
        $ticketService = new TicketService();
        $ticketService->replyByAdmin(
            $request->input('id'),
            $request->input('message'),
            $request->user['id']
        );
        return response([
            'data' => true
        ]);
    }

    public function aiDraft(Request $request)
    {
        $question = trim((string)$request->input('question', ''));
        $ticketId = (int)$request->input('ticket_id', 0);
        $sendReply = (int)$request->input('send', 0) === 1;
        if ($question === '' && !$ticketId) {
            abort(500, '请先输入用户问题，或填写工单 ID');
        }
        if ($sendReply && !$ticketId) {
            abort(500, '模型回复工单需要填写工单 ID');
        }

        $config = (array)config('v2board', []);
        if (array_key_exists('ticket_ai_enable', $config) && empty($config['ticket_ai_enable'])) {
            abort(500, '自建工单模型还没有启用');
        }

        $ticketContext = [
            'question' => mb_substr($question, 0, 1200),
            'operator' => [
                'id' => $request->user['id'] ?? null
            ]
        ];

        $ticket = null;
        if ($ticketId) {
            $ticket = Ticket::where('id', $ticketId)->first();
            if (!$ticket) {
                abort(500, '工单不存在');
            }
            if ($sendReply && (int)$ticket->status !== 0) {
                abort(500, '工单已关闭，不能自动回复');
            }

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

            $ticketContext['ticket'] = [
                'id' => $ticket->id,
                'subject' => $ticket->subject,
                'level' => $ticket->level,
                'status' => $ticket->status,
                'user_email' => $user ? $user->email : '',
                'messages' => $messages
            ];
        }

        try {
            $draft = (new AiRiskService())->generateTicketReplyDraft($ticketContext, $config);
        } catch (Throwable $e) {
            abort(500, 'AI 生成失败：' . $e->getMessage());
        }

        if ($sendReply && $ticket) {
            $ticketService = new TicketService();
            $ticketService->replyByAdmin(
                $ticket->id,
                $draft,
                $request->user['id']
            );
        }

        return response([
            'data' => [
                'draft' => $draft,
                'replied' => $sendReply,
                'ticket_id' => $ticket ? $ticket->id : null,
                'generated_at' => time()
            ]
        ]);
    }

    public function close(Request $request)
    {
        if (empty($request->input('id'))) {
            abort(500, '参数错误');
        }
        $ticket = Ticket::where('id', $request->input('id'))
            ->first();
        if (!$ticket) {
            abort(500, '工单不存在');
        }
        $ticket->status = 1;
        if (!$ticket->save()) {
            abort(500, '关闭失败');
        }
        return response([
            'data' => true
        ]);
    }
}
