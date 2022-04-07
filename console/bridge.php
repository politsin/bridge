#!/usr/bin/env php
<?php

require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Console\Application;
use Symfony\Component\Dotenv\Dotenv;

// Sup .env vars.
$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/.env');

print_r($_ENV);

use Workerman\Worker;
use Workerman\Mqtt\Client as Mqtt;
use InfluxDB2\Client as Influx;
use InfluxDB2\Model\WritePrecision;
use InfluxDB2\Point;
use InfluxDB2\WriteApi;

// RUN: `php ~/html/modules/custom/iiot/bridge/bridge.php start` -d to demonize/.
// Input "php /var/www//html/modules/custom/iiot/bridge/bridge.php stop" to stop.
$name = $_ENV['NAME'] ?? 'docker-bridge';
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
      'content' => $_ENV['MQTT_WILL_TOPIC'] ?? offline,
      'qos' => (int) $_ENV['MQTT_WILL_QOS'] ?? 1,
      'retain' => (bool) $_ENV['MQTT_WILL_RETAIN'] ?? TRUE,
    ],
  ],
  'mqtt' => [
    'host' => $_ENV['MQTT_HOST'],
    'onine' => str_replace("{name}", $name, $_ENV['MQTT_WILL_TOPIC']),
    'subscribe' => [
      '$devices/*/events/*' => 1,
      '$devices/*/state/*' => 1,
      '$registries/*/events' => 1,
      '$registries/*/state' => 1,
      '$monitoring/json' => 1,
    ],
  ],
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

$worker->onWorkerStart = function () use ($writeApi, $config, $parser) {
  $mqtt = new Mqtt($config['mqtt']['host'], $config['mqtt_options']);
  $mqtt->onConnect = function (Mqtt $mqtt) use ($config) {
    $mqtt->subscribe($config['mqtt']['subscribe']);
    $mqtt->publish($config['mqtt_options']['will']['topic'], 'online');
  };
  $mqtt->onMessage = function ($topic, $content) use ($writeApi, $parser) {
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
    }
  };
  $mqtt->connect();
};

Worker::runAll();
