<?php
/**
 * Created by PhpStorm.
 * User: gang.zhu
 * Date: 2019/9/6 0006
 * Time: 09:35
 */

namespace app\common\service;


use think\swoole\template\Task;

class TestTask extends Task
{
    public function run($serv, $task_id, $fromWorkerId)
    {
        // TODO: Implement run() method.
    }

    public function initialize($args)
    {
        // TODO: Implement initialize() method.
    }
}