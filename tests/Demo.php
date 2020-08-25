<?php
namespace tests;
use task\Task;

class Demo{
    public function test(...$data){
        $payload=[
            addslashes(self::class),
            'test',
            $data
        ];
//        echo 'start';
        Task::push([
            $payload
        ]);
//        Task::push([
//            $payload
//        ]);
//        Task::push([
//            $payload
//        ]);
//        Task::push([
//            $payload
//        ]);
//        Task::push([
//            $payload
//        ]);
//        sleep(2);
//        echo 'end';
//        return false;
    }
}
