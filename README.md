# vcd-influxdb
Pushing vCloud Director Metrics into InfluxDB.

## Installation
### Download and Requirements
```
apt install php7.2-cli php7.2-curl composer unzip
git clone https://github.com/level66network/vcd-influxdb.git
cd vcd-influxdb
composer install
```

### Configuration
Duplicate 'config.php.tpl' as 'config.php' and edit the setting according your needs.

### Grafana
Import Dashboards into Grafana and update all vCPU calculations with the speed your processors (2.4 GHz in our case).

## Run

### Daemon Mode
Fetches and pushes the data every 300 seconds.
```
./vcd-influxdb.php --daemon
```

### One Time
Fetches and pushes the data only once.
```
./vcd-influxdb.php
```

## systemd
```
cp systemd/system/vcd-influxdb.service /etc/systemd/system/vcd-influxdb.service
/bin/systemctl daemon-reload
/bin/systemctl enable vcd-influxdb.service
/bin/systemctl start vcd-influxdb.service
```
