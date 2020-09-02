<?php


namespace tasker;


use tasker\exception\Exception;
use tasker\process\Master;
use tasker\process\Worker;

class Tasker
{
    const VERSION='1.0.0';
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
        global $argv,$argc;
        $usage = "
Usage: Commands \n\n
Commands:\n
start\t\tStart worker.\n
restart\t\tStart master.\n
stop\t\tStop worker.\n
reload\t\tReload worker.\n
status\t\tWorker status.\n\n\n
Use \"--help\" for more information about a command.\n";
        if($argc<2)
        {
            Console::display($usage);
        }
        $command = $argv[$argc-1];

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
                $path=dirname($cfg['pid_path']).'/reload.'.$masterPid;
                while (!is_file($path))
                {
                    usleep(100);
                }
                @unlink($path);
                Console::display("Task reload success",false);
                exit(0);

            case 'status':
                posix_kill($masterPid, SIGUSR2);
                $path='/tmp/status.'.$masterPid;
                while (!is_file($path))
                {
                    usleep(100);
                }
                usleep(100000*$cfg['worker_nums']);
                $status_content=file_get_contents($path);
                @unlink($path);
                $status_array=explode(PHP_EOL,$status_content);
                $text="worker\tpid\truntime\tmemory\t".PHP_EOL;
//                $total_memory=0;
                foreach ($status_array as $index=>$status) {
                    if($status)
                    {
                        $json=json_decode($status,true);
                        $text.=($index+1)."\t".$json['process_id']."\t".$json['runtime']."\t".$json['memory'].PHP_EOL;
//                        $total_memory+=$json['memory'];
                    }
                }
                Console::hearder();
                Console::display($text,false);
                exit(0);

            default:
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
     * @throws \Exception
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

//            var_dump($res);
            //CREATE TABLE `task` (
            //  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            //  `payload` text NOT NULL,
            //  `doat` int(255) unsigned NOT NULL,
            //  `dotimes` int(10) unsigned NOT NULL,
            //  `startat` int(255) unsigned NOT NULL,
            //  `endat` int(255) unsigned NOT NULL,
            //  `exception` text NOT NULL,
            //  PRIMARY KEY (`id`)
            //) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;
            //字段检查

            //检查redis
            Redis::getInstance($cfg['redis'])->ping();

            if(!empty($cfg['hot_update_path']))
            {
                //判断是否支持pclose、popen
            }
        }
        catch (\Exception $e)
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
     * @throws \Exception
     */
    public static function run($cfg=[])
    {
        self::checkEnv();
        self::parseCfg($cfg);
        self::parseCmd($cfg);
        self::checkCfg($cfg);
        self::daemonize();
        self::$cfg=$cfg;
        (new Master($cfg))->run();
    }

    /**
     * 添加任务
     * @param string $class_name 类名
     * @param string $medoth_name 方法名
     * @param array $param 参数
     * @param mixed $doat 时间戳
     */
    public static function push($class_name,$medoth_name,$param=[],$doat=null){

        self::delay($class_name,$medoth_name,$param,$doat);
    }

    /**
     * @param string $class_name 类名
     * @param string $medoth_name 方法名
     * @param array $param 参数
     * @param mixed $doat 时间戳
     */
    public static function delay($class_name,$medoth_name,$param=[],$doat=null){
        if(empty(self::$cfg)) {
            //输入配置
            self::cfg();
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
    public static function cfg($cfg=[]){
        self::parseCfg($cfg);
        self::checkCfg($cfg);
        self::$cfg=$cfg;
    }

}