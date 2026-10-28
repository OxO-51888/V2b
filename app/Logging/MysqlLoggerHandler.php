<?php
namespace App\Logging;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use App\Models\Log as LogModel;

class MysqlLoggerHandler extends AbstractProcessingHandler
{
    public function __construct($level = Logger::DEBUG, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        try{
            $request = request();
            $safe = new SafeLogData();
            $body = $request->isJson() ? $request->json()->all() : $request->request->all();
            $query = $request->query->all();
            $safe->rememberSecrets($request->headers->all());
            $safe->rememberSecrets($body);
            $safe->rememberSecrets($query);
            $safe->rememberSecrets($record['context'] ?? []);
            $log = [
                'title' => $safe->message($record['message']),
                'level' => $record['level_name'],
                'host' => $request->getSchemeAndHttpHost(),
                'uri' => $safe->uri($request),
                'method' => $request->getMethod(),
                'ip' => $request->getClientIp(),
                'data' => $safe->json(['body' => $safe->fields($body), 'query' => $safe->fields($query)]),
                'context' => $safe->json($safe->fields($record['context'] ?? [])),
                'created_at' => $record['datetime'] instanceof \DateTimeInterface ? $record['datetime']->getTimestamp() : strtotime($record['datetime']),
                'updated_at' => $record['datetime'] instanceof \DateTimeInterface ? $record['datetime']->getTimestamp() : strtotime($record['datetime']),
            ];

            LogModel::insert(
                $log
            );
        }catch (\Exception $e){
            Log::channel('daily')->error('Database log write failed', ['class' => get_class($e), 'code' => $e->getCode()]);
        }
    }
}
