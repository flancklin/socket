<?php
/**
 * Created by PhpStorm.
 * User: 25754
 * Date: 2019/4/23
 * Time: 14:13
 */

class socketServer
{

    const LISTEN_SOCKET_NUM = 9;
    const LOG_PATH = "./log/";
    const SEND_ALL_CLIENT = 'all';
    /**
     * $_clientSockets
     * [
     *    [
     *      'resource'=>'',
     *      'handShake'=>true/false
     *    ]
     * ]
     *
     */
    private $_master = null;//服务端
    private $_clientSockets = [];//存客户端连接+是否握手判断
    private $_clientInfo = [];//客户端用户信息

    public function run()
    {
        if(!$this->initMaster('127.0.0.1',1234)) return $this->log('init', true);
        while (true) {
            try {
                $this->initClient();
            } catch (Exception $e) {
                $this->log("init-client:fail", true);
            }
        }
    }

    /**
     * @param $ip
     * @param $port
     * @return bool
     */
    private function initMaster($ip, $port){
        $this->log("master:begin");
        $this->_master = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->_master && socket_set_option($this->_master, SOL_SOCKET, SO_REUSEADDR, 1) or $this->_master = null;// 设置IP和端口重用,在重启服务器后能重新使用此端口;
        $this->_master && socket_bind($this->_master, $ip, $port) or $this->_master = null;//绑定地址与端口
        //listen函数使用主动连接套接口变为被连接套接口，使得一个进程可以接受其它进程的请求，从而成为一个服务器进程。
        //在TCP服务器编程中listen函数把进程变为一个服务器，并指定相应的套接字变为被动连接,其中的能存储的请求不明的socket数目。
        $this->_master && socket_listen($this->_master, self::LISTEN_SOCKET_NUM) or $this->_master = null;
        $this->log("master:end");
        return  $this->_master;
    }

    private function initClient(){
        $this->log("client:begin");
        //监听client是否有信息 sockets_select。（从众多client中选出有信息的那几个）
        $write = $except = NULL;
        $allSockets = array_column($this->_clientSockets, 'resource');
        array_push($allSockets, $this->_master);//把服务端master也加入监听范围，因为新建连接需要master【这里会阻塞】
        $this->log('client:socket_select');
        if (false === socket_select($allSockets, $write, $except, NULL)) return $this->log("socket_select:fail", true);

        //遍历处理有信息的client
        foreach ($allSockets as $socket) {
            $clientKey = (int)$socket;
            $this->log("client:socket:int:".intval($socket).':socket(type):'.gettype($socket));
            if ($socket == $this->_master) {
                //网络第一次
                $this->dealConnect();//接收到client请求，建立socket连接
            } else {
                //接受数据
                $bytes = @socket_recv($socket, $buffer, 2048, 0);
                //没握手的先握手
                if ($this->_clientSockets[$clientKey]['handShake'] == false) {
                    //网络第二次
                    $this->dealHandShake($socket, $buffer);
                    continue;
                }
                //网络第三次+n次
                if ($bytes == 0) {
                    $data = $this->dealDisconnect($clientKey);
                } else {
                    $data = $this->dataFrameParse($buffer);
                }
                $this->dealGetData($socket, $data);//把接收到的数据进行处理，把处理结果发送出去(全发送，发给全部client)
            }
        }
    }

    /**
     * 连接socket
     * @param $client
     */
    protected function dealConnect()
    {
        //从服务端中接收client请求
        if (false === $client = socket_accept($this->_master))  return $this->log("deal-connect:fail", true);
        //获取客户端信息
        socket_getpeername ( $client  , $clientIP ,$clientPort );
        //把客户端加入连接池
        $this->_clientSockets[(int)$client] = [ 'resource' => $client, 'handShake' => false, 'ip' => $clientIP,'port' => $clientPort];
        $this->log(['label' => 'deal-connect', 'ip' => $clientIP, 'port' => $clientPort]);
        return true;
    }

    /**
     * 断开连接
     * @param $clientKey
     * @return array
     */
    protected function dealDisconnect($clientKey)
    {
        unset($this->_clientSockets[$clientKey]);
        unset($this->_clientInfo[$clientKey]);
        return ['type' => 'logout','msg' => @$this->_clientInfo[$clientKey]['username']];
    }

    /**
     * 业务处理
     * @param $socket
     * @param $recv_msg
     * @return void
     */
    protected function dealGetData($socket, $recv_msg)
    {
        $clientKey = (int)$socket;
        $msg_type = $recv_msg['type'];
        $msg_content = $recv_msg['msg'];
        $response = ['type' => $msg_type];
        switch ($msg_type) {
            case 'login':
                $this->_clientInfo[$clientKey] = [
                    'client_no' => $clientKey,
                    "username" => $msg_content,
                    'headerimg' => $recv_msg['headerimg'] ?? '',
                    "login_time" =>time()
                ];
                // 取得最新的名字记录
                $response['msg'] = $msg_content;
                $response['user_list'] = array_values($this->_clientInfo);;
                break;
            case 'logout':
                $response['user_list'] = array_values($this->_clientInfo);;
                $response['msg'] = $msg_content;
                break;
            case 'user':
                $userInfo = $this->_clientInfo[$clientKey];
                $response['from'] = $userInfo['username'];
                $response['msg'] = $msg_content;
                $response['headerimg'] = $userInfo['headerimg'];
                break;
        }
        $this->dealSendData(self::SEND_ALL_CLIENT, $response);
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
            $sockets = array_column($this->_clientSockets, 'resource');
        }elseif(is_array($client)){
            $sockets = $client;
        }

        $dataStr = $this->dataFrameEncrypt($data);
        foreach ($sockets as $socket) {
            socket_write($socket, $dataStr, strlen($dataStr));
        }
        return true;
    }

    /**
     * socket握手
     * @param $socket
     * @param $buffer
     * @return bool
     */
    protected function dealHandShake($socket, $buffer)
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
        socket_write($socket, $upgrade, strlen($upgrade));
        // 标记握手已经成功，下次接受数据采用数据帧格式
        $this->_clientSockets[(int)$socket]['handShake'] = true;
        //发送消息通知客户端握手成功
        return $this->dealSendData($socket, ['type' => 'handShake', 'msg' => '握手成功']);
    }

    /**
     * 帧数据封装
     * @param $msg
     * @return string
     */
    private function dataFrameEncrypt($msg)
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
    private function dataFrameParse($buffer)
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

    private function log($label, $flagErr = false){
        $data = is_array($label) ? $label : ['label' => $label];
        if($flagErr){
            $data['err_no'] = $errNo = socket_last_error();
            $data['err'] = socket_strerror($errNo);
        }
        file_put_contents(self::LOG_PATH . 'websocket_debug3.log', implode(' | ', $data) . "\r\n", FILE_APPEND);
        return 'log';
    }
}

(new socketServer())->run();