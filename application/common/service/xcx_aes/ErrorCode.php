<?php
namespace app\common\service\xcx_aes;
/**
 * error code 说明.
 * <ul>

 *    <li>-41001: encodingAesKey 非法</li>
 *    <li>-41003: aes 解密失败</li>
 *    <li>-41004: 解密后得到的buffer非法</li>
 *    <li>-41005: base64加密失败</li>
 *    <li>-41016: base64解密失败</li>
 * </ul>
 */
class ErrorCode
{
	public static $OK = 0;
	public static $IllegalAesKey = -41001;
	public static $IllegalIv = -41002;
	public static $IllegalBuffer = -41003;
	public static $DecodeBase64Error = -41004;
	
	public static function getErrorStr($error_code)
	{
	    $arr = [
	        self::$OK => '成功',
	        self::$IllegalAesKey => 'encodingAesKey 非法',
	        self::$IllegalIv => '初始向量非法',
	        self::$IllegalBuffer => 'aes 解密失败',
	        self::$DecodeBase64Error => '解密后得到的buffer非法'
	    ];
	    return isset($arr[$error_code]) ? $arr[$error_code] : '未知的错误.';
	}
}