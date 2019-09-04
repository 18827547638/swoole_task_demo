<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2019/4/12 0012
 * Time: 18:29
 */

namespace app\common\service;


use Qiniu\Auth;
use Qiniu\Storage\BucketManager;
use Qiniu\Storage\UploadManager;
use think\facade\Log;

class QiniuService
{
    private $accessKey;
    private $secretKey;
    private $bucket;
    private $expires = 600;

    private $police = [
        'insertOnly' => 1,
        'fsizeLimit' => 2048000,
        'mimeLimit' => 'image/*'
    ];

    public function __construct()
    {
        $this->accessKey = config('QINIU_ACCESS_KEY');
        $this->secretKey = config('QINIU_SECRET_KEY');
        $this->bucket = config('QINIU_BUCKET');
    }

    /**
     * 生成上传token
     * @return string
     */
    public function uploadAthor($expires = ''){
        try{
            $auth = new Auth($this->accessKey, $this->secretKey);
            $upToken = $auth->uploadToken($this->bucket, null, empty($expires) ? $this->expires : $expires, $this->police, true);
            return $upToken;
        } catch (\Exception $e){
            Log::error($e);
        }
            return '';
    }

    /**
     * 服务器直传
     * @return string
     */
    public function putFile($key, $filePath){
        try{
            $token = $this->uploadAthor(20);
            $uploadMgr = new UploadManager();
            list($ret, $err) = $uploadMgr->putFile($token, $key, $filePath);
            if ($err !== null) {
                Log::error($err);
            } else {
                return [
                    'fileName' => ltrim($key,  DIRECTORY_SEPARATOR),
                    'fileUrl'  => config('QINIU_DOMIN') . $ret['key']
                ];
            }
        } catch (\Exception $e){
            Log::error($e);
        }
        return false;
    }

    /**
     * 删除指定key
     * @param $key
     * @return bool
     */
    public function delete_by_key($key){
        try{
            $bucket = new BucketManager(new Auth($this->accessKey, $this->secretKey));
            $result = $bucket->delete($this->bucket, $key);
            if(empty($result)){
                return true;
            }
            Log::error($result);
        } catch (\Exception $e) {
            Log::error($e);
        }
    }
}