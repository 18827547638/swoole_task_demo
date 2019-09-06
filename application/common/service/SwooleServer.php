<?php
/**
 * Created by PhpStorm.
 * User: gang.zhu
 * Date: 2019/9/6 0006
 * Time: 09:56
 */

namespace app\common\service;


class SwooleServer
{
    private $serv;

    public function __construct()
    {
        $this->serv = new swoole_server("0.0.0.0", 9501);
        $this->serv->set(array(
            'worker_num' => 1, //一般设置为服务器CPU数的1-4倍
            'daemonize' => 0, //以守护进程执行
            'max_request' => 50,
            'dispatch_mode' => 2,
            'task_worker_num' => 8, //task进程的数量
            "task_ipc_mode " => 3, //使用消息队列通信，并设置为争抢模式
            //"log_file" => "log/taskqueueu.log" ,//日志
        ));
        $this->serv->on('Receive', array($this, 'onReceive'));
        // bind callback
        $this->serv->on('Task', array($this, 'onTask'));
        $this->serv->on('Finish', array($this, 'onFinish'));
        $this->serv->start();
    }

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
}