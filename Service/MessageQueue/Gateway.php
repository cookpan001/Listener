<?php

include dirname(__DIR__) . DIRECTORY_SEPARATOR . 'base.php';
include dirname(__DIR__) . DIRECTORY_SEPARATOR . 'autoload.php';
$config = array(
    array(
        'codec' => 'cookpan001\Listener\Codec\MessagePack',
        'class' => 'cookpan001\Listener\Bussiness\MessageQueue\Acceptor',
        'name' => 'acceptor',
        'role' => 'server',
        'host' => '127.0.0.1',
        'port' => 6380,
        'on' => array(
            'connect' => 'onConnect',
            'message' => 'onMessage',
        ),
        'emit' => array(
            'waitor' => 'onWaitor',
        ),
        'after' => array(
            'onAfter',
        ),
    ),
    array(
        'codec' => 'cookpan001\Listener\Codec\MessagePack',
        'class' => 'cookpan001\Listener\Bussiness\MessageQueue\Waitor',
        'name' => 'waitor',
        'role' => 'client',
        'host' => '127.0.0.1',
        'port' => 5380,
        'on' => array(
            'connect' => 'onConnect',
            'message' => 'onMessage',
        ),
        'emit' => array(
            //'acceptor' => 'onAcceptor',
        ),
        'after' => array(
            'onAfter',
        ),
    ),
);
$app = new cookpan001\Listener\Initializer();
$app->init($config);
$app->start();
