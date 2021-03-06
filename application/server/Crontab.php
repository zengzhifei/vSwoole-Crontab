<?php
// +----------------------------------------------------------------------+
// | VSwoole FrameWork                                                    |
// +----------------------------------------------------------------------+
// | Not Decline To Shoulder a Responsibility                             |
// +----------------------------------------------------------------------+
// | zengzhifei@outlook.com                                               |
// +----------------------------------------------------------------------+

namespace vSwoole\application\server;


use vSwoole\application\server\logic\CrontabLogic;
use vSwoole\core\server\CrontabServer;
use vSwoole\library\common\cache\Redis;
use vSwoole\library\common\cache\Table;
use vSwoole\library\common\Config;
use vSwoole\library\common\Inotify;
use vSwoole\library\common\Process;
use vSwoole\library\common\Task;
use vSwoole\library\common\Utils;

class Crontab extends CrontabServer
{
    /**
     * 启动服务器
     * @param array $connectOptions
     * @param array $configOptions
     * @throws \ReflectionException
     */
    public function __construct(array $connectOptions = [], array $configOptions = [])
    {
        //创建任务池内存表
        $GLOBALS['task_table'] = new Table(102400);
        $GLOBALS['task_table']->create([
            'task_cmd'            => ['string', 32],
            'task_url'            => ['string', 128],
            'task_time'           => ['string', 128],
            'task_process_num'    => ['int', 4],
            'task_concurrent_num' => ['int', 8],
        ]);

        parent::__construct($connectOptions, $configOptions);
    }

    /**
     * 主进程启动回调函数
     * @param \swoole_server $server
     * @throws \Exception
     */
    public function onStart(\swoole_server $server)
    {
        parent::onStart($server); // TODO: Change the autogenerated stub

        //写入服务器IP到缓存
        $redis = Redis::getInstance(Config::loadConfig('redis')->get('redis_master'));
        $redis->sAdd(Config::loadConfig('redis')->get('redis_key.Crontab.Server_Ip'), Utils::getServerIp());
    }

    /**
     * 管理进程启动回调函数
     * @param \swoole_server $server
     */
    public function onManagerStart(\swoole_server $server)
    {
        parent::onManagerStart($server); // TODO: Change the autogenerated stub

        //DEBUG模式下，监听文件变化自动重启
        if (Config::loadConfig('config', true)->get('is_debug')) {
            Process::getInstance()->add(function ($process) use ($server) {
                $process->name(VSWOOLE_CRONTAB_SERVER . ' inotify');
                Inotify::getInstance()->watch([VSWOOLE_CONFIG_PATH, VSWOOLE_APP_SERVER_PATH . 'logic/CrontabLogic.php'], function () use ($server) {
                    $server->reload();
                });
            });
            Process::signalProcess(false);
        }
    }

    /**
     * 工作进程启动回调函数
     * @param \swoole_server $server
     * @param int $worker_id
     * @throws \ReflectionException
     */
    public function onWorkerStart(\swoole_server $server, int $worker_id)
    {
        parent::onWorkerStart($server, $worker_id); // TODO: Change the autogenerated stub

        //引入计划任务逻辑类
        $this->logic = new CrontabLogic($server);
    }

    /**
     * 接收客户端数据回调函数
     * @param \swoole_server $server
     * @param int $fd
     * @param int $reactor_id
     * @param string $data
     * @throws \ReflectionException
     */
    public function onReceive(\swoole_server $server, int $fd, int $reactor_id, string $data)
    {
        parent::onReceive($server, $fd, $reactor_id, $data); // TODO: Change the autogenerated stub

        //异步处理任务
        Task::task($server, [$this->logic, 'receive'], [$fd, $data]);
    }

    /**
     * 工作进程接收管道消息回调函数
     * @param \swoole_server $server
     * @param int $src_worker_id
     * @param $data\
     */
    public function onPipeMessage(\swoole_server $server, int $src_worker_id, $data)
    {
        parent::onPipeMessage($server, $src_worker_id, $data); // TODO: Change the autogenerated stub

        //执行计划任务
        $this->logic->execute($data);
    }

    /**
     * 异步任务执行回调函数
     * @param \swoole_server $server
     * @param int $task_id
     * @param int $src_worker_id
     * @param $data
     */
    public function onTask(\swoole_server $server, int $task_id, int $src_worker_id, $data)
    {
        parent::onTask($server, $task_id, $src_worker_id, $data); // TODO: Change the autogenerated stub

        //执行异步任务处理
        Task::execute($server, $data);
    }

    /**
     * 异步任务执行完成回调函数
     * @param \swoole_server $server
     * @param int $task_id
     * @param $data
     */
    public function onFinish(\swoole_server $server, int $task_id, $data)
    {
        parent::onFinish($server, $task_id, $data); // TODO: Change the autogenerated stub

        //执行异步任务完成回调
        Task::finish($data);
    }

    /**
     * 主进程结束回调函数
     * @param \swoole_server $server
     * @throws \Exception
     */
    public function onShutdown(\swoole_server $server)
    {
        parent::onShutdown($server); // TODO: Change the autogenerated stub

        //删除缓存服务器IP
        $redis = Redis::getInstance(Config::loadConfig('redis')->get('redis_master'));
        $redis->sRem(Config::loadConfig('redis')->get('redis_key.Crontab.Server_Ip'), Utils::getServerIp());
    }
}