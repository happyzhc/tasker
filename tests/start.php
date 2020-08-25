<?php
namespace tests;
require_once dirname(__FILE__).'/../vendor/autoload.php';
require_once dirname(__FILE__).'/Demo.php';
use task\Task;
Task::run([
    //传入配置
//    'queue_type'=>'1212'
]);