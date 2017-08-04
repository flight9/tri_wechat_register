<?php

class WeiChat {
    private $_appid;
    private $_appsecret;
    private $_token;		//不是access_tokens

    public function __construct($appid,$appsecret,$token) {
        $this->_appid = $appid;
        $this->_appsecret = $appsecret;
        $this->_token = $token;
    }

    /**
     * 获取AccessToken
     * @return string
     */
    public function getAccesstoken() {
        if( $access_token = $this->getCacheValue('access_token')) {
            return $access_token;
        }
        
        //如果token文件不存在或过期，则再次请求
        $url = "https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid=$this->_appid&secret=$this->_appsecret";
        $content = curl_request($url);
        $acc_obj = json_decode($content);
        $this->setCacheValue('access_token', $acc_obj->access_token, $acc_obj->expires_in);
        return $acc_obj->access_token;
    }
    
    /**
     * 获取缓存中的值
     * @param string $key
     * @return mixed 错误时返回false
     */
    private function getCacheValue($key) {
        if( defined('RUN_ON_SAE') && RUN_ON_SAE) {
            $mem = new Memcached();
            $content = $mem->get($key);
            return $content;
        } else {
            //如果token文件存在，则直接读文件
            $file = './'.$key;
            if( file_exists($file)) {
                $content = file_get_contents($file);
                $content = json_decode($content, true);
                if( time()-filemtime($file)< $content['expires_in']) {	/*filemtime文件编辑时间*/
                    return $content[$key];
                }
            }
            return false;
        }
    }
    
    /**
     * 设置缓存中的值
     * @param string $key
     * @return void
     */
    private function setCacheValue($key, $value, $expires_in) {
        if(defined('RUN_ON_SAE') && RUN_ON_SAE) {
            $mem = new Memcached();
            return $mem->set($key, $value, $expires_in);
        } else {
            $file = './'.$key;
            $js_obj = array(
                $key        => $value,
                'expires_in'=> $expires_in,
            );
            return file_put_contents($file, json_encode($js_obj));
        }
    }

    /**
     * 获取二维码Ticket
     * @param int $expire_seconds 有效期
     * @param string $type 二维码类型
     * @param int $scene_id 场景编号Id
     * @return string
     */
    private function getTicket($expire_seconds, $type, $scene_id) {
        //为临时二维码
        if($type == 'temp') {
            $data = '{"expire_seconds": '.$expire_seconds.', "action_name": "QR_SCENE", "action_info": {"scene": {"scene_id": '.$scene_id.'}}}';
        } else {
        //为永久二维码
            $data = '{"action_name": "QR_LIMIT_SCENE", "action_info": {"scene": {"scene_id": '.$scene_id.'}}}';
        }
        $url = 'https://api.weixin.qq.com/cgi-bin/qrcode/create?access_token='.$this->getAccesstoken();
        return curl_request($url, true, 'post', $data);
    }

    /**
     * 获取二维码图片
     * @param int $expire_seconds 有效期
     * @param string $type 二维码类型
     * @param int $scene_id 场景编号Id
     * @param bool $save_file 是否保存到文件
     * @return string
     */
    public function getQRCode($scene_id=1, $type='temp', $expire_seconds=604800, $save_file=false) {
        $json = json_decode($this->getTicket($expire_seconds, $type, $scene_id));
        //dbg:var_dump($json);exit;
        $ticket = $json->ticket;
        $url = 'https://mp.weixin.qq.com/cgi-bin/showqrcode?ticket='.urlencode($ticket);//urlencode为安全
        //dbg:exit($url);
        $image = curl_request($url);
        /*网页显示要加头: header('Content-Type:image/jpeg');*/
        //保存二维码图片(便于下次使用)
        if($save_file) {
            $file = './'.$type.$scene_id.'.jpg';
            file_put_contents($file, $image);
            exit('File saved!');
        } else {
            exit($image);
        }
    }
    
    /**
     * 获取用户OpenId列表
     * @param string $first_openid 起始用户Id，不填从第一个开始
     * @return object
     */
    public function getUserlist($first_openid = '') {
        $url = 'https://api.weixin.qq.com/cgi-bin/user/get?access_token='.$this->getAccesstoken().
                (!empty($first_openid)?'&next_openid='.$first_openid:'');
        $result = curl_request($url);
        return json_decode($result);
    }
    
    /**
     * 获取用户基本信息
     * @param string $open_id
     * @return object
     */
    public function getUserInfo($open_id) {
        $url = 'https://api.weixin.qq.com/cgi-bin/user/info?access_token='.$this->getAccesstoken().
                '&openid='.$open_id.'&lang=zh_CN';
        $result = curl_request($url);
        return json_decode($result);
    }
    
    /**
     * 获取网页OAuth2.0的用户信息
     * @param type $only_openid true:仅获取openid即可, false:获取详细用户信息(注意:后者调用前入口API改 scope=snsapi_userinfo, 否则报错)
     * @return mixed 错误时返回false
     */
    public function getOAuth2UserInfo($only_openid = true) {
        //必须传入微信服务器提供的code
        if(!isset($_GET['code'])) { 
            exit( '错误：参数错误！');
            #return false;
        }
        //获取openid和网页access_token
        $code = $_GET['code'];
        $access_token = '';
        $openid = '';
        $url = 'https://api.weixin.qq.com/sns/oauth2/access_token?appid='.$this->_appid.'&secret='.$this->_appsecret.
                '&code='.$code.'&grant_type=authorization_code';
        $result = curl_request($url);
        $r_obj = json_decode($result);
        if(property_exists($r_obj, 'errcode')) {
            echo '错误：'.$r_obj->errmsg;
            return false;
        } else {
            $access_token = $r_obj->access_token;
            $openid = $r_obj->openid;
        }
		if( $only_openid) {
            return $openid;
		}
        //获取用户详细信息
        else {
            $url = 'https://api.weixin.qq.com/sns/userinfo?access_token='.$access_token.'&openid='.$openid.'&lang=zh_CN';
            $result = curl_request($url);
            $r_obj = json_decode($result);
            if(property_exists($r_obj, 'errcode')) {
                echo '错误：'.$r_obj->errmsg;
                return false;
            } else {
                return $r_obj;
            }
        }
    }
    
    /**
     * 获取OAuth2所需入口转跳url（人工单独调用）
     * @param type $redir_url 我们目标地址
     * @param type $scope 取值snsapi_base | snsapi_userinfo
     * @param type $state 转跳后所传参数
     */
    public function getOAuth2EnvelopUrl($redir_url, $scope='snsapi_base', $state=0) {
        $url = 'https://open.weixin.qq.com/connect/oauth2/authorize?appid='.APPID.
            '&redirect_uri='.  urlencode($redir_url).
            '&response_type=code&scope='.$scope.'&state='.$state.'#wechat_redirect';
        return $url;   
    }
    
    /**
     * 获取网页jsapi的配置数组
     * @return array
     */
    public function getJswxConfig() {
        $nonce_str = $this->createNonceStr();
        $timestamp = time();
        
        //动态获得url
        $url = $protocol = 
                (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) 
                ? "https://" : "http://";
        $url = "$protocol$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        
        //获得signatuere
        $jsapi_ticket = $this->getJsApiTicket();
        $string = "jsapi_ticket=$jsapi_ticket&noncestr=$nonce_str&timestamp=$timestamp&url=$url";
        $signature = sha1($string);
        
        //返回config
        $config = array(
            "appId"     => $this->_appid,
            "nonceStr"  => $nonce_str,
            "timestamp" => $timestamp,
            "url"       => $url,
            "signature" => $signature,
            "rawString" => $string,
        );
        return $config;
    }
    
    /**
     * 官方创建随机字符串
     * @param int $length
     * @return string
     */
    private function createNonceStr($length = 16) {
        $chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
        $str = "";
        for ($i = 0; $i < $length; $i++) {
            $str .= substr($chars, mt_rand(0, strlen($chars) - 1), 1);
        }
        return $str;
    }
    
    /**
     * 获取网页jsapi的ticket
     * @return string
     */
    private function getJsApiTicket() {
        if( $jsapi_ticket = $this->getCacheValue('jsapi_ticket')) {
            return $jsapi_ticket;
        }
        //重新获取
        $url = 'https://api.weixin.qq.com/cgi-bin/ticket/getticket?type=jsapi&access_token='.
            $this->getAccesstoken();
        $content = curl_request($url);
        $js_obj = json_decode($content);
        $this->setCacheValue('jsapi_ticket', $js_obj->ticket, $js_obj->expires_in);
        return $js_obj->ticket;
    }

    /**
     * 用于第一次公众服务器验证我们URL合法性
     */
    public function firstValid() {
        if($this->checkSignature()) {
            echo $_GET['echostr'];	//告知签名合法
        } else {
            echo 'failed!';
        }
    }

    /**
     * 微信官方的签名验证算法
     * @return bool
     */
    private function checkSignature() {
        $signature = $_GET["signature"];
        $timestamp = $_GET["timestamp"];
        $nonce = $_GET["nonce"];	

        $token = $this->_token;
        $tmpArr = array($token, $timestamp, $nonce);
        sort($tmpArr, SORT_STRING);		//数字也按字典顺序排序
        $tmpStr = implode( $tmpArr );
        $tmpStr = sha1( $tmpStr );

        //debug: echo $tmpStr; exit;

        if( $tmpStr == $signature ){
            return true;
        }else{
            return false;
        }
    }
    
    /**
     * 响应公众平台消息方法
     */
    public function responseMsg() {
        //签名验证
        if(!$this->checkSignature())   exit('unsigned!');
        //获得原始POST数据
        $xml_str = $GLOBALS['HTTP_RAW_POST_DATA'];
        if(empty($xml_str))     exit('failed!') ;
        //解析xml，根据MsgType分发
        libxml_disable_entity_loader(true);     //防止外部实体注入(PHP 5 >= 5.2.11)
        $msg_obj = simplexml_load_string($xml_str, 'SimpleXMLElement', LIBXML_NOCDATA);
        return $msg_obj;
    }
    
    /**
     * 公用返回文本
     * @param type $from_user
     * @param type $to_user
     * @param type $content
     */
    public function sendText($from_user, $to_user, $content) {
        $response = '<xml>';
	$response .= '<ToUserName><![CDATA['.$to_user.']]></ToUserName>';
	$response .= '<FromUserName><![CDATA['.$from_user.']]></FromUserName>';
	$response .= '<CreateTime>'.time().'</CreateTime>';
	$response .= '<MsgType><![CDATA[text]]></MsgType>';
	$response .= '<Content><![CDATA['.$content.']]></Content>';
        $response .= '</xml>';
        echo $response;
    }
    
    /**
     * 公用返回图片
     * @param type $from_user
     * @param type $to_user
     * @param string $file 文件位置
     * @param bool $is_mediaid 上面给出的是MediaId(代表之前已经上传过)
     */
    public function sendImage($from_user, $to_user, $file, $is_mediaid=false) {
        if(!$is_mediaid) {
            $r_obj = $this->uploadTmpMedia($file, 'image'); //上传素材
            $media_id = $r_obj->media_id;
        } else {
            $media_id = $file;
        }
        $response = '<xml>';
	$response .= '<ToUserName><![CDATA['.$to_user.']]></ToUserName>';
	$response .= '<FromUserName><![CDATA['.$from_user.']]></FromUserName>';
	$response .= '<CreateTime>'.time().'</CreateTime>';
	$response .= '<MsgType><![CDATA[image]]></MsgType>';
	$response .= '<Image>';
	$response .= '<MediaId><![CDATA['.$media_id.']]></MediaId>';
	$response .= '</Image>';
        $response .= '</xml>';
        echo $response;
    }
    
    /**
     * 公用返回音乐
     * @param string $from_user
     * @param string $to_user
     * @param string $music_url   音乐在公网的url
     * @param string $music_hq_url   高品质在公网的url
     * @param string $thunb_pic   缩略图的位置或MediaId
     * @param bool $thumb_is_id   缩略图的位置或MediaId
     * @param type $title 标题
     * @param type $desc  描述
     */
    public function sendMusic($from_user, $to_user, $music_url, $music_hq_url, $thunb_pic, $thumb_is_id=false, $title='',$desc='') {
        if(!$thumb_is_id) {
            $r_obj = $this->uploadTmpMedia($thunb_pic, 'image'); //上传素材
            $media_id = $r_obj->media_id;
        } else {
            $media_id = $thunb_pic;
        }
        $response = '<xml>';
	$response .= '<ToUserName><![CDATA['.$to_user.']]></ToUserName>';
	$response .= '<FromUserName><![CDATA['.$from_user.']]></FromUserName>';
	$response .= '<CreateTime>'.time().'</CreateTime>';
	$response .= '<MsgType><![CDATA[music]]></MsgType>';
	$response .= '<Music>';
	$response .= '<Title><![CDATA['.$title.']]></Title>';
	$response .= '<Description><![CDATA['.$desc.']]></Description>';
	$response .= '<MusicUrl><![CDATA['.$music_url.']]></MusicUrl>';
	$response .= '<HQMusicUrl><![CDATA['.$music_url.']]></HQMusicUrl>';
	$response .= '<ThumbMediaId><![CDATA['.$media_id.']]></ThumbMediaId>';
	$response .= '</Music>';
        $response .= '</xml>';
        echo $response;
    }
    
    /**
     * 公用返回图文消息
     * @param string $from_user
     * @param string $to_user
     * @param array $news_list 新闻列表
     */
    public function sendNews($from_user, $to_user, $news_list=array()) {
        //条目部分
        $items = '';
        foreach ($news_list as $k=>$v) {
            $items .= '<item>';
            $items .= '<Title><![CDATA['.$v['title'].']]></Title> ';
            $items .= '<Description><![CDATA['.$v['description'].']]></Description>';
            $items .= '<PicUrl><![CDATA['.$v['picurl'].']]></PicUrl>';
            $items .= '<Url><![CDATA['.$v['url'].']]></Url>';
            $items .= '</item>';
        }
        //整体部分
        $response = '<xml>';
	$response .= '<ToUserName><![CDATA['.$to_user.']]></ToUserName>';
	$response .= '<FromUserName><![CDATA['.$from_user.']]></FromUserName>';
	$response .= '<CreateTime>'.time().'</CreateTime>';
	$response .= '<MsgType><![CDATA[news]]></MsgType>';
	$response .= '<ArticleCount>'.count($news_list).'</ArticleCount>';
        $response .= '<Articles>'.$items.'</Articles>';
        $response .= '</xml>';
        echo $response;
    }
    
    /**
     * 公用发送模板消息(主动)
     * @param string $to_user
     * @param string $temp_id
     * @param string $link
     * @param array $data 模板里的变量数据，关联数组
     * @return bool
     */
    public function sendTemplateMsg($to_user, $temp_id, $link, $data) {
        $url = 'https://api.weixin.qq.com/cgi-bin/message/template/send?access_token='.$this->getAccesstoken();
        $post_arr = array(
            "touser"    => $to_user,
            "template_id"=> $temp_id,
            "url"       => $link,
            "data"      => $data,
        );
        $post_str = json_encode($post_arr);
        //dbg:echo $post_str; exit;
        $result = curl_request($url, true, 'post', $post_str);
        $r_obj = json_decode($result);
        if($r_obj->errcode == 0) {
            return true;
        } else {
            echo $r_obj->errmsg;
            return false;
        }
    }
    
    /**
     * 公用发送客服消息(主动)
     * 参考: https://mp.weixin.qq.com/wiki/11/c88c270ae8935291626538f9c64bd123.html
     * @param string $to_user
     * @param mixed $data
     * @param string $type
     * @param bool $ret_inst 先立即返回（否则微信会提示'微信号无法服务'）
     */
    public function sendCustomerMsg($to_user, $data, $type='text', $ret_inst=true) {
        // 立即返回
        if($ret_inst) {
            //SAE的Apache使用以下代码无效
//            ignore_user_abort(true);
//            ob_start();
//            echo 'success'; // send the response
//            header('Connection: close');
//            header('Content-Length: ' . ob_get_length());
//            ob_end_flush();
//            ob_flush();
//            flush();
        }
        
        // 通过接口回复(暂时仅text)
        $url = 'https://api.weixin.qq.com/cgi-bin/message/custom/send?access_token='.$this->getAccesstoken();
        $post_arr = array(
            "touser"    => $to_user,
            "msgtype"   => $type,
            $type       => array(
                "content" => $data,
            ),
        );
        $post_str = json_encode($post_arr, JSON_UNESCAPED_UNICODE); //此接口中文不能转码
        $result = curl_request($url, true, 'post', $post_str);
        $r_obj = json_decode($result);
        
        if($r_obj->errcode == 0) {
            return true;
        } else {
            if(!$ret_inst) {  
                echo $r_obj->errmsg; 
            } else {
                sae_debug($r_obj->errmsg);
            }
            return false;
        }
    }
    
    /**
     * 公用按分组群发文本消息(主动)
     * @param string $content
     * @param bool $is_to_all
     * @param int $group_id
     * @return boolean
     */
    public function sendAllText($content, $is_to_all=true, $group_id=0) {
        $url = 'https://api.weixin.qq.com/cgi-bin/message/mass/sendall?access_token='.$this->getAccesstoken();
        $post_arr = array(
            'filter'    => array(
                'is_to_all' => $is_to_all,
                'group_id' => $group_id,
            ),
            'text'      => array(
                 'content'  => $content,
             ),
            'msgtype'   => 'text',
        );
        $post_str = json_encode($post_arr,JSON_UNESCAPED_UNICODE);  //JSON_UNESCAPED_UNICODE否则中文\u显示乱码
        $result = curl_request($url, true, 'post', $post_str);
        $r_obj = json_decode($result);
        if($r_obj->errcode == 0) {
            return true;
        } else {
            echo $r_obj->errmsg;
            return false;
        }
    }
    
    /**
     * 调用小黄鸡聊天机器人回复
     * @param type $msg_obj
     */
    public function callXiaohuangjiSend($msg_obj, $txt) {
        $url = 'http://www.niurenqushi.com/api/simsimi/';
        //$data['txt'] = $txt;  //编码按 multipart/form-data
        $data = 'txt='.urlencode($txt); //必须编码按application/x-www-form-urlencoded
        $ret = curl_request($url, false, 'post', $data);
        $content = json_decode($ret)->text;
        $this->sendText($msg_obj->ToUserName, $msg_obj->FromUserName, $content);
    }
    
    /**
     * 上传临时素材
     * @param string $file 文件位置
     * @param string $type 媒体类型
     * @return obj 上传后json结果
     */
    public function uploadTmpMedia($file, $type) {
        $url = 'https://api.weixin.qq.com/cgi-bin/media/upload?access_token='.
                $this->getAccesstoken().'&type='.$type;
        $data = array(
            'media' => '@'.$file,	//@代表后面是文件地址
        );
        $result = curl_request($url,true,'post',$data);
        return json_decode($result);
    }
    
    
    /**
     * 上传永久素材
     * @param string $file 文件位置
     * @param string $type 媒体类型
     * @return obj 上传后json结果
     */
    public function uploadStillMedia($file, $type) {
        $url = 'https://api.weixin.qq.com/cgi-bin/material/add_material?access_token='.
                $this->getAccesstoken().'&type='.$type;
        $data = array(
            'media' => '@'.$file,	//@代表后面是文件地址
        );
        $result = curl_request($url,true,'post',$data);
        return json_decode($result);
    }
    
    /**
     * 删除菜单
     * @return bool
     */
    public function menuDelete() {
        $url = 'https://api.weixin.qq.com/cgi-bin/menu/delete?access_token='.$this->getAccesstoken();
        $result = curl_request($url);
        $r_obj = json_decode($result);
        return $r_obj->errcode==0?true:false;
    }
    
    /**
     * 创建菜单
     * @return bool
     */
    public function menuCreate($menu) {
        $data = $menu;
        $url = 'https://api.weixin.qq.com/cgi-bin/menu/create?access_token='.$this->getAccesstoken();
        $result = curl_request($url,true,'post',$data);
        $r_obj = json_decode($result);
        if($r_obj->errcode==0) {
            return true;
        } else {
            echo $r_obj->errmsg,'<br/>';
            return false;
        }
    }
}

/**
 * curl通用请求方法
 * @param string $url
 * @param bool $https
 * @param string $method
 * @param mixed $data  array|string
 * @return mixed
 */
function curl_request($url, $https=true, $method='get', $data=null) {
    $ch = curl_init();                                  //初始化，返回资源号
    curl_setopt($ch,CURLOPT_URL,$url);			//访问的url
    curl_setopt($ch,CURLOPT_HEADER,false);		//不需要头信息
    curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);	//不输出到页面仅返回字串

    if($https) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);//不做服务器端认证（如支付时则需要）
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);//不做客户端认证
    }
    if($method == 'post') {
        curl_setopt($ch, CURLOPT_POST, true);		//设置post方式
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);    //设置post的数据
    }

    $content = curl_exec($ch);
    if($content === false) {
        echo $errmsg = curl_error($ch);
    }
    //执行访问
    curl_close($ch);					//关闭资源
    return $content;
}

