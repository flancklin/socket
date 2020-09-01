<?php

include_once './flancklin/websocket/autoload.php';


(new \flancklin\websocket\ClientService())->run('127.0.0.1', '1233');