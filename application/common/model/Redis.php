<?php

namespace app\common\model;

use think\Cache;

class Redis extends Cache
{
    private $key = '';

    public function setKey($name)
    {
        $this->key = $this->config['redis']['prefix'] . $name;
        return $this;
    }

    public function hSet($hashKey, $value)
    {
        return self::store('redis')->handler()->hSet($this->key, $hashKey, $value);
    }

    public function hMSet($hashKeys)
    {
        return self::store('redis')->handler()->hMset($this->key, $hashKeys);
    }

    public function hGet($hashKey)
    {
        return self::store('redis')->handler()->hGet($this->key, $hashKey);
    }

    public function hMGet($hashKeys)
    {
        return self::store('redis')->handler()->hMGet($this->key, $hashKeys);
    }

    public function kGet()
    {
        return self::store('redis')->handler()->get($this->key);
    }
}