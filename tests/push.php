<?php
namespace tests;
require_once dirname(__FILE__).'/../vendor/autoload.php';
require_once dirname(__FILE__).'/Demo.php';
use tasker\Tasker;
//Tasker::cfg();
Tasker::push(Demo::class,'test');