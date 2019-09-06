<?php
/**
 * Created by PhpStorm.
 * User: gang.zhu
 * Date: 2019/9/6 0006
 * Time: 17:40
 */

namespace app\common\service;


class SwooleMysql
{
    private $param;
    public $db;
    public function __construct() {
        $this->db = new \swoole_mysql();
        $this->param = array(
            'host' => '127.0.0.1',
            'user' => 'root',
            'password' => '8ik,9ol.',
            'database' => 'hssc',
        );
    }

    public function exec($sql) {
        echo 'exec';
        $this->db->connect($this->param, function ($db, $result) use ($sql) {
            if ($result === false) {
                echo "连接数据库失败 ： 错误代码：" . $db->connect_errno . PHP_EOL . $db->connect_error;
                return false;
            }
            echo '连接数据库success';
            $db->query($sql, function ($db, $res) {
                if ($res === false) {
                    // error属性获得错误信息，errno属性获得错误码
                    echo "sql语句执行错误 : " . $db->error;
                } else if ($res === true) {
                    // 非查询语句  affected_rows属性获得影响的行数，insert_id属性获得Insert操作的自增ID
                    echo "sql语句执行成功，影响行数 : " . $db->affected_rows;

                } else {
//                    //查询语句  $result为结果数组
//                    echo  json_encode($res);
                    echo 'query success';
                        var_dump($res);
                }
                $db->close();
            });
        });
    }
}