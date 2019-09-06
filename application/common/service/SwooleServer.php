<?php
/**
 * Created by PhpStorm.
 * User: gang.zhu
 * Date: 2019/9/6 0006
 * Time: 09:56
 */

namespace app\common\service;


use think\swoole\Server;

class SwooleServer extends Server
{
    private $serv;
    protected $host = '127.0.0.1';
    protected $port = 9502;
    protected $serverType = 'socket';
    protected $mode = SWOOLE_PROCESS;
    protected $sockType = SWOOLE_SOCK_TCP;
    protected $option = [
        'worker_num'=> 4,
        'daemonize'	=> true,
        'backlog'	=> 128
    ];

    public function onReceive(swoole_server $serv, $fd, $from_id, $data)
    {
        echo "Get Message From Client {$fd}:{$data}\n";
        // send a task to task worker.
        $serv->task($data);
    }

    public function onTask($serv, $task_id, $from_id, $data)
    {
        $array = json_decode($data, true);
        return time();
//        if ($array['url']) {
//            return $this->httpGet($array['url'], $array['param']);
//        }
    }

    public function onFinish($serv, $task_id, $data)
    {
        echo "Task {$task_id} finish\n";
        echo "Result: {$data}\n";
    }

    public function onMessage($serv, $frame)
    {
        echo "onMessage\n";
    }
}