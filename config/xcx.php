<?php
return [
    'meishi_appid' => 'wx52038676bc6a879a',
    'meishi_secret' => '4923787effe23b98ea62c926555ee52f',
    'meishi_mch_id' => '1247430201',		//商户号
    'meishi_mch_secret' => '50839dcaf6fc19762241f334cea9fbbf',
    'meishi_notify_url' => 'http://api.lmsggdc.com/meishi/index/notify',
    'wx_place_an_order_url' => 'https://api.mch.weixin.qq.com/pay/unifiedorder',		//统一下单接口
    'wx_order_query_url' => 'https://api.mch.weixin.qq.com/pay/orderquery',				//订单查询接口
    'xcx_get_openid_by_code_url' => 'https://api.weixin.qq.com/sns/jscode2session',//GET https://api.weixin.qq.com/sns/jscode2session?appid=APPID&secret=SECRET&js_code=JSCODE&grant_type=authorization_code
];