<?php
/**
 * Created by PhpStorm.
 * User: gang.zhu
 * Date: 2019/9/4 0004
 * Time: 18:56
 */

namespace app\api\controller;

use app\common\service\SwooleClient;
use think\swoole\facade\Task;
class Index
{
    public function hello()
    {
        $data = array(
            "url" => "http://192.168.10.19/send_mail",
            "param" => array(
                "username" => 'test',
                "password" => 'test'
            )
        );
        $client = new SwooleClient();
        $client->connect();
        if ($client->send($data)) {
            echo 'success';
        } else {
            echo 'fail';
        }
        $client->close();
    }
}