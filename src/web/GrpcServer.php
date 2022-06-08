<?php

// Changes made by Spywer

namespace swoole\foundation\web;

use Yii;
use yii\base\InvalidConfigException;

class GrpcServer extends Server
{
    /**
     * @var string 监听主机
     */
    public $host = 'localhost';
    /**
     * @var int 监听端口
     */
    public $port = 50051;
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
        'worker_num' => 1,
        'daemonize' => 0,
        'task_worker_num' => 1,
        'open_http2_protocol' => true
    ];
    /**
     * @var array 应用配置
     */
    public $app = [];
    /**
     * @var \Swoole\Http\Server swoole server实例
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
            throw new InvalidConfigException('The GRPC "app" property mus be set.');
        }

        if (!$this->server instanceof \Swoole\Http\Server) {
            $this->server = new \Swoole\Http\Server($this->host, $this->port, $this->mode, $this->sockType);
            $this->server->set($this->options);
        }

        foreach ($this->events() as $event => $callback) {
            $this->server->on($event, $callback);
        }
    }

    /**
     * 启动服务器
     * @return bool
     */
    public function start()
    {
        swoole_set_process_name("Yii2 GRPC Server: master");

        return $this->server->start();
    }

    public function onManagerStart()
    {
        swoole_set_process_name("Yii2 GRPC Server: manager");
    }

    /**
     * master启动
     * @param \Swoole\Http\Server $server
     */
    public function onStart(\Swoole\Http\Server $server)
    {
        printf("GRPC listen on %s:%d\n", $server->host, $server->port);
    }

    /**
     * 工作进程启动时实例化框架
     * @param \Swoole\Http\Server $server
     * @param int $workerId
     * @throws InvalidConfigException
     */
    public function onWorkerStart(\Swoole\Http\Server $server, $workerId)
    {
        new Application($this->app);

        Yii::$app->set('server', $server);

        $this->processRename($server, $workerId);

        echo "GRPC Worker# $workerId started" . PHP_EOL;

        if(Yii::$app->hasModule('tasks') && !$server->taskworker && $server->setting['worker_num'] >= 1 && $server->getWorkerId() == 0) {
            Yii::$app->getModule('tasks')->start();
        }
    }

    /**
     * 处理请求
     * @param \Swoole\Http\Request $request
     * @param \Swoole\Http\Response $response
     */
    public function onRequest(\Swoole\Http\Request $request, \Swoole\Http\Response $response)
    {
        $dispatcher = new \mri\core\Dispatcher();
        $router = new \mri\core\grpc\Router();
        $header = new \mri\core\grpc\Header();

        $header->setResponse($response)->init();

        try {

            if (trim($request->server['request_uri']) == SWOOLE_CLOSE_KEYWORD) {
                $response->end();
                return;
            }

            $route = $router->setURI($request->server['request_uri'])->route();

            if (is_null($route)) {
                $errorMessage = "Unknown router, request_uri: " . $request->server['request_uri'];
                $header->status(\Grpc\STATUS_UNKNOWN, $errorMessage)->getResponse()->end();
                return;
            }

            Yii::$app->runAction(
                $route['controller'] . '/' . $route['action'],
                ['request' => $dispatcher->handleRequest($route['module'], $route['controller'], $route['action'], $request->rawcontent())]
            );

            $status = Yii::$app->getResponse()->data['status'] ?? \Grpc\STATUS_OK;
            $message = Yii::$app->getResponse()->data['message'] ?? '';

            $header->status($status, $message)->getResponse()->end(
                $dispatcher->handleResponse(Yii::$app->getResponse()->data['data'] ?? [])
            );

        } catch (\Exception $e) {
            $header->status(\Grpc\STATUS_INTERNAL, $e->getMessage())->getResponse()->end();
            return;
        }
    }

    public function onWorkerStop(\Swoole\Http\Server $server, $workerId)
    {
        echo "GRPC Worker# $workerId stopped" . PHP_EOL;
    }

    public function onWorkerExit(\Swoole\Http\Server $server, $workerId)
    {
        echo "GRPC Worker# $workerId exit" . PHP_EOL;
    }

    public function onFinish(\Swoole\Http\Server $server)
    {
        echo "GRPC Task finished" . PHP_EOL;
    }

    protected function processRename(\Swoole\Http\Server $server, $worker_id)
    {
        if ($server->taskworker) {
            swoole_set_process_name("Yii2 GRPC Server: task");
        } else {
            swoole_set_process_name("Yii2 GRPC Server: worker");
        }
    }
}