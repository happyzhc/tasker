<?php


namespace tasker;


use tasker\exception\Exception;
use tasker\process\Master;
use tasker\process\Worker;

class Tasker
{
    const VERSION='1.0';
    const IS_DEBUG=true;
    protected static $is_cli=false;

    /**
     * 守护态运行.
     */
    protected static function daemonize()
    {
        $pid = pcntl_fork();
        if (-1 === $pid) {
            Console::display('process fork fail');
        } elseif ($pid > 0) {
            Console::hearder();
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
    }


    /**
     * 解析命令参数.
     * @param $cfg
     */
    protected static function parseCmd($cfg)
    {
        global $argv;
        $command = isset($argv[1]) ? $argv[1] : '';

        // 获取master的pid和存活状态
        $masterPid = is_file($cfg['pid_path']) ? file_get_contents($cfg['pid_path']) : 0;
        $masterAlive = $masterPid ? posix_kill($masterPid,0) : false;
        if ($masterAlive) {
            if ($command === 'start') {
                Console::display('Task already running at '.$masterPid);
            }
        } else {
            if ($command && $command !== 'start') {
                Console::display('Task not run');
            }
        }
        switch ($command) {
            case 'start':
                break;
            case 'stop':
            case 'restart':
                Console::display('Task stopping ...',false);
                // 给master发送stop信号
                posix_kill($masterPid, SIGINT);

                $timeout = $cfg['stop_worker_timeout']+3;
                $startTime = time();
                while (posix_kill($masterPid,0)) {
                    usleep(1000);
                    if (time() - $startTime >= $timeout) {
                        Console::display('Task stop fail');
                    }
                }
                Console::display('Task stop success',$command==='stop');
                break;
            case 'reload':
                Console::display('Task reloading ...',false);
                // 给master发送reload信号
                posix_kill($masterPid, SIGUSR1);
//                pcntl_signal(SIGINT,function ($signal){
//                    Console::display('Task reload success',false);
//                },false);
//                while(1)
//                {
//                    pcntl_signal_dispatch();
//                }
                exit(0);

            case 'status':
                posix_kill($masterPid, SIGUSR2);
                $path=dirname($cfg['pid_path']).'/status.'.$masterPid;
                while (!is_file($path))
                {
                    usleep(100);
                }
                usleep(100000*$cfg['worker_nums']);
                $status_content=file_get_contents($path);
                @unlink($path);
                $status_array=explode(PHP_EOL,$status_content);
                $text="worker\truntime\tmemory\t".PHP_EOL;
//                $total_memory=0;
                foreach ($status_array as $index=>$status) {
                    if($status)
                    {
                        $json=json_decode($status,true);
                        $text.=($index+1)."\t".$json['runtime']."\t".$json['memory'].PHP_EOL;
//                        $total_memory+=$json['memory'];
                    }
                }
                Console::hearder();
                Console::display($text,false);
                exit(0);

            default:
                $usage = "
Usage: Commands \n\n
Commands:\n
start\t\tStart worker.\n
stop\t\tStop worker.\n
reload\t\tReload codes.\n
status\t\tWorker status.\n\n\n
Use \"--help\" for more information about a command.\n";
                Console::display($usage);
        }

    }

    /**
     * 环境检测.
     * @return void
     * @throws Exception
     */
    protected static function checkEnv()
    {
        // 只能运行在cli模式
        if (php_sapi_name() != "cli") {
            throw new Exception('Task only run in command line mode');
        }
        self::$is_cli=true;
        
    }

    protected static function parseCfg(&$cfg){
        $task_cfg=require dirname(__FILE__).'/../config.php';
        $cfg_key=array_keys($task_cfg);
        foreach ($cfg_key as $key)
        {
            if(!empty($cfg[$key]))
            {
                $task_cfg[$key]=$cfg[$key];
            }
        }
        $cfg=$task_cfg;
    }

    /**
     * 检查一些关键配置
     * @param $cfg
     * @return array
     * @throws Exception
     */
    protected static function checkCfg($cfg){
        if($cfg['worker_nums']<=0)
        {
            if(!self::$is_cli)
            {
                throw new Exception('worker_nums value invalid');
            }
            Console::display('worker_nums value invalid');
        }
        try {
            //检查dababase
            $res=Database::getInstance($cfg['database'])->query("SHOW COLUMNS FROM ".$cfg['database']['table']);
            //字段检查

            //检查redis
            Redis::getInstance($cfg['redis'])->ping();
        }
        catch (\Throwable $e)
        {
            if($e instanceof Exception)
            {
                throw $e;
            }
            throw new Exception($e->getMessage());
        }
        return $cfg;

    }


    /**
     * 启动.
     * @param $cfg
     * @throws Exception
     */
    public static function run($cfg)
    {
        self::checkEnv();
        self::parseCfg($cfg);
        self::parseCmd($cfg);
        self::checkCfg($cfg);
        self::daemonize();
        (new Master($cfg))->run();
    }

    /**
     * 添加任务
     * @param array $payload
     */
    public static function push(...$payload){

        self::delay(...$payload);
    }
    public static function delay($class_name,$medoth_name,$param,$doat=null){
        if(empty(self::$cfg)) {
            //输入配置
            self::cfg($cfg);
        }
        $cfg=self::$cfg;
        $payload=[
            $class_name,
            $medoth_name,
            $param
        ];
        $doat=$doat?:time();
        $sql='INSERT INTO '.$cfg['database']['table'].
            '(payload,doat) VALUES("'.addslashes(json_encode($payload)).'",'.$doat.')';
//        var_dump($sql);
        Database::getInstance(self::$cfg['database'])->exce($sql);
    }
    private static $cfg;
    //外部注入配置
    public static function cfg(&$cfg){
        self::parseCfg($cfg);
        self::checkCfg($cfg);
        self::$cfg=$cfg;
    }

}

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


                (new Worker([]))->run();


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
            if ( ($exit_id = pcntl_wait($status)) > 0 ) {//如果有子进程意外中断了
                echo "Child({$exit_id}) exited.\n";
                echo "中断子进程的信号值是 " . pcntl_wtermsig($status) . PHP_EOL;//输出中断的信号量
                unset($this->childs[$exit_id]);//把中断的子进程的进程id 剔除掉
            }

            if ( count($this->childs) < $count ) {//如果子进程的进程数量小于规定的数量
                $this->fork();//重新开辟一个子进程
            }
            pcntl_signal_dispatch();
        }

        echo "Done\n";
    }
}