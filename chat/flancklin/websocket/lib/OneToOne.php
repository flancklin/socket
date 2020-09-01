<?php

namespace flancklin\websocket;

class OneToOne extends BaseSocket
{
    protected function initClient(){
        $this->log("client:begin");
        //监听client是否有信息 sockets_select。（从众多client中选出有信息的那几个）
        $write = $except = NULL;
        $allSockets = array_values($this->_clients);
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
                $client = $socket;
                //接受数据
                $bytes = @socket_recv($client, $buffer, 2048, 0);
                //没握手的先握手
                if (!isset($this->_handShakes[$clientKey]) || $this->_handShakes[$clientKey] !== true) {
                    //网络第二次
                    $this->dealHandShake($client, $buffer);
                    continue;
                }
                //网络第三次+n次
                if ($bytes == 0) {
                    $data = $this->dealDisconnect($clientKey);
                } else {
                    $data = $this->dataFrameParse($buffer);
                }
                list($sendClient, $response) = $this->dealGetData($client, $data);//把接收到的数据进行处理，把处理结果发送出去(全发送，发给全部client)
                $sendClient && $response && $this->dealSendData($sendClient, $response);
            }
        }
    }

    /**
     * 业务处理
     * @param $client
     * @param $data
     * @return mixed
     */
    protected function dealGetData($client, $data)
    {
        $clientKey = (int)$client;
        //服务端
        //发送：  ['type' => 'hand_shake', 'message' => '握手成功']
        //接收：  ['type' => 'login', 'uid_from' => 123]
        //发送：  ['type' => 'login', 'uid_from' => 123, 'message' =>'登录成功']
        //接收：  ['type' => 'chat', 'uid_from'=>123, 'uid_to' => 456, 'message' => '2号。你在干嘛']
        //发送：  ['type' => 'chat', 'uid_from'=>[info], 'uid_to' => [info], 'message' => '2号。你在干嘛']
        //接收：  ['type' => 'logout', 'uid_from'=>123]

        //错误：  ['type' => 'error', 'code'=>101, 'message' => '用户不存在']


        $uidFrom = $data['uid_from'] ?? 0;
        $type = $data['type'];

        $sockets = [];
        $response = ['type' => $type];
        switch ($type) {
            case 'login':
                $this->_users[$uidFrom] = $this->userInfo($uidFrom);
                $this->_userSocket[$uidFrom] = $client;
                $response['user_list'] = array_values($this->_users);;
                $response['message'] = '系统消息:'. $this->_users[$uidFrom]['username'].'已上线';
                $sockets = self::SEND_ALL_CLIENT;//通知所有人我登录成功了
                break;
            case 'logout':
                $response['user_list'] = array_values($this->_users);
                $response['message'] = '系统消息:'. $this->_users[$uidFrom]['username'].'已下线';
                $sockets = self::SEND_ALL_CLIENT;//通知所有人我退出了
                break;
            case 'chat':
                $uidTo = $data['uid_to'] ?? 0;
                $toClient = $uidTo ? (isset($this->_userSocket[$uidTo]) ? $this->_userSocket[$uidTo] : null) : null;
                if($uidTo && $toClient){
                    $response = $data;
                    $response['uid_from'] = $this->_users[$uidFrom];
                    $response['uid_to'] = $this->_users[$uidTo];
                    $sockets = [$client, $toClient];
                }else{
                    $response = ['type' => 'error', 'code'=>101, 'message' => '用户不存在'];
                    $sockets = $client;
                }
                break;
            default:
                $response = false;
        }
        return [$sockets, $response];
    }


    public function userInfo($uid){
        $imgArr = ['a1.jpg', 'a2.jpg', 'a3.jpg', 'a4.jpg', 'a5.jpg', 'a6.jpg', 'a7.jpg', 'a8.jpg', 'a9.jpg', 'a10.jpg'];
        static $imgRelation = [];
        if(isset($imgRelation[$uid])){
            $img = $imgRelation[$uid];
        }else{
            $img = 'img/' . $imgArr[array_rand($imgArr)];
            $imgRelation[$uid] = $img;
        }

        return [
            'uid' => $uid,
            'username' => 'name'.$uid,
            'headerimg' => $img,
        ];
    }
}