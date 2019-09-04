<?php

namespace app\api\model;


use think\Model;
use traits\model\SoftDelete;

class BaseModel extends Model
{
    /*protected function  prefixImgUrl($value, $data){
        $finalUrl = $value;
        if($data['from'] == 1){
            $finalUrl = config('wx.img_prefix').$value;
        }
        return $finalUrl;
    }*/
}