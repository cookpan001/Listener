<?php

namespace cookpan001\Listener\Bussiness;

class Mediator
{
    private $emiter;
    private $storage;
    
    public $keys = array();
    public $connections = array();
    public $register = array();
    
    public $logger = null;
    public $app = null;
    
    public function __construct($app)
    {
        $this->app = $app;
        $this->logger = $this->app->logger;
        $this->storage = $this->app->storage;
        $this->emiter = $this->app->emiter;
    }
    
    public function __destruct()
    {
        $this->logger = null;
        $this->app = null;
    }
    
    public function getInstance($name)
    {
        return $this->app->getInstance($name);
    }
    
    /**
     * Acceptor间信息交换时使用, Socket
     */
    public function onExchage($conn, $data)
    {
        if(empty($data)){
            return;
        }
        $this->logger->log(__FUNCTION__.': '.json_encode($data));
        foreach($data as $param){
            $cmd = array_shift($param);
            $command = 'ex'.ucfirst($cmd);
            if(method_exists($this, $command)){
                call_user_func(array($this, $command), $conn, ...$param);
            }
        }
    }
    
    public function onConnect($conn)
    {
        if(!isset($this->connections[$conn->id])){
            $this->connections[$conn->id] = $conn;
        }
        $conn->on('close', function($id){
            $conn = $this->connections[$id];
            if(isset($this->register[$id])){
                unset($this->register[$id]);
            }
            $keys = $conn->keys();
            $update = array();
            foreach($keys as $key){
                if(isset($this->keys[$key][$id])){
                    unset($this->keys[$key][$id]);
                }
                $update[$key] = count($this->keys[$key]);
            }
            unset($this->connections[$id]);
            if($update){
                foreach($this->register as $brotherId => $__){
                    if(!isset($this->connections[$brotherId])){
                        continue;
                    }
                    $conn = $this->connections[$brotherId];
                    $conn->reply('notify', array_keys($update), array_values($update));
                }
            }
        });
    }
    
    public function exSend($conn, $key, $value)
    {
        $conn->reply('mediator', 'ack', $key, $value);
        if(empty($this->keys[$key])){
            $this->storage->set($key, $value, $value);
            return false;
        }
        foreach($this->keys[$key] as $id => $num){
            if($id == $conn->id){
                continue;
            }
            if($num <= 0){
                unset($this->keys[$key][$id]);
                continue;
            }
            if(!isset($this->connections[$id])){
                unset($this->keys[$key][$id]);
                continue;
            }
            $this->connections[$id]->reply('mediator', 'push', $key, $value);
            return true;
        }
        $this->storage->set($key, $value, $value);
        return false;
    }
    
    public function exSubscribe($conn, ...$para)
    {
        $update = array();
        foreach($para as $key){
            if(!isset($this->keys[$key][$conn->id])){
                $this->keys[$key][$conn->id] = 0;
            }
            $this->keys[$key][$conn->id] += 1;
            $conn->subscribe($key);
            $tmp = $this->storage->getAndRemove($key);
            if($tmp){
                $conn->reply('mediator', 'push', $key, ...$tmp);
            }
            $update[$key] = count($this->keys[$key]);
        }
        if($update){
            foreach($this->register as $brotherId => $__){
                if(!isset($this->connections[$brotherId])){
                    continue;
                }
                $conn = $this->connections[$brotherId];
                $conn->reply('notify', array_keys($update), array_values($update));
            }
        }
    }
    
    public function exUnsubscribe($conn, ...$para)
    {
        $update = array();
        foreach($para as $key){
            if(isset($this->keys[$key][$conn->id])){
                $this->keys[$key][$conn->id] -= 1;
            }
            if($this->keys[$key][$conn->id] <= 0){
                $conn->unsubscribe($key);
                unset($this->keys[$key][$conn->id]);
            }
            $update[$key] = count($this->keys[$key]);
        }
        if($update){
            foreach($this->register as $brotherId => $__){
                if(!isset($this->connections[$brotherId])){
                    continue;
                }
                $conn = $this->connections[$brotherId];
                $conn->reply('notify', array_keys($update), array_values($update));
            }
        }
    }
    //有其他Mediator连接到来，发送本地监听的key到该连接
    public function exRegister($conn, $host, $port)
    {
        $this->register[$conn->id] = $host .':'. $port;
        $conn->reply('notify', array_keys($this->keys), array_map('array_sum', $this->keys));
    }
    
    public function exInfo($conn)
    {
        $info = array(
            'connections: '.count($this->connections),
            'keys: '.json_encode(array_keys($this->keys)),
            'keys_detail: '.array_map('array_sum', $this->keys),
            'registered: '.json_encode(array_values($this->register)),
        );
        $conn->reply($info);
    }
}