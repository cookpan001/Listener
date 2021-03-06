<?php

namespace cookpan001\Listener;

class Listener
{
    const FRAME_SIZE = 1500;
    
    public $host = '0.0.0.0';
    public $port;
    public $socket;
    public $allConnections = 0;
    public $codec = null;
    public $connections = array();
    public $callback = null;
    public $logger = null;
    public $socketLoop = null;
    public $id = 0;
    public $handler = null;
    public $periodTimer = null;

    public function __construct($host, $port, $codec, $logger = null)
    {
        $this->host = $host;
        $this->port = $port;
        $this->codec = $codec;
        if(is_null($logger)){
            $this->logger = new Logger();
        }else{
            $this->logger = $logger;
        }
    }
    
    public function __call($name, $arguments)
    {
        $this->logger->log('unknown method: '.$name . ', args: '. json_encode($arguments)."\n");
    }
    
    public function stop()
    {
        socket_close($this->socket);
    }
    
    public function create()
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if(!$socket){
            $this->logger->log("Unable to create socket");
            $this->periodTimer = new \EvPeriodic(0, 1, null, function(){
                $this->create();
            });
            return false;
        }
        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
        if(!socket_bind($socket, $this->host, $this->port)){
            $this->logger->log("Unable to bind socket port: ".$this->port);
            $this->periodTimer = new \EvPeriodic(0, 1, null, function(){
                $this->create();
            });
            return false;
        }
        if(!socket_listen($socket)){
            $this->logger->log("Unable to listen socket");
            $this->periodTimer = new \EvPeriodic(0, 1, null, function(){
                $this->create();
            });
            return false;
        }
        socket_set_nonblock($socket);
        $this->socket = $socket;
        if($this->periodTimer){
            $this->periodTimer->stop();
            $this->periodTimer = null;
        }
    }
    
    public function setCodec($codec)
    {
        $this->codec = $codec;
    }
    
    public function setHandler($obj)
    {
        $this->handler = $obj;
    }
    
    public function on($condition, callable $func)
    {
        $this->callback[$condition][] = $func;
        return $this;
    }
    
    public function emit($condition, ...$param)
    {
        if(!isset($this->callback[$condition])){
            return false;
        }
        foreach($this->callback[$condition] as $callback){
            call_user_func_array($callback, $param);
        }
        return true;
    }
    
    public function setId($id)
    {
        $this->id = $id;
    }
    
    public function errorHandler($errno, $errstr, $errfile, $errline, $errcontext)
    {
        $str = sprintf("%s:%d\nerrcode:%d\t%s\n%s\n", $errfile, $errline, $errno, $errstr, json_encode($errcontext, JSON_PARTIAL_OUTPUT_ON_ERROR));
        $this->logger->error($str);
    }
    
    public function fatalHandler()
    {
        $error = error_get_last();
        if(empty($error)){
            return ;
        }
        $this->logger->error(json_encode($error, JSON_PARTIAL_OUTPUT_ON_ERROR));
    }
    
    public function loop()
    {
        if (\Ev::supportedBackends() & ~\Ev::recommendedBackends() & \Ev::BACKEND_KQUEUE) {
            if(PHP_OS != 'Darwin'){
                $this->socketLoop = new \EvLoop(\Ev::BACKEND_KQUEUE);
            }
        }
        if (!$this->socketLoop) {
            $this->socketLoop = \EvLoop::defaultLoop();
        }
    }
    
    public function start()
    {
        $this->emit('local', $this->host, $this->port);
        $socket = $this->socket;
        $that = $this;
        $this->serverWatcher = new \EvIo($this->socket, \Ev::READ, function () use ($that, $socket){
            $clientSocket = socket_accept($socket);
            $that->process($clientSocket);
            ++$that->allConnections;
        });
    }
    
    public function process($clientSocket)
    {
        socket_set_nonblock($clientSocket);
        $conn = new Connection($clientSocket, $this);
        $that = $this;
        $id = uniqid();
        $that->logger->log("new connection to {$that->host}:{$that->port}, id:{$id}");
        $watcher = new \EvIo($clientSocket, \Ev::READ, function() use ($that, $conn){
            $str = $that->receive($conn);
            if(false !== $str){
                $that->logger->log('----------------'.__CLASS__.' BEGIN----------------');
                if($that->codec && $str != "\r" && $str != "\n" && $str != "\r\n"){
                    $commands = $that->codec->unserialize($str);
                    $that->logger->log('***************'.__CLASS__.' PARSED***************');
                    $ret = $that->emit('message', $conn, $commands);
                    $that->logger->log('***************'.__CLASS__.' HANDLED***************');
                    if(false === $ret){
                        $that->logger->log($commands);
                        $that->reply($conn, 1);
                    }
                }
                $that->logger->log('----------------'.__CLASS__.' FINISH---------------');
            }
        });
        $conn->setId($id);
        $conn->setWatcher($watcher);
        $this->connections[$id] = $conn;
        $this->emit('connect', $conn);
    }
    
    public function receive(Connection $conn)
    {
        $tmp = '';
        $str = '';
        $i = 0;
        while(true){
            ++$i;
            $num = socket_recv($conn->clientSocket, $tmp, self::FRAME_SIZE, MSG_DONTWAIT);
            if(is_int($num) && $num > 0){
                $str .= $tmp;
            }
            $errorCode = socket_last_error($conn->clientSocket);
            socket_clear_error($conn->clientSocket);
            if (EAGAIN == $errorCode || EINPROGRESS == $errorCode) {
                break;
            }
            if((0 === $errorCode && null === $tmp) || EPIPE == $errorCode || ECONNRESET == $errorCode){
                $this->closed($conn);
                return false;
            }
            if(0 === $num){
                break;
            }
        }
        return $str;
    }
    
    public function closed($conn)
    {
        if(isset($this->connections[$conn->id])){
            unset($this->connections[$conn->id]);
        }
        $this->logger->log('connection: '.$conn->id . ' closed');
        $conn->close();
    }
    
    public function reply($conn, ...$param)
    {
        if($this->codec){
            if(count($param) > 1){
                $message = $this->codec->serialize($param);
            }else{
                $message = $this->codec->serialize($param[0]);
            }
            $num = socket_write($conn->clientSocket, $message, strlen($message));
            if(false === $num){
                $this->closed($conn);
                return false;
            }
            $this->logger->log('***************'.__CLASS__.' REPLY***************');
            $tmp = '';
            socket_recv($conn->clientSocket, $tmp, self::FRAME_SIZE, MSG_DONTWAIT);
            $errorCode = socket_last_error($conn->clientSocket);
            socket_clear_error($conn->clientSocket);
            if((0 === $errorCode && null === $tmp) || EPIPE == $errorCode || ECONNRESET == $errorCode){
                $this->closed($conn);
                return false;
            }
            if(strlen($tmp)){
                $ret = $this->receive($conn);
                if(false === $ret){
                    return false;
                }
                return $ret . $tmp;
            }
        }
        return true;
    }
}