<?php

namespace flancklin\websocket;

class OneToGroup extends BaseSocket {


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
                list($sendClient, $response) = $this->dealGetData((int)$client, $data);//把接收到的数据进行处理，把处理结果发送出去(全发送，发给全部client)
                $sendClient && $response && $this->dealSendData($sendClient, $response);
            }
        }
    }

    /**
     * 业务处理
     * @param $clientKey
     * @param $data
     * @return mixed
     */
    protected function dealGetData($socket, $recv_msg)
    {
        $clientKey = (int)$socket;
        $msg_type = $recv_msg['type'];
        $msg_content = $recv_msg['msg'];
        $response = ['type' => $msg_type];
        switch ($msg_type) {
            case 'login':
                $this->_users[$clientKey] = [
                    'client_no' => $clientKey,
                    "username" => $msg_content,
                    'headerimg' => $recv_msg['headerimg'] ?? '',
                    "login_time" =>time()
                ];
                // 鍙栧緱鏈€鏂扮殑鍚嶅瓧璁板綍
                $response['msg'] = $msg_content;
                $response['user_list'] = array_values($this->_users);;
                break;
            case 'logout':
                $response['user_list'] = array_values($this->_users);;
                $response['msg'] = $msg_content;
                break;
            case 'user':
                $userInfo = $this->_users[$clientKey];
                $response['from'] = $userInfo['username'];
                $response['msg'] = $msg_content;
                $response['headerimg'] = $userInfo['headerimg'];
                break;
            default:
                $response = false;
        }
        return [self::SEND_ALL_CLIENT, $response];
    }
}