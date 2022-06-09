<?php

// Powered and changes made by Spywer

namespace swoole\foundation\web;

use Yii;
use yii\base\BaseObject;
use yii\base\InvalidConfigException;
use immusen\websocket\src\rpc\Response;
use immusen\websocket\src\rpc\Exception;
use immusen\websocket\src\rpc\Request;
use immusen\websocket\src\Task;

/**
 * Web服务器
 * Class WebSocketServer
 * @package app\servers
 */
class WebSocketServer extends BaseObject
{
    /**
     * @var string 监听主机
     */
    public $host = 'localhost';
    /**
     * @var int 监听端口
     */
    public $port = 9502;
    /**
     * @var int 进程模型
     */
    public $mode = SWOOLE_PROCESS;
    /**
     * @var int SOCKET类型
     */
    public $sockType = SWOOLE_SOCK_TCP;
    /**
     * @var array 服务器选项
     */
    public $options = [
        'worker_num' => 2,
        'daemonize' => 0,
        'task_worker_num' => 2
    ];
    /**
     * @var array 应用配置
     */
    public $app = [];
    /**
     * @var \Swoole\WebSocket\Server swoole server实例
     */
    public $server;

    /**
     * @inheritDoc
     * @throws InvalidConfigException
     */
    public function init()
    {
        parent::init();
        if (empty($this->app)) {
            throw new InvalidConfigException('The WebSocket "app" property mus be set.');
        }

        if (!$this->server instanceof \Swoole\WebSocket\Server) {
            $this->server = new \Swoole\WebSocket\Server($this->host, $this->port, $this->mode, $this->sockType);
            $this->server->set($this->options);
        }

        foreach ($this->events() as $event => $callback) {
            $this->server->on($event, $callback);
        }
    }

    /**
     * @return array
     */
    public function events()
    {
        return [
            'start' => [$this, 'onStart'], // +
            'workerStart' => [$this, 'onWorkerStart'], // +
            'workerError' => [$this, 'onWorkerError'],
            'workerStop' => [$this, 'onWorkerStop'],
            'workerExit' => [$this, 'onWorkerExit'],
            'open' => [$this, 'onOpen'], // +
            'request' => [$this, 'onRequest'], // +
            'message' => [$this, 'onMessage'], // +
            'task' => [$this, 'onTask'], // +
            'managerStart' => [$this, 'onManagerStart'],
            'disconnect' => [$this, 'onDisconnect'], // +
            'close' => [$this, 'onClose'], // +
            'finish' => [$this, 'onFinish'] // +
        ];
    }

    /**
     * @return bool
     */
    public function start()
    {
		swoole_set_process_name("Yii2 WebSocket Server: master");
		
        return $this->server->start();
    }
	
	public function onManagerStart()
    {
		swoole_set_process_name("Yii2 WebSocket Server: manager");
    }

    /**
     * @param \Swoole\WebSocket\Server $server
     */
    public function onStart(\Swoole\WebSocket\Server $server)
    {
        echo "Server Start: {$server->master_pid}" . PHP_EOL;

        printf("listen on %s:%d\n", $server->host, $server->port);
    }

    /**
     * @param \Swoole\WebSocket\Server $server
     * @param int $workerId
     * @throws InvalidConfigException
     */
    public function onWorkerStart(\Swoole\WebSocket\Server $server, $workerId)
    {
        new Application($this->app);
		
        Yii::$app->set('server', $server);
		
		$this->processRename($server, $workerId);
		
		echo "Worker# $workerId started" . PHP_EOL;
		
		if(Yii::$app->hasModule('tasks') && !$server->taskworker && $server->setting['worker_num'] >= 1 && $server->getWorkerId() == 0) {
			Yii::$app->getModule('tasks')->start();
		}
    }

    /**
     * @param \Swoole\WebSocket\Server $server
     * @param int $workerId
     * @param int $workerPid
     * @param $exitCode
     * @param $signal
     */
    public function onWorkerError(\Swoole\WebSocket\Server $server, $workerId, $workerPid, $exitCode, $signal)
    {
        fprintf(STDERR, "WebSocket worker error. id=%d pid=%d code=%d signal=%d\n", $workerId, $workerPid, $exitCode, $signal);
    }

    /**
     * @param \Swoole\WebSocket\Server $server
     */
    public function onOpen(\Swoole\WebSocket\Server $server, \Swoole\Http\Request $request)
    {
        if($this->options['log_level'] == 'debug') {
            echo "connection open: {$request->fd}" . PHP_EOL;
        }
    }

    /**
     * @param \Swoole\Http\Request $request
     * @param \Swoole\Http\Response $response
     * @return mixed
     */
    public function onRequest(\Swoole\Http\Request $request, \Swoole\Http\Response $response)
    {
        if ($request->server['path_info'] == '/rpc')
        {
            go(function () use ($request, $response) {
                $result = $this->handleRequest($request->get['p'], -1);
                if ($result instanceof Response) {
                    return $response->end($result->serialize());
                } else {
                    return $response->end('{"jsonrpc":2.0,"id":1,"result":"ok"}');
                }
            });

        } else if ($request->server['remote_addr'] == '127.0.0.1' && $request->server['path_info'] == '/status') {

            return $response->end(http_build_query($this->server->stats(), '', "\n"));

        } else {

            return $response->end($response->status(404));
        }
    }

    /**
     * @param \Swoole\WebSocket\Server $request
     * @param \Swoole\WebSocket\Frame $frame
     */
    public function onMessage(\Swoole\WebSocket\Server $server, \Swoole\WebSocket\Frame $frame)
    {
        go(function () use ($server, $frame) {
            $result = $this->handleRequest($frame->data, $frame->fd);
            if ($result instanceof Response)
                $server->push($frame->fd, $result->serialize());
        });
    }

    /**
     * @param \Swoole\WebSocket\Server $server
     * @param int $taskId
     * @param int $workerId
     * @param \immusen\websocket\src\Task $task
     * @return mixed
     */
    public function onTask(\Swoole\WebSocket\Server $server, $taskId, $workerId, Task $task)
    {
        try {

            $class = Yii::$app->controllerNamespace . '\\' . ucfirst($task->class) . 'Controller';
            $method = new \ReflectionMethod($class, 'action' . ucfirst($task->method));
            $args = $this->getArgs($method, $task);

            return $method->invokeArgs(new $class($server, $task), $args);

        } catch (\Exception $e) {

            var_dump($e->getMessage());
            var_dump($e->getTraceAsString());

            $response = new Response($task->rpc_id);
            $response->setError([
                'code' => -32603,
                'message' => $e->getMessage(),
            ]);

            $server->push($task->fd, $response->serialize());
        }
    }

    /**
     * @param \Swoole\WebSocket\Server $server
     * @param int $workerId
     */
	public function onWorkerStop(\Swoole\WebSocket\Server $server, $workerId)
    {
        echo "WebSocket Worker# $workerId stopped" . PHP_EOL;
    }

    /**
     * @param \Swoole\WebSocket\Server $server
     * @param int $workerId
     */
	public function onWorkerExit(\Swoole\WebSocket\Server $server, $workerId)
    {
        echo "WebSocket Worker# $workerId exit" . PHP_EOL;
    }

    /**
     * @param \Swoole\WebSocket\Server $request
     * @param int $fd
     */
    public function onDisconnect(\Swoole\WebSocket\Server $server, int $fd)
    {
        if($this->options['log_level'] == 'debug') {
            echo "Connection disconnect: {$fd}" . PHP_EOL;
        }
    }

    /**
     * 分发任务
     * @param \Swoole\WebSocket\Server $server
     * @param int $fd
     * @param $from
     */

    public function onClose(\Swoole\WebSocket\Server $server, int $fd, $from)
    {
        if($this->options['log_level'] == 'debug') {
            echo "WebSocket Task close" . PHP_EOL;
        }

        $server->task(Task::internal('common/close', ['fd' => $fd]));
    }

    /**
     * @param \Swoole\WebSocket\Server $server
     */
    public function onFinish(\Swoole\WebSocket\Server $server)
    {
        if($this->options['log_level'] == 'debug') {
            echo "WebSocket Task finished" . PHP_EOL;
        }
    }

    /**
     * @param \Swoole\WebSocket\Server $server
     * @param int $workerId
     */
    private function processRename(\Swoole\WebSocket\Server $server, $workerId)
    {
		if ($server->taskworker) {
			swoole_set_process_name("Yii2 WebSocket Server: task");
		} else {
			swoole_set_process_name("Yii2 WebSocket Server: worker");
		}
	}

    /**
     * @param $method
     * @param \immusen\websocket\src\Task $server
     */
    private function getArgs($method, Task $task)
    {
        $params = $task->param;
        $args = [];
        $missing = [];

        if (!is_array($params)) {
            $params = array($params);
        }

        foreach ($method->getParameters() as $param) {

            $name = $param->getName();

            if (array_key_exists($name, $params)) {

                $args[] = $params[$name];
                unset($params[$name]);

            } elseif ($param->isDefaultValueAvailable()) {

                $args[] = $param->getDefaultValue();

            } else {

                $missing[] = $name;
            }
        }

        if (!empty($missing)) {
            throw new \Exception('Missing required parameters: ' . implode(',', $missing));
        }

        return $args;
    }

    /**
     * @param $rpc_json
     * @param int $fd
     */
    private function handleRequest($rpc_json, $fd = 0)
    {
        try {

            $request = new Request($rpc_json);
            $id = $request->getId();
            $method = $request->getMethod();
            $params = $request->getParams();

        } catch (Exception $e) {

            if (!isset($id)) { $id = 1; }
            $response = new Response($id);
            $response->setError($e->getError());
            return $response;
        }

        return $this->server->task(Task::rpc($fd, $method, $params, $id));
    }
}