#!/usr/bin/env php
<?php

require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Console\Application;
use Symfony\Component\Dotenv\Dotenv;

// Sup .env vars.
$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/.env');

use Workerman\Worker;
use Workerman\Mqtt\Client as Mqtt;
use InfluxDB2\Client as Influx;
use InfluxDB2\Model\WritePrecision;
use InfluxDB2\Point;
use InfluxDB2\WriteApi;

// RUN: `php ~/html/modules/custom/iiot/bridge/bridge.php start` -d to demonize/.
// Input "php /var/www//html/modules/custom/iiot/bridge/bridge.php stop" to stop.
$name = $_ENV['NAME'] ?? 'php-bridge';
$topic_will = $_ENV['MQTT_WILL_TOPIC'] ?? "\$bridge/{$name}/availability";
$topic_online = $_ENV['MQTT_ONLINE_TOPIC'] ?? "\$bridge/{$name}/availability";
$config = [
  'influx' => [
    'url' => $_ENV['INFLUX_HOST'],
    'token' => $_ENV['INFLUX_TOKEN'],
    'bucket' => $_ENV['INFLUX_BUCKET'],
    'org' => $_ENV['INFLUX_ORG'],
    'precision' => WritePrecision::S,
    'tags' => [
      'bridge' => $name,
    ],
    'timeout' => (int) $_ENV['INFLUX_TIMEOUT'] ?? 2,
  ],
  // Options: https://github.com/walkor/mqtt /.
  'mqtt_options' => [
    'username' => $_ENV['MQTT_USER'] ?? "",
    'password' => $_ENV['MQTT_PASS'] ?? "",
    'ssl' => (bool) $_ENV['MQTT_SSL'] ?? TRUE,
    'will' => [
      'topic' => str_replace("{name}", $name, $_ENV['MQTT_WILL_TOPIC']),
      'content' => $_ENV['MQTT_WILL_CONTENT'] ?? 'offline',
      'qos' => (int) $_ENV['MQTT_WILL_QOS'] ?? 1,
      'retain' => (bool) $_ENV['MQTT_WILL_RETAIN'] ?? TRUE,
    ],
  ],
  'mqtt' => [
    'host' => $_ENV['MQTT_HOST'],
    'online' => [
      'topic' => str_replace("{name}", $name, $_ENV['MQTT_ONLINE_TOPIC']),
      'content' => $_ENV['MQTT_ONLINE_CONTENT'] ?? 'online',
    ],
    'subscribe' => [
      '$devices/*/events/*' => 1,
      '$devices/*/state/*' => 1,
      '$registries/*/events' => 1,
      '$registries/*/state' => 1,
      '$monitoring/json' => 1,
    ],
  ],
  'redis' => [
    'enable' => (bool) $_ENV['REDIS_ENABLE'] ?? FALSE,
    'host' => $_ENV['REDIS_HOST'] ?? "",
    'port' => (int) $_ENV['REDIS_PORT'] ?? 6379,
    'user' => $_ENV['REDIS_USER'] ?? "",
    'pass' => $_ENV['REDIS_PASS'] ?? "",
    'ttl' => (int) $_ENV['REDIS_TTL'] ?? 0,
  ]
];

$parser = function ($topic) : array | NULL {
  if (substr($topic, 0, 9) == '$devices/') {
    $args = explode("/", $topic);
    $info = [
      'uuid' => $args[1] ?? "",
      'type' => $args[2] ?? "",
      'event' => $args[3] ?? "",
    ];
    return $info;
  }
  else {
    return 0;
  }
};

// Start.
$worker = new Worker();
$influx = new Influx($config['influx']);
$writeApi = $influx->createWriteApi();
$redis = new \Redis();
if ($config['redis']['enable']) {
  try {
    $redis->connect($config['redis']['host'], $config['redis']['port']);
    $redis->auth($config['redis']['pass']);
    if (!$redis->ping()) {
      $config['redis']['enable'] = FALSE;
    }
  }
  catch (\RedisException $e) {
    $config['redis']['enable'] = FALSE;
  }
}

$worker->onWorkerStart = function () use ($writeApi, $redis, $config, $parser) {
  $mqtt = new Mqtt($config['mqtt']['host'], $config['mqtt_options']);
  $mqtt->onConnect = function (Mqtt $mqtt) use ($config) {
    $mqtt->subscribe($config['mqtt']['subscribe']);
    $mqtt->publish($config['mqtt']['online']['topic'], $config['mqtt']['online']['content']);
  };
  $mqtt->onMessage = function ($topic, $content) use ($writeApi, $redis, $config, $parser) {
    if ($info = $parser($topic)) {
      if ($info['type'] == 'events') {
        $measurment = floatval($content);
        $point = Point::measurement($info['event'])
          ->addField('value', $measurment)
          ->addTag('_device', $info['uuid'])
          ->addTag('bridge', 's1')
          ->time(microtime(TRUE));
        $writeApi->write($point);
      }
      if ($config['redis']['enable'] && $info['type'] == 'state') {
        $state = mb_substr($content, 0, 128);
        if ($config['redis']['ttl'] > 0) {
          $redis->set($topic, $state, ['nx', 'ex' => $config['redis']['ttl']]);
        }
        else {
          $redis->set($topic, $state);
        }
      }
    }
  };
  $mqtt->connect();
};

Worker::runAll();
