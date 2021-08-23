# Yii2 Swoole Extension

Running Yii2 application on Swoole Environment.

This extension based on Component-Driven development.

There is no side effects to your business or Yii2 framework.

Original repo: https://github.com/swoole-foundation/yii2-swoole-extension

## Get Started

1. Initialize your Yii application
     ```bash
     composer create-project --prefer-dist yiisoft/yii2-app-basic basic
     ```

2. Install this package by composer
     ```bash
     composer require spywer/yii2-swoole-extension
     ```

3. Create server configuration file.
	```php
	// config/server.php
	<?php
	  return [
	   'host' => 'localhost',
	   'port' => 9501,
	   'mode' => SWOOLE_PROCESS,
	   'sockType' => SWOOLE_SOCK_TCP,
	   'app' => require __DIR__ . '/swoole.php', 
	   'options' => [ // options for swoole server
	       // Process
        'pid_file' => __DIR__ . '/../runtime/swoole-server.pid',
        'daemonize' => 0,

        // Server
        'worker_num' => 2,
        //'reactor_num' => 1,

        // Task worker
        'task_worker_num' => 2,

        // Coroutine
        'enable_coroutine' => true,

        // Source File Reloading
        'reload_async' => false,
        'max_wait_time' => 30,

        // Static Files
        'document_root' => dirname(__DIR__) . '/web',
        'enable_static_handler' => true,
        'static_handler_locations' => ['/'],

        // Logging
        'log_level' => 1,
        'log_file' => __DIR__ . '/../runtime/logs/swoole.log',
        'log_rotation' => SWOOLE_LOG_ROTATION_DAILY,
        'log_date_format' => '%Y-%m-%d %H:%M:%S',
        'log_date_with_microseconds' => false,

        // Compression
        'http_compression' => true,
        'http_compression_level' => 6, // 1 - 9
        'compression_min_length' => 20,
	   ]
	];
	```

4. Create swoole.php and replace the default web components of Yii2。
	
	> Thanks for [@RicardoSette](https://github.com/RicardoSette)
	
	```php
	// config/swoole.php
	<?php
	
	$config = require __DIR__ . '/web.php';
	
	$config['components']['response']['class'] = swoole\foundation\web\Response::class;
	$config['components']['request']['class'] = swoole\foundation\web\Request::class;
	$config['components']['errorHandler']['class'] = swoole\foundation\web\ErrorHandler::class;
	
	return $config;
	```
	
	
	
5. Create bootstrap file.

  ```php
  // bootstrap.php
  <?php

	use swoole\foundation\web\Server;
	use Swoole\Runtime;

	ini_set('use_cookies', 'false');
	ini_set('use_only_cookies', 'true');

	// Warning: singleton in coroutine environment is untested!
	Runtime::enableCoroutine(true, SWOOLE_HOOK_ALL);
	defined('WEBROOT') or define('WEBROOT', __DIR__ . '/web');
	defined('YII_DEBUG') or define('YII_DEBUG', true);
	defined('YII_ENV') or define('YII_ENV', getenv('PHP_ENV') === 'development' ? 'dev' : 'prod');

	require __DIR__ . '/vendor/autoload.php';
	require __DIR__ . '/vendor/yiisoft/yii2/Yii.php';

	$config = require __DIR__ . '/config/swoole-server.php';
	$server = new Server($config);

	echo "Start bootstrap loader\n";

	$server->start();
  ```
  
6. Edit config/web.php file.

	```php
	'components' => [
		'request' => [
            'class' => \swoole\foundation\web\Request::class,
            'cookieValidationKey' => 'insert your key',
        ],
        'response' => [
            'class' => \swoole\foundation\web\Response::class,
            'format' => \swoole\foundation\web\Response::FORMAT_JSON
        ],
		'errorHandler' => [
            'class' => \swoole\foundation\web\ErrorHandler::class,
            'errorAction' => 'site/error',
        ],
		'assetManager' => [
            'linkAssets' => true,
            'appendTimestamp' => true,
            'basePath' => __DIR__ . '/../web/assets',
            'bundles' => [
                //'yii\web\JqueryAsset' => false,
                //'yii\web\YiiAsset' => false,
                //'yii\bootstrap\BootstrapAsset' => false,
            ],
        ],
	],
	```

7. Start your app.
  ```bash
  php bootstrap.php
  ```

8. Congratulations! Your first Yii2 Swoole Application is running!

## Examples

Theres is an complete application in `tests` directory.

## Todo

- [ ] Fix coroutine environment
- [ ] Support for docker
- [ ] Add test case
- [ ] Work with travis-ci

## Contribution

This Project only works because of contributions by users like you!

1. Fork this project
2. Create your branch
3. Make a pull request
4. Wait for merge
