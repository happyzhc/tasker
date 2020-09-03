<?php
namespace gc;
use tasker\Tasker;

$cfg=[
    //传入配置

    'worker_nums'=>1,
    'redis'=>[
        'host'=>'127.0.0.1',
        'port'=>6379,
        'db'=>8,
        'pwd'=>'ljk2fxf',
        'queue_key'=>'task'
    ],
    'database'=>[
        'host'=>'127.0.0.1',
        'db'=>'task',
        'user'=>'task',
        'pwd'=>'123456',
        'port'=>3306,
        'table'=>'task',
        'charset'=>'utf8'
    ],
];
require_once dirname(__FILE__).'/../vendor/autoload.php';
class ClassA {
    public function test($cfg){
        $obj=new ClassB;
        echo $obj->getVal()."\n";
        Tasker::cfg($cfg);
        Tasker::push(__CLASS__,'test',[$cfg]);

    }
}
class ClassB{
    private $val;
    public function getVal(){
        if(empty($this->val))
        {
            $this->val=mt_rand(1000,9999);
        }
        return $this->val;
    }
}
Tasker::run($cfg);
