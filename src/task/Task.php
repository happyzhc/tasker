<?php


namespace task;


use task\exception\Exception;

class Task
{
    const VERSION='1.0';


    /**
     * pid文件.
     */
    static $cfg=null;


    private static $is_cli=false;

    /**
     * master进程pid.
     */
    protected static $_masterPid;

    /**
     * worker进程pid.
     */
    protected static $_workers = array();

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
     * 保存master进程pid以实现stop和reload
     */
    protected static function saveMasterPid()
    {
        // 保存pid以实现重载和停止
        self::$_masterPid = posix_getpid();
        if (false === file_put_contents(self::$cfg['pid_path'], self::$_masterPid)) {
            Console::display("can not save pid to" . self::$cfg['pid_path'] );
        }
    }

    /**
     * 解析命令参数.
     */
    protected static function parseCmd()
    {
        global $argv;
        $command = isset($argv[1]) ? $argv[1] : '';

        // 获取master的pid和存活状态
        $masterPid = is_file(self::$cfg['pid_path']) ? file_get_contents(self::$cfg['pid_path']) : 0;
        $masterAlive = $masterPid ? self::isAlive($masterPid) : false;

        if ($masterAlive) {
            if ($command === 'start') {
                Console::display('Task already running at '.$masterPid);
            }
        } else {
            if ($command && $command !== 'start' && $command !== 'status') {
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

                $timeout = self::$cfg['stop_worker_timeout']+1;
                $startTime = time();
                while (self::isAlive($masterPid)) {
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

    /**
     * master进程监控worker.
     */
    protected static function monitor()
    {
        while (1) {
            // 挂起当前进程的执行直到一个子进程退出或接收到一个信号
            $status = 0;
            $pid = pcntl_wait($status, WNOHANG);//WNOHANG 不阻塞
            pcntl_signal_dispatch();
            if ($pid > 0) {
                // worker健康检查
                self::checkWorkerAlive();
            }
            elseif($pid<0){
                break;
            }
            else{
                //不阻塞的时候 没有子进程退出要做什么写这里
                //扫描监听目录变化 重启worker
            }
            // 其他你想监控的
        }
    }

    /**
     * worker健康检查,防止worker异常退出
     */
    protected static function checkWorkerAlive()
    {
        $allWorkerPid = self::$_workers;
        foreach ($allWorkerPid as $index => $pid) {
            if (!self::isAlive($pid)) {
                unset(self::$_workers[$index]);
            }
        }

        self::forkWorkers();
    }
    /**
     * 设置进程名.
     *
     * @param string $title 进程名.
     */
    protected static function setProcessTitle($title)
    {
        if (extension_loaded('proctitle') && function_exists('setproctitle')) {
            @setproctitle($title);
        } elseif (version_compare(phpversion(), "5.5", "ge") && function_exists('cli_set_process_title')) {
            @cli_set_process_title($title);
        }
    }

    /**
     * 关闭标准输出和错误输出.
     */
    protected static function resetStdFd()
    {
        global $STDERR, $STDOUT;

        //重定向标准输出和错误输出
        @fclose(STDOUT);
        fclose(STDERR);
        $STDOUT = fopen(self::$cfg['stdout_path'], 'a');
        $STDERR = fopen(self::$cfg['stdout_path'], 'a');
    }

    /**
     * 创建所有worker进程.
     */
    protected static function forkWorkers()
    {
        while (count(self::$_workers) < self::$cfg['worker_nums']) {
            self::forkOneWorker();
        }
    }

    /**
     * 创建一个worker进程.
     * @throws Exception
     */
    protected static function forkOneWorker()
    {
        echo "\nmaster 准备创建新worker进程";
        $pid = pcntl_fork();
        // 父进程
        if ($pid > 0) {
            self::$_workers[] = $pid;
            echo "\n成功创建子进程".$pid;
        } else if ($pid === 0) { // 子进程
            self::setProcessTitle(self::$cfg['worker_title']);
            // 子进程会阻塞在这里
            self::workerRun();

            // 子进程退出
            exit(0);
        } else {
            if(!empty(self::$_workers))
            {
                self::stopAllWorkers();
            }
            throw new Exception("fork one worker fail");
        }
    }

    /**
     * 初始化.
     */
    protected static function init()
    {
        self::setProcessTitle(self::$cfg['master_title']);
    }

    /**
     * 安装信号处理器.
     */
    protected static function installSignal()
    {
        // SIGINT
        pcntl_signal(SIGINT, array(Task::class, 'signalHandler'), false);
        // SIGTERM
        pcntl_signal(SIGTERM, array(Task::class, 'signalHandler'), false);

        // SIGUSR1
        pcntl_signal(SIGUSR1, array(Task::class, 'signalHandler'), false);
        // SIGQUIT
        pcntl_signal(SIGQUIT, array(Task::class, 'signalHandler'), false);

        // 忽略信号
        pcntl_signal(SIGUSR2, SIG_IGN, false);
        pcntl_signal(SIGHUP, SIG_IGN, false);
        pcntl_signal(SIGPIPE, SIG_IGN, false);
    }

    /**
     * 信号处理器.
     *
     * @param integer $signal 信号.
     */
    protected static function signalHandler($signal)
    {
        switch ($signal) {
            case SIGINT:
            case SIGTERM:
                self::stop();
                break;
            case SIGQUIT:
            case SIGUSR1:
                self::reload();
                break;
            default:
                break;
        }
    }

    /**
     * 获取所有worker进程pid.
     *
     * @return array
     */
    protected static function getAllWorkerPid()
    {
        return array_values(self::$_workers);
    }

    /**
     * 强制kill掉一个进程.
     *
     * @param integer $pid 进程pid.
     */
    protected static function forceKill($pid)
    {
        // 进程是否存在
        if (self::isAlive($pid)) {
            posix_kill($pid, SIGKILL);
        }
    }

    /**
     * 进程是否存活.
     *
     * @param mixed $pids 进程pid.
     *
     * @return bool
     */
    protected static function isAlive($pids)
    {
        if (!is_array($pids)) {
            $pids = array($pids);
        }

        foreach ($pids as $pid) {
            if (posix_kill($pid, 0)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 停止所有worker进程.
     */
    protected static function stopAllWorkers()
    {
        $allWorkerPid = self::getAllWorkerPid();
        foreach ($allWorkerPid as $workerPid) {
            posix_kill($workerPid, SIGINT);
        }
        $timeout=self::$cfg['stop_worker_timeout'];
        $status=0;
        $start_time=time();
        while (self::isAlive($allWorkerPid))
        {
            usleep(1000);
            //这里不检查posix_kill获取不到真是值
            pcntl_wait($status, WNOHANG);
            if(time()-$start_time>$timeout)
            {
                // 子进程退出异常,强制kill
                foreach ($allWorkerPid as $workerPid) {
                    self::forceKill($workerPid);
                }
                break;
            }
        }
        // 清空worker实例
        self::$_workers = array();
    }

    /**
     * 停止.
     */
    protected static function stop()
    {

        // 主进程给所有子进程发送退出信号

        if (self::$_masterPid === posix_getpid()) {
            self::stopAllWorkers();
            if (is_file(self::$cfg['pid_path'])) {
                @unlink(self::$cfg['pid_path']);
            }
            exit(0);
        } else { // 子进程退出
            // 退出前可以做一些事
            exit(0);
        }
    }

    /**
     * 重新加载.
     */
    protected static function reload()
    {
        // 停止所有worker即可,master会自动fork新worker
        self::stopAllWorkers();
        self::checkWorkerAlive();
    }

    /**
     * worker进程任务.
     */
    private static function workerRun()
    {
        // 模拟调度,实际用event实现
        while (1) {
            // 捕获信号
            pcntl_signal_dispatch();
            $job_cfg=self::$cfg[self::$cfg['queue_type']];
            $job_cfg['queue_type']=self::$cfg['queue_type'];
            $job_cfg['retry_count']=self::$cfg['retry_count'];
            call_user_func([Queue::class,'listen'],$job_cfg);
        }
    }
    protected static function parseCfg($cfg){
        self::$cfg=require dirname(__FILE__).'/../config.php';
        $cfg_key=array_keys(self::$cfg);
        foreach ($cfg_key as $key)
        {
            if(!empty($cfg[$key]))
            {
                self::$cfg[$key]=$cfg[$key];
            }
        }
    }

    /**
     * 检查一些关键配置
     * @return void
     * @throws Exception
     */
    protected static function checkCfg(){
        if(self::$cfg['worker_nums']<=0)
        {
            if(!self::$is_cli)
            {
                throw new Exception('worker_nums value invalid');
            }
            Console::display('worker_nums value invalid');
        }
        if(self::$cfg['queue_type']=='database')
        {
            //todo 尝试链接
            //todo 检查表存在 不存在则尝试自动创建
            //todo 检查表字段
        }
        elseif(self::$cfg['queue_type']=='redis')
        {
            //todo 尝试链接
        }
        else
        {
            if(!self::$is_cli)
            {
                throw new Exception('queue_type value invalid,eg [\'database\',\'redis\']');
            }
            Console::display('queue_type value invalid,eg [\'database\',\'redis\']');
        }
    }


    /**
     * 启动.
     * @param $cfg
     */
    public static function run($cfg)
    {
        self::checkEnv();
        self::cfg($cfg);
        self::init();

        self::parseCmd();
        self::daemonize();
        self::saveMasterPid();

        self::installSignal();
        self::forkWorkers();

//        self::resetStdFd();
        self::monitor();
    }

    /**
     * 添加任务
     * @param array $job_opt 数组 [payload[class,method,data],doat]
     * @param array $cfg
     * @throws Exception
     */
    public static function push($job_opt=[],$cfg=[]){
        if($cfg) {
            //输入配置
            self::cfg($cfg);
        }
        if(empty(self::$cfg))
        {
            throw new Exception('需初始化配置');
        }
        $job_cfg=self::$cfg[self::$cfg['queue_type']];
        $job_cfg['queue_type']=self::$cfg['queue_type'];
        $job_cfg['retry_count']=self::$cfg['retry_count'];
        Queue::getInstance($job_cfg)->add(...$job_opt);
    }
    //外部注入配置
    public static function cfg($cfg){
        self::parseCfg($cfg);
        self::checkCfg();
    }

}