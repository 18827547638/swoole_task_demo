<?php
/**
 * Created by PhpStorm.
 * User: gang.zhu
 * Date: 2019/9/6 0006
 * Time: 09:57
 */

namespace app\common\service;


use Exception;

class SwooleClient
{
    private $client;

    public function __construct()
    {
        $this->client = new \swoole_client(SWOOLE_SOCK_TCP);
    }

    public function connect()
    {
        if (!$this->client->connect("127.0.0.1", 9508, 1)) {
            throw new Exception(sprintf('Swoole Error: %s', $this->client->errCode));
        }
    }

    public function send($data)
    {
        if ($this->client->isConnected()) {
            if (!is_string($data)) {
                $data = json_encode($data);
            }

            return $this->client->send($data);
        } else {
            throw new Exception('Swoole Server does not connected.');
        }
    }

    public function close()
    {
        $this->client->close();
    }
}