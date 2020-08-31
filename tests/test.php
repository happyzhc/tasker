<?php


$pid = pcntl_fork();
if (-1 === $pid) {
} elseif ($pid > 0) {
    exit(0);
}
//  子进程了
// 将当前进程提升为会话leader
if (-1 === posix_setsid()) {
    exit("process setsid fail\n");
}
// 再次fork以避免SVR4这种系统终端再一次获取到进程控制
$pid = pcntl_fork();
if (-1 === $pid) {
    exit("process fork fail\n");
} elseif (0 !== $pid) {
    exit(0);
}
umask(0);
chdir('/');



(new test_master())->run();


class test_child{
    public function __construct()
    {
        echo "Child process id = " . posix_getpid() . PHP_EOL;
        pcntl_signal(SIGINT, [$this,'signal'] , false);
    }
    protected function signal($a){

        echo "子进程注册的信号\n";
        exit;
    }
    public function run(){
        while (true) {//死循环 执行任务
            sleep(1);
            if(mt_rand(0,99)>80)
            {
                exit(0);
            }
            pcntl_signal_dispatch();
        }
    }
}
class test_master{
    protected function fork() {//定义一个fork子进程函数

            $pid = pcntl_fork();//fork 一个子进程

            switch ($pid) {
                case -1:
                    die('Create failed');
                    break;
                case 0:
                    // Child


                    (new test_child())->run();


                    break;
                default:
                    // Parent

                    $this->childs[$pid] = $pid;//主进程 记录子进程的进程id
                    break;
            }
    }
    protected $childs=[];

    public function run(){

        echo "Master process id = " . posix_getpid() . PHP_EOL;
// SIGINT
        pcntl_signal(SIGINT, function ($a){
            echo "收到信号\n";
            posix_kill(0,SIGINT);
            exit;
        }, false);
        $count = 1;//fork

        for ($i = 0; $i < $count; $i++) {
            $this->fork();
        }

        while ( count($this->childs) ) {//监控
            if ( ($exit_id = pcntl_wait($status,WNOHANG)) > 0 ) {//如果有子进程意外中断了
                echo "Child({$exit_id}) exited.\n";
                echo "中断子进程的信号值是 " . pcntl_wtermsig($status) . PHP_EOL;//输出中断的信号量
                unset($this->childs[$exit_id]);//把中断的子进程的进程id 剔除掉
            }

            if ( count($this->childs) < $count ) {//如果子进程的进程数量小于规定的数量
                $this->fork();//重新开辟一个子进程
            }
            usleep(100000);
            pcntl_signal_dispatch();
        }

        echo "Done\n";
    }
}