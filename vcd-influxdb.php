#!/usr/bin/env php
<?php
/* Check if requirements set */
if(file_exists(__DIR__ . '/vendor/autoload.php')){
	require_once(__DIR__ . '/vendor/autoload.php');
}else{
	exit('Composer not initialized!');
}

/* Check if configuration exsists */
if(file_exists(__DIR__ . '/config.php')){
	require_once(__DIR__ . '/config.php');
}else{
	exit('Configuration does not exsist.');
}

/* Check if daemon flag is set */
foreach($argv as $arg){
	if($arg == '-d' or $arg == '--daemon'){
		$daemon = true;
	}else{
		$daemon = false;
	}
}

/* Connect to vCD */
$api = new RestClient(['base_url' => $cfg['VCD']['URL'], 'headers' => ['Accept' => 'application/*+xml;version=30.0']]);
$api->set_option('username', $cfg['VCD']['USER'] . '@system');
$api->set_option('password', $cfg['VCD']['PASSWORD']);

/* Get vCD token */
$token = $api->post('/api/sessions');

/* Check if authententication successfull */
if(isset($token->headers->x_vmware_vcloud_access_token)){
	$api->set_option('headers', ['Accept' => 'application/*+xml;version=30.0', 'x-vcloud-authorization' => $token->headers->x_vcloud_authorization]);
	$api->set_option('username', null);
	$api->set_option('password', null);
}else{
	Exit('vCD authentication failed!');
}

/* Connect to InfluxDB */
if($cfg['INFLUXDB']['USER'] and $cfg['INFLUXDB']['PASSWORD']){
	$influxdb = new InfluxDB\Client($cfg['INFLUXDB']['HOST'], $cfg['INFLUXDB']['PORT'], $cfg['INFLUXDB']['USER'], $cfg['INFLUXDB']['PASSWORD'], $cfg['INFLUXDB']['SSL']);
}else{
	$influxdb = new InfluxDB\Client($cfg['INFLUXDB']['HOST'], $cfg['INFLUXDB']['PORT'], null, null, $cfg['INFLUXDB']['SSL']);
}

/* Use InfluxDB database and check if exsists */
$db = $influxdb->selectDB($cfg['INFLUXDB']['DB']);

do{
	/* Fetching ORGs */
	$orgs = $api->get('/api/admin/orgs/query');
	$orgs = new SimpleXMLElement($orgs->response);


	$influxdb_org = Array();

	/* Iterating through Orgs */
	foreach($orgs->OrgRecord as $org){
		/* org measurements */
		$influxdb_org[] =  new InfluxDB\Point('org', null, ['name' => $org['name'], 'displayName' => $org['displayName'], 'isEnabled' => true, 'isReadOnly' => true, 'canPublishCatalogs' => true], ['numberOfCatalogs' => $org['numberOfCatalogs'], 'numberOfDisks' => $org['numberOfDisks'], 'numberOfGroups' => $org['numberOfGroups'], 'numberOfVApps' => $org['numberOfVApps'], 'numberOfRunningVMs' => $org['numberOfRunningVMs'], 'numberOfVdcs' => $org['numberOfVdcs'], 'storedVMQuota' => $org['storedVMQuota']]);

		$org = $api->get(str_replace($cfg['VCD']['URL'], '', $org['href']));
		$org = new SimpleXMLElement($org->response);

		$influxdb_ovdc = Array();

		/* Iterate through Links oVDCs */
		foreach($org->Link as $link){
			if($link['type'] == 'application/vnd.vmware.vcloud.vdc+xml'){
				$ovdc = $api->get(str_replace($cfg['VCD']['URL'], '', $link['href']));
				$ovdc = new SimpleXMLElement($ovdc->response);

				/* oVDC measurements */
				$influxdb_ovdc[] = new InfluxDB\Point(
					'oVDC',
					null,
					['name' => $ovdc['name'], 'status' => $ovdc['status'], 'org' => $org['name'], 'AllocationModel' => $ovdc->AllocationModel, 'IsEnabled' => $ovdc->IsEnabled],
					['VmQuota' => $ovdc->VmQuota, 'NetworkQuota' => $ovdc->NetworkQuota, 'UsedNetworkCount' => $ovdc->UsedNetworkCount, 'VCpuInMhz2' => $ovdc->VCpuInMhz2, 'ComputeCapacityCpuAllocated' => $ovdc->ComputeCapacity->Cpu->Allocated, 'ComputeCapacityCpuLimit' => $ovdc->ComputeCapacity->Cpu->Limit, 'ComputeCapacityCpuReserved' => $ovdc->ComputeCapacity->Cpu->Reserved, 'ComputeCapacityCpuUsed' => $ovdc->ComputeCapacity->Cpu->Used, 'ComputeCapacityMemoryAllocated' => $ovdc->ComputeCapacity->Memory->Allocated, 'ComputeCapacityMemoryLimit' => $ovdc->ComputeCapacity->Memory->Limit, 'ComputeCapacityMemoryReserved' => $ovdc->ComputeCapacity->Memory->Reserved, 'ComputeCapacityMemoryUsed' => $ovdc->ComputeCapacity->Memory->Used]
				);

				/* Iterate through Storage Profiles */
				foreach($ovdc->VdcStorageProfiles->VdcStorageProfile as $sp){
					$sp = $api->get(str_replace($cfg['VCD']['URL'], '', $sp['href']));
					$sp =  new SimpleXMLElement($sp->response);

					/* Storage Profile measurements */
					$influxdb_ovdc[] = new InfluxDB\Point(
						'oVDC-storage',
						null,
						['name' => $sp['name'], 'Enabled' => $sp->Enabled, 'org' => $org['name'], 'ovdc' => $ovdc['name'], 'Default' => $sp->Default],
						['Limit' => $sp->Limit, 'StorageUsedMB' => $sp->StorageUsedMB, 'IopsAllocated' => $sp->IopsAllocated]
					);
				}
			}
		}

		/* Push oVDC data to InfluxDB */
		$db->writePoints($influxdb_ovdc, InfluxDB\Database::PRECISION_SECONDS);
	}

	/* Push org data to InfluxDB */
	$db->writePoints($influxdb_org, InfluxDB\Database::PRECISION_SECONDS);


        /* Take a nap if running in daemon mode */
        if($daemon){
 		sleep(300);
        }

/* Ending of daemon loop */
}while($daemon);
