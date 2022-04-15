# bridge

Конфигурация в `docker-compose`

## Обязательные параметры
### `INFLUX_HOST`
* Адрес сервера influxDB2
* Например: https://influx.example.com:8086

### `INFLUX_TOKEN`
* Токен доступа
* Выглядит примерно так: `разные-буковки-ключ==`

### `INFLUX_BUCKET`
* бакет в который пишем данные
* Например: iot

### `INFLUX_ORG`
* организация в инфлюкс в которой бакет

### `MQTT_HOST`
* адрес mqtt сервера
* Например: mqtt://mqtt.example.com:8883

### `MQTT_USER`
* пользователь для доступа к mqtt
* Например: `iot:bridge`, где `iot` - это vhost если ребит, `bridge` - это username

### `MQTT_PASS`
* Пароль доступа

## Не обязательные параметры

## `NAME`
* Имя моста
* По умолчанию `php-bridge`

## `TIMEZONE`
* По умолчанию `Europe/Moscow`

## `INFLUX_TIMEOUT`
* По умолчанию `2`

## `MQTT_SSL`
* По умолчанию `TRUE`

## `MQTT_SSL`
* По умолчанию `TRUE`

## `MQTT_ONLINE_TOPIC`
* По умолчанию `$bridge/{name}/availability`
* Если указывать в композ-файле, то экранировать так: `$$bridge/{name}/availability`
* `{name}` заменяется на значение из `NAME`

## `MQTT_ONLINE_CONTENT`
* По умолчанию `online`
*
## `MQTT_WILL_TOPIC`
* По умолчанию `$bridge/{name}/availability`
* Если указывать в композ-файле, то экранировать так: `$$bridge/{name}/availability`
* `{name}` заменяется на значение из `NAME`

## `MQTT_WILL_QOS`
* По умолчанию `1`

## `MQTT_WILL_RETAIN`
* По умолчанию `TRUE`
