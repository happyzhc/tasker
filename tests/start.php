<?php
namespace tests;
require_once dirname(__FILE__).'/../vendor/autoload.php';
require_once dirname(__FILE__).'/Demo.php';
use tasker\Tasker;
Tasker::run([
    //传入配置
    'redis'=>[
        'host'=>'127.0.0.1',
        'port'=>6379,
        'db'=>8,
        'pwd'=>'ljk2fxf',
        'queue_key'=>'task'
    ]
]);