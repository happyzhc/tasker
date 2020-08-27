<?php
namespace tests;
use tasker\Tasker;

class Demo{
    public function test(...$data){
//        echo 'start'."\n";
        $stime=time();
        $payload=[
            addslashes(self::class),
            'test',
            $data,
        ];
        Tasker::push([
            $payload,time()
        ]);
        Tasker::push([
            $payload,time()
        ]);
        Tasker::push([
            $payload,time()
        ]);
//        Task::push([
//            $payload
//        ]);
//        Task::push([
//            $payload
//        ]);
//        sleep(3);
//        exit;
//        sleep(4);
//       echo  file_get_contents('http://vcode.9xy.cn/test.php')."\n";
//        echo 'end'.(time()-$stime)."\n";
//        return false;
    }
}
