<?php

namespace app\api\controller;

use app\api\service\UserToken;
use app\common\service\SystemAreaService;
use app\common\service\xcx_aes\ErrorCode;
use app\common\service\xcx_aes\WXBizDataCrypt;
use swoole_server;

/**
 * Class Token
 * @package api\controller
 */
class Token
{
    /**
     * 用户获取令牌（登陆）
     * @url /token
     * @POST code
     * @note 虽然查询应该使用get，但为了稍微增强安全性，所以使用POST
     */
    public function login()
    {
        $code = request()->param('code');
        $rawData = request()->param('rawData');
        $iv = request()->param('iv');
        $wxCrypt = new WXBizDataCrypt();
        $result = $wxCrypt->decryptData($rawData, $iv, $rawData);
        if ($result != ErrorCode::$OK) {
            return exception('用户信息不正确');
        }
        $wx = new UserToken($code, $rawData);
        $token = $wx->get();
        return $token;
    }

    public function test()
    {
//echo '2342';exit;

        (new SystemAreaService())->test();
    }

    /**
     * description:服务端
     */
    public function syncSend()
    {
        $serv = new \swoole_server('0.0.0.0', 8082);

        $serv->set(array('task_worker_num' => 4));

        $serv->on('receive', function ($serv, $fd, $from_id, $data) {
            $task_id = $serv->task($data);
            echo "开始投递异步任务 id=$task_id\n";
        });

        $serv->on('task', function ($serv, $task_id, $from_id, $data) {
            echo "接收异步任务[id=$task_id]" . PHP_EOL;
            (new SystemAreaService())->test();
            $serv->finish('');
        });

        $serv->on('finish', function ($serv, $task_id, $data) {
            echo "异步任务[id=$task_id]完成" . PHP_EOL;
        });

        $serv->start();
    }
}
