<?php


namespace tasker;


use tasker\exception\Exception;
use tasker\process\Master;
use tasker\queue\Database;
use tasker\queue\Redis;

class Tasker
{
    const VERSION='1.0.0';
    const IS_DEBUG=false;
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
            Console::header();
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
Usage: Commands 
Commands:\n
start\t\tStart worker.
restart\t\tStart master.
stop\t\tStop worker.
reload\t\tReload worker.
status\t\tWorker status.
\t\t-s speed info
\t\t-t time info
\t\t-c count info
\t\t-m count info

Use \"--help\" for more information about a command.\n";
        $i=1;
        $options=[];
        while($command = $argv[$argc-$i])
        {
            if(substr($command,0,1)!=='-')
            {
                break;
            }
            $options[]=$command;
            $i++;
        }


        if($argc<2 || in_array('--help',$argv) || empty($command))
        {
            Console::header();
            Console::display($usage);
        }


        // 获取master的pid和存活状态
        $masterPid = is_file($cfg['pid_path']) ? file_get_contents($cfg['pid_path']) : 0;
        $masterAlive = $masterPid ? posix_kill($masterPid,0) : false;
        if ($masterAlive) {
            if ($command === 'start') {
                Console::display('Tasker already running at '.$masterPid);
            }
        } else {
            if ($command && $command !== 'start') {
                Console::display('Tasker not run');
            }
        }
        switch ($command) {
            case 'start':
                break;
            case 'stop':
            case 'restart':
                Console::display('Tasker stopping ...',false);
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
                Console::display('Tasker stop success',$command==='stop');
                break;
            case 'reload':
                Console::display('Tasker reloading ...',false);
                // 给master发送reload信号
                posix_kill($masterPid, SIGUSR1);
                $path=dirname($cfg['pid_path']).'/reload.'.$masterPid;
                while (!is_file($path))
                {
                    usleep(100);
                }
                @unlink($path);
                Console::display("Tasker reload success",false);
                exit(0);

            case 'status':
                posix_kill($masterPid, SIGUSR2);
                $path='/tmp/status.'.$masterPid;
                while (!is_file($path))
                {
                    Op::sleep(0.001);
                }
                Op::sleep(0.1*$cfg['worker_nums']);
                $status_content=file_get_contents($path);
                @unlink($path);
                self::displayStatus($status_content,$masterPid);

                exit(0);

            default:
                Console::display($usage);
        }

    }
    protected static function displayStatus($status_content,$masterPid){

        $status_array=explode(PHP_EOL,$status_content);
        $master_status=[];
        $worker_status=[];
        foreach ($status_array as $index=>$status) {
            if($status)
            {
                $decode=unserialize($status);
                if($decode['process_id']==$masterPid)
                {
                    $master_status=$decode;
                }
                else{
                    $worker_status[]=$decode;
                }
            }
        }
        Console::header();
        $master_text="--------------------master info-------------------".PHP_EOL;
        $master_text.="start_time:\t".$master_status['start_time'].PHP_EOL;
        $master_text.="memory:\t\t".$master_status['memory'].PHP_EOL;
        Console::display($master_text,false);
        $worker_text="";
        if(empty($options) || in_array('-s',$options))
        {
            $worker_text.="---------------------speed info-------------------".PHP_EOL;
            $worker_text.="pid\tfast_speed\tslow_speed\tagv_speed".PHP_EOL;
            foreach ($worker_status as $v)
            {
                $worker_text.=$v['process_id']."\t".$v['fast_speed']."\t\t".$v['slow_speed']."\t\t".$v['agv_speed']."".PHP_EOL;
            }
        }
        if(empty($options) || in_array('-t',$options))
        {
            $worker_text.="---------------------time info--------------------".PHP_EOL;
            $worker_text.="pid\truntime\t\tsleep_time\twork_time".PHP_EOL;
            foreach ($worker_status as $v)
            {
                $worker_text.=$v['process_id']."\t".$v['runtime']."\t".$v['sleep_time']."\t".$v['work_time']."".PHP_EOL;
            }
        }
        if(empty($options) || in_array('-c',$options))
        {
            $worker_text.="---------------------count info-------------------".PHP_EOL;
            $worker_text.="pid\tsuccess_count\tfail_count\texcept_count".PHP_EOL;
            foreach ($worker_status as $v)
            {
                $worker_text.=$v['process_id']."\t".$v['success_count']."\t\t".$v['fail_count']."\t\t".$v['except_count']."".PHP_EOL;
            }
        }
        if(empty($options) || in_array('-m',$options))
        {
            $worker_text.="--------------------memory info-------------------".PHP_EOL;
            $worker_text.="pid\tmemory".PHP_EOL;
            foreach ($worker_status as $v)
            {
                $worker_text.=$v['process_id']."\t".$v['memory']."".PHP_EOL;
            }
        }

        Console::display($worker_text,false);
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
        try {
            //pcntl 系列函数判断
            if(!self::functionCheck([
                'posix_kill',
                'posix_getpid',
                'posix_getppid',
                'posix_setsid',
            ]))
            {
                throw new Exception('posix_* functions has been disabled');
            }
            if(!self::functionCheck([
                'pcntl_signal_dispatch',
                'pcntl_signal',
                'pcntl_fork',
                'pcntl_waitpid',
            ]))
            {
                throw new Exception('pcntl_* functions has been disabled');
            }

            if($cfg['worker_nums']<=0)
            {
                throw new Exception('worker_nums value invalid');
            }
            //检查dababase
            $res=Database::getInstance($cfg['database'])->query("SHOW COLUMNS FROM ".$cfg['database']['table']);

            if(!(array_column($res,'Field')===['id','payload','doat','dotimes','startat','endat','exception']))
            {
                throw new Exception('table '.$cfg['database']['table'].' field error');
            }

            //检查redis
            Redis::getInstance($cfg['redis'])->ping();
            if(!empty($cfg['hot_update_path']))
            {
                //判断是否支持pclose、popen
                if(!self::functionCheck('pclose,popen'))
                {
                    throw new Exception('pclose | popen has been disabled');
                }
            }
        }
        catch (\Exception $e)
        {
            if(self::$is_cli)
            {
                Console::display($e->getMessage());
            }
            if($e instanceof Exception)
            {
                throw $e;
            }
            throw new Exception($e->getMessage());
        }
        return $cfg;

    }
    protected static function functionCheck($functions){
        if(!is_array($functions))
        {
            $functions=explode(',',$functions);
        }
        foreach ($functions as $function)
        {
            if(!function_exists($function))
            {
                return false;
            }
        }
        return true;
    }


    /**
     * 启动.
     * @param $cfg
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
     * @param array $param 参数 ...模式传入
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