<?php


namespace tasker\process;


use tasker\Console;
use tasker\Op;
use tasker\process\master\HotUpdate;
use tasker\queue\Database;
use tasker\process\master\Provider;
use tasker\queue\Redis;
use tasker\Tasker;
use tasker\traits\Singleton;

class Master extends Process
{
    use Singleton;
    protected $cfg;
    protected $_workers=[];
    private $_status=[];
    public function __construct($cfg)
    {
        $this->_status['start_memory']=memory_get_usage();
        $this->_status['start_time']=Op::microtime();
        $this->cfg=$cfg;
        $this->setProcessTitle($this->cfg['master_title']);
        $this->_process_id = posix_getpid();
    }
    protected function saveMasterPid()
    {
        // 保存pid以实现重载和停止
        if (false === file_put_contents($this->cfg['pid_path'], $this->_process_id)) {
            Console::display('can not save pid to'.$this->cfg['pid_path']);
        }
    }
    //安装信号
    protected function installSignal(){
        // SIGINT
        pcntl_signal(SIGINT, array($this, 'signalHandler'), false);
        // SIGTERM
        pcntl_signal(SIGTERM, array($this, 'signalHandler'), false);
        // SIGUSR1
        pcntl_signal(SIGUSR1, array($this, 'signalHandler'), false);
        // SIGQUIT
        pcntl_signal(SIGQUIT, array($this, 'signalHandler'), false);

        // SIGUSR2
        pcntl_signal(SIGUSR2, array($this, 'signalHandler'), false);
        // 忽略信号
        pcntl_signal(SIGHUP, SIG_IGN, false);
        pcntl_signal(SIGPIPE, SIG_IGN, false);
    }

    /**
     * 信号处理器.
     *
     * @param integer $signal 信号.
     */
    protected function signalHandler($signal)
    {
        switch ($signal) {
            case SIGINT:
            case SIGTERM:
                $this->stop();
                break;
            case SIGQUIT:
            case SIGUSR1:
                $this->reload();
                break;
            case SIGUSR2:
                $this->status();
                break;
            default:
                break;
        }
    }

    /**
     * 停止.
     */
    protected function stop()
    {
        // 主进程给所有子进程发送退出信号
        $this->stopAllWorkers();
        if (is_file($this->cfg['pid_path'])) {
            @unlink($this->cfg['pid_path']);
        }
        Console::log("master process is stop");
        exit(0);
    }

    /**
     * 重新加载.
     */
    protected function reload()
    {
        // 停止所有worker即可,master会自动fork新worker
        $this->stopAllWorkers();
        file_put_contents(dirname($this->cfg['pid_path']).'/reload.'.$this->_process_id,'1');
    }

    /**
     * 获取状态
     */
    protected function status()
    {
        $process_id=$this->_process_id;
        $start_time=date('Y-m-d H:i:s',$this->_status['start_time']);
        $memory=Op::memory2M( memory_get_usage()-$this->_status['start_memory']);
        //运行了多少时间
        $runtime=Op::dtime(Op::microtime()-$this->_status['start_time']);
        file_put_contents('/tmp/status.'.$this->_process_id,serialize(compact(
                'process_id',
                      'memory',
                         'runtime',
                         'start_time'
            )).PHP_EOL,FILE_APPEND|LOCK_EX);
        $allWorkerPid = $this->_workers;
        foreach ($allWorkerPid as $workerPid) {
            posix_kill($workerPid, SIGUSR1);
        }


    }

    /**
     * 创建所有worker进程.
     */
    protected function forkWorkers()
    {
        while (count($this->_workers) < $this->cfg['worker_nums']) {
            $this->forkOneWorker();
        }
    }

    /**
     * 创建一个worker进程.
     */
    protected function forkOneWorker()
    {
        //创建进程之前释放
        Database::free();
        Redis::free();
        $pid = pcntl_fork();
        // 父进程
        if ($pid > 0) {
            $this->_workers[$pid] = $pid;
        } else if ($pid === 0) { // 子进程
            // 子进程会阻塞在这里
            (new Worker($this->cfg))->run();
            // 子进程退出
            exit(0);
        } else {
            if(!empty($this->_workers))
            {
                $this->stopAllWorkers();
            }
//            throw new Exception("fork one worker fail");
        }
    }


    /**
     * 停止所有worker进程.
     */
    protected function stopAllWorkers()
    {
        foreach ($this->_workers as $workerPid) {
            posix_kill($workerPid, SIGINT);
        }
        $timeout=$this->cfg['stop_worker_timeout'];
        $start_time=time();
        while ($this->isAlive($this->_workers))
        {
            usleep(1000);
            if(time()-$start_time>$timeout)
            {
                // 子进程退出异常,强制kill
                foreach ($this->_workers as $workerPid) {
                    $this->forceKill($workerPid);
                }
                break;
            }
        }
        // 清空worker实例
        $this->_workers= [];
    }
    protected function forceKill($pid)
    {
        // 进程是否存在
        if ($this->isAlive($pid)) {
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
    protected function isAlive($pids)
    {
        if (!is_array($pids)) {
            $pids = array($pids);
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid,$status,WNOHANG);
            if (posix_kill($pid, 0)) {
                return true;
            }
        }

        return false;
    }


    /**
     * 关闭标准输出和错误输出.
     */
    protected function resetStdFd()
    {
        if(Tasker::IS_DEBUG)
        {
            return;
        }

        global $argv,$STDOUT, $STDERR;
        $stdout_path=is_null($this->cfg['stdout_path'])?
            dirname(is_file($argv[0])?$argv[0]:$_SERVER['PWD'].'/'.$argv[0]).'/tasker.log':
            $this->cfg['stdout_path'];
        $handle = fopen($stdout_path, "a");
        if ($handle) {
            unset($handle);
            set_error_handler(function(){});
            fclose($STDOUT);
            fclose($STDERR);
            fclose(STDOUT);
            fclose(STDERR);
            $STDOUT = fopen($stdout_path, "a");
            $STDERR = fopen($stdout_path, "a");
            restore_error_handler();
        }
        //这咯写入才会记录到日志
        Console::log("master ".$this->_process_id." start success");

    }
    /**
     * master进程监控worker.
     */
    protected function monitor()
    {

        while (1) {
            // 挂起当前进程的执行直到一个子进程退出或接收到一个信号
            if (($pid = pcntl_wait($status, WNOHANG)) > 0) {
                Console::log("catch worker $pid stop and restart it right now");
                unset($this->_workers[$pid]);//把中断的子进程的进程id 剔除掉
            }
            $this->forkWorkers();
            try{
                //读取任务丢到list里
                Provider::moveToList($this->cfg);
                //扫描监听目录变化 重启master
                if(HotUpdate::check($this->cfg['hot_update_path'],$this->cfg['hot_update_interval'])){
                    $this->hotUpdate();
                }
            }catch (\Exception $e)
            {
                echo  $e->getMessage();
            }
            Op::sleep(0.1);
            pcntl_signal_dispatch();
        }
    }
    protected function hotUpdate(){
        Database::free();
        Redis::free();
        $pid = pcntl_fork();
        // 父进程
        if ($pid > 0) {
            //等待
        } else if ($pid === 0) { // 重启子进程
            //发送结束信号
            $this->setProcessTitle('task_hot_update');
            posix_kill($this->_process_id,SIGINT);
            $timeout=5;
            $stime=time();
            while(posix_kill($this->_process_id,0) && time()-$stime<$timeout);
            if(posix_kill($this->_process_id,0))
            {
                Console::display('stop fail');
                exit(0);
            }
            global $argv;
            if(!is_file($argv[0]))
            {
                $cmd='php '.$_SERVER['PWD'].'/'.join(' ',$argv);
            }
            else{
                $cmd='php '.join(' ',$argv);
            }
            pclose(popen($cmd,'r'));
            exit(0);
        } else {
            //创建子进程失败
        }
    }
    public function run(){

        $this->saveMasterPid();
        $this->resetStdFd();
        $this->forkWorkers();

        $this->installSignal();

        $this->monitor();
    }
}