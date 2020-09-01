<?php
namespace flancklin\websocket;


abstract class BaseSocket{
    //服务端
    protected $_master = null;
    //客户端
    protected $_clients = [];//全部的客户端连接   [(int)$client = >$client]
    protected $_handShakes = [];//全部的客户端握手清空   [(int)$client => true]
    //用户信息
    protected $_users = [];//[uid=>['headimg'=>'...jpg','name'=>'..dd']]
    protected $_userSocket = [];//用户和socket连接的绑定。[uid=>(int)$client]
    //日志
    const LOG_PATH = ".".DIRECTORY_SEPARATOR."log".DIRECTORY_SEPARATOR;
    const SEND_ALL_CLIENT = 'all';

    public function run($ip, $port, $params = [])
    {
        if(!$this->initMaster($ip, $port)) return '';
        while (true) {
            try {
                $this->initClient();
            } catch (\Exception $e) {
                $this->log("init-client:fail", true);
            }
        }
    }

    /**
     * @param $ip
     * @param $port
     * @param $listenSocketNum
     * @return bool
     */
    protected function initMaster($ip, $port, $listenSocketNum =9){
        $this->log("master:begin");
        $this->_master = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->_master && socket_set_option($this->_master, SOL_SOCKET, SO_REUSEADDR, 1) or $this->_master = null;// 设置IP和端口重用,在重启服务器后能重新使用此端口;
        $this->_master && socket_bind($this->_master, $ip, $port) or $this->_master = null;//绑定地址与端口
        //listen函数使用主动连接套接口变为被连接套接口，使得一个进程可以接受其它进程的请求，从而成为一个服务器进程。
        //在TCP服务器编程中listen函数把进程变为一个服务器，并指定相应的套接字变为被动连接,其中的能存储的请求不明的socket数目。
        $this->_master && socket_listen($this->_master, $listenSocketNum) or $this->_master = null;
        $this->log("master:end");
        return  $this->_master;
    }
    abstract protected function initClient();
    /**
     * socket握手
     * @param $client
     * @param $buffer
     * @return bool
     */
    protected function dealHandShake($client, $buffer)
    {
        //提取 Sec-WebSocket-Key 信息
        $key = null;
        if (preg_match("/Sec-WebSocket-Key: (.*)\r\n/", $buffer, $match)) {
            $key = $match[1];
        }
        //加密 Sec-WebSocket-Key
        $acceptKey = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

        $upgrade = "HTTP/1.1 101 Switching Protocols\r\n" .
            "Upgrade: websocket\r\n" .
            "Connection: Upgrade\r\n" .
            "Sec-WebSocket-Accept: " . $acceptKey . "\r\n\r\n";

        // 写入socket
        socket_write($client, $upgrade, strlen($upgrade));
        $this->_handShakes[(int)$client] = true;// 标记握手已经成功，下次接受数据采用数据帧格式
        $this->dealSendData($client, ['type' => 'hand_shake', 'message' => '握手成功']);
        return  true;
    }

    /**
     * 连接socket
     * @param $client
     */
    protected function dealConnect()
    {
        $client = socket_accept($this->_master);
        //从服务端中接收client请求
        if (false === $client)  return $this->log("deal-connect:fail", true);

        //把客户端加入连接池
        $this->_clients[(int)$client] = $client;
        return true;
    }
    /**
     * 断开连接
     * @param  $key
     * @param $type
     * @return boolean
     */
    protected function dealDisconnect($key, $type = 'clientKey')
    {
        if($type == 'clientKey'){
            $uid = array_search($key, $this->_userSocket);
        }else if($type == 'uid'){
            $uid = $type;
            $key = $this->_userSocket[$uid];
        }
        if($uid){
            unset($this->_userSocket[$uid]);//删掉【用户和socket连接的绑定】
            unset($this->_users[$uid]);//删掉用户信息
        }
        if($key){
            unset($this->_clients[$key]);//删掉连接
            unset($this->_handShakes[$key]);//删掉握手信息
        }
        return true;
    }
    /**
     * 数据广播
     * @param $client
     * @param $data
     * @return boolean
     */
    protected function dealSendData($client, $data)
    {
        $sockets = [];
        if(is_resource($client)){
            $sockets = [$client];
        }elseif($client == self::SEND_ALL_CLIENT){
            $sockets = array_values($this->_clients);
        }elseif(is_array($client)){
            $sockets = $client;
        }
        $dataStr = $this->dataFrameEncrypt($data);
        foreach ($sockets as $socket) {
            socket_write($socket, $dataStr, strlen($dataStr));
        }
        return true;
    }

    protected function log($label, $flagErr = false){
        $data = is_array($label) ? $label : ['label' => $label];
        if($flagErr){
            $data['err_no'] = $errNo = socket_last_error();
            $data['err'] = socket_strerror($errNo);
        }
        file_put_contents(static::LOG_PATH . 'websocket_debug3.log', implode(' | ', $data) . "\r\n", FILE_APPEND);
        return 'log';
    }

    /**
     * 帧数据封装
     * @param $msg
     * @return string
     */
    protected function dataFrameEncrypt($msg)
    {
        $msg = json_encode($msg);
        $frame = [];
        $frame[0] = '81';
        $len = strlen($msg);
        if ($len < 126) {
            $frame[1] = $len < 16 ? '0' . dechex($len) : dechex($len);
        } else if ($len < 65025) {
            $s = dechex($len);
            $frame[1] = '7e' . str_repeat('0', 4 - strlen($s)) . $s;
        } else {
            $s = dechex($len);
            $frame[1] = '7f' . str_repeat('0', 16 - strlen($s)) . $s;
        }
        $data = '';
        $l = strlen($msg);
        for ($i = 0; $i < $l; $i++) {
            $data .= dechex(ord($msg{$i}));
        }
        $frame[2] = $data;
        $data = implode('', $frame);
        return pack("H*", $data);
    }

    /**
     * 接受数据解析
     * @param $buffer
     * @return mixed
     */
    protected function dataFrameParse($buffer)
    {
        $decoded = '';
        $len = ord($buffer[1]) & 127;
        if ($len === 126) {
            $masks = substr($buffer, 4, 4);
            $data = substr($buffer, 8);
        } else if ($len === 127) {
            $masks = substr($buffer, 10, 4);
            $data = substr($buffer, 14);
        } else {
            $masks = substr($buffer, 2, 4);
            $data = substr($buffer, 6);
        }
        for ($index = 0; $index < strlen($data); $index++) {
            $decoded .= $data[$index] ^ $masks[$index % 4];
        }
        return json_decode($decoded, true);
    }
}