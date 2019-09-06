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
    protected $host = '0.0.0.0';
    protected $port = 9508;
    protected $serverType = 'websocket';
    protected $mode = SWOOLE_PROCESS;
    protected $socketType = SWOOLE_SOCK_TCP;
    protected $option = [
        'worker_num'=> 4,
//        'daemonize'	=> true,
        'backlog'	=> 128,
        'task_worker_num' => 4
    ];

    public function onReceive($serv, $fd, $from_id, $data)
    {
        $task_id = $serv->task("Async");
        echo "开始投递异步任务 id=$task_id\n";
    }

    public function onTask($serv, $task_id, $from_id, $data)
    {
        /*echo "接收异步任务[id=$task_id]".PHP_EOL;
        for ($i = 0 ; $i<10000;$i++){
            if($i%2==0){
                echo 'send'.$i.' success'."\n";
            }else{
                echo 'send'.$i.' fail'."\n";
            }
            sleep(1);
        }*/
        echo "接收异步任务[id=$task_id]".PHP_EOL;
        echo "参数".$data.PHP_EOL;

        $serv->finish("$data -> OK");
//        $array = json_decode($data, true);
//        db('system')->insertGetId(['type'=>'swoole','key'=>'测试','value'=>time()]);
//        return time();
//        if ($array['url']) {
//            return $this->httpGet($array['url'], $array['param']);
//        }
    }

    public function onFinish($serv, $task_id, $data)
    {
        echo "异步任务[id=$task_id]完成".PHP_EOL;
    }

    public function onMessage($serv, $frame)
    {
        echo "onMessage\n";
    }
    public function onStart($serv)
    {
        echo "start\n";
    }
    public function onClose($ser, $fd) {
        echo "client {$fd} closed\n";
    }
}