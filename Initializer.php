<?php

namespace cookpan001\Listener;

class Initializer
{
    public $listener = array();
    public $handler = array();
    public $worker = array();
    public $codec = array();
    public $parent = null;
    public $logger = null;
    public $storage = null;
    public $emiter = null;
    
    public function __construct()
    {
        $this->logger = new cookpan001\Listener\Logger();
        $this->storage = new cookpan001\Listener\Storage();
        $this->emiter = new cookpan001\Listener\Emiter();
    }
    
    public function __destruct()
    {
        
    }
    
    public function __call($name, $arguments)
    {
        var_export('unknown method: '.$name . ', args: '. json_encode($arguments)."\n");
    }
    
    public function getCodec($codec)
    {
        if(!isset($this->codec[$codec])){
            $this->codec[$codec] = new $codec;
        }
        return $this->codec[$codec];
    }
    
    public function createServer($conf)
    {
        $server = new \cookpan001\Listener\Listener($conf['port'], $this->getCodec($conf['codec']), $this->logger);
        $server->create();
        $server->setId($conf['name']);
        if(isset($conf['on'])){
            $obj = new $conf['class']($this);
            foreach($conf['on'] as $condition => $callback){
                if(is_callable($callback)){
                    $server->on($condition, $callback);
                }else{
                    $server->on($condition, array($obj, $callback));
                }
            }
            $this->handler[$conf['name']] = $obj;
        }
        if(isset($conf['emit'])){
            foreach($conf['emit'] as $condition => $callback){
                $this->emiter->on($condition, array($server, $callback));
            }
        }
        return $server;
    }
    
    public function createClient($conf)
    {
        if($conf['role'] == 'agent'){
            $client = new \cookpan001\Listener\Agent($conf['instance']);
        }else{
            $client = new \cookpan001\Listener\Client($conf['host'], $conf['port']);
        }
        $client->setCodec($this->getCodec($conf['codec']));
        $client->setLogger($this->logger);
        $client->setId($conf['name']);
        if(isset($conf['on'])){
            $obj = new $conf['class']($this, $client);
            foreach($conf['on'] as $condition => $callback){
                if(is_callable($callback)){
                    $client->on($condition, $callback);
                }else{
                    $client->on($condition, array($obj, $callback));
                }
            }
            $this->handler[$conf['name']] = $obj;
        }
        if(isset($conf['emit'])){
            foreach($conf['emit'] as $condition => $callback){
                $this->emiter->on($condition, array($client, $callback));
            }
        }
        return $client;
    }
    /**
     * 单进程监听多个端口
     */
    public function init($config)
    {
        $after = array();
        foreach($config as $conf){
            if($conf['role'] == 'server'){
                $app = $this->createServer($conf);
                $this->listener[$app->id] = $app;
            }else{
                $app = $this->createClient($conf);
                $this->listener[$app->id] = $app;
            }
            if(isset($config['after'])){
                foreach($config['after'] as $funcName){
                    $after[] = array($this->listener[$app->id], $funcName);
                }
            }
        }
        foreach($after as $func){
            list($obj, $name) = $func;
            if(method_exists($obj, $name)){
                call_user_func($func);
            }
        }
        foreach($this->listener as $app){
            $app->start();
        }
    }
    
    public function getInstance($name)
    {
        if(isset($this->handler[$name])){
            return $this->handler[$name];
        }
        return null;
    }
    
    public function start()
    {
        \Ev::run();
    }
}