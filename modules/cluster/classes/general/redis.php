<?php
IncludeModuleLangFile(__FILE__);

class CClusterRedis
{
	public static $systemConfigurationUpdate = null;
	private static $arList = false;

	public static function loadConfig()
	{
		if (self::$arList === false)
		{
			$arList = false;
			if (file_exists($_SERVER["DOCUMENT_ROOT"].BX_ROOT."/modules/cluster/redis.php"))
			{
				include($_SERVER["DOCUMENT_ROOT"].BX_ROOT."/modules/cluster/redis.php");
			}

			if (defined("BX_REDIS_CLUSTER") && is_array($arList))
			{
				self::$arList = $arList;
			}
			else
			{
				self::$arList = [];
			}
		}

		return self::$arList;
	}

	public static function saveConfig($servers)
	{
		self::$arList = false;
		$isOnline = false;

		$content = '<'.'?
define("BX_REDIS_CLUSTER", "'.EscapePHPString(CMain::GetServerUniqID()).'");
$arList = [
';
		$defGroup = 1;
		$groups = [];
		$clusterGroups = CClusterGroup::GetList(["ID" => "DESC"]);
		while ($group = $clusterGroups->Fetch())
		{
			$defGroup = $groups[$group["ID"]] = intval($group["ID"]);
		}

		foreach ($servers as $i => $server)
		{
			$isOnline |= ($server["STATUS"] == "ONLINE");

			$GROUP_ID = intval($server["GROUP_ID"]);
			if (!array_key_exists($server["GROUP_ID"], $groups))
			{
				$GROUP_ID = $defGroup;
			}

			$content .= "\t".intval($i)." => [\n";
			$content .= "\t\t'ID' => \"".EscapePHPString($server["ID"])."\",\n";
			$content .= "\t\t'GROUP_ID' => ".$GROUP_ID.",\n";
			$content .= "\t\t'HOST' => \"".EscapePHPString($server["HOST"])."\",\n";
			$content .= "\t\t'PORT' => ".intval($server["PORT"]).",\n";
			if ($server["STATUS"] == "ONLINE")
			{
				$content .= "\t\t'STATUS' => \"ONLINE\",\n";
			}
			elseif ($server["STATUS"] == "OFFLINE")
			{
				$content .= "\t\t'STATUS' => \"OFFLINE\",\n";
			}
			else
			{
				$content .= "\t\t'STATUS' => \"READY\",\n";
			}
			$content .= "\t],\n";
		}

		$content .= '];
?'.'>';
		file_put_contents($_SERVER["DOCUMENT_ROOT"].BX_ROOT."/modules/cluster/redis.php", $content);
		bx_accelerator_reset();

		self::$systemConfigurationUpdate = null;
		$cache = \Bitrix\Main\Config\Configuration::getValue('cache');

		if ($isOnline)
		{
			if (
				!is_array($cache)
				|| !isset($cache['type'])
				|| !is_array($cache['type'])
				|| !isset($cache['type']['class_name'])
				|| !($cache['type']['class_name'] === 'CPHPCacheRedisCluster')
			)
			{
				\Bitrix\Main\Config\Configuration::setValue('cache', [
					'type' => [
						'class_name' => 'CPHPCacheRedisCluster',
						'extension' => 'redis',
						'required_file' => 'modules/cluster/classes/general/redis_cache.php',
					],
					'failover' => \Bitrix\Main\Config\Option::get('cluster', 'failower_settings'),
					'timeout' => \Bitrix\Main\Config\Option::get('cluster', 'redis_timeoit'),
					'read_timeout' => \Bitrix\Main\Config\Option::get('cluster', 'redis_read_timeout'),
					'persistent' => (\Bitrix\Main\Config\Option::get('cluster', 'redis_persistent') == "Y"),
				]);
				self::$systemConfigurationUpdate = true;
			}
		}
		else
		{
			if (
				is_array($cache)
				&& isset($cache['type'])
				&& is_array($cache['type'])
				&& isset($cache['type']['class_name'])
				&& ($cache['type']['class_name'] === 'CPHPCacheRedisCluster')
			)
			{
				\Bitrix\Main\Config\Configuration::setValue('cache', null);
				self::$systemConfigurationUpdate = false;
			}
		}
	}

	public static function getList()
	{
		$res = new CDBResult;
		$res->InitFromArray(CClusterRedis::loadConfig());
		return $res;
	}

	public static function getServerList()
	{
		$result = [];
		foreach (CClusterRedis::loadConfig() as $data)
		{
			$result[] = [
				"ID" => $data["ID"],
				"GROUP_ID" => $data["GROUP_ID"],
				"SERVER_TYPE" => "redis",
				"ROLE_ID" => "",
				"HOST" => $data["HOST"],
				"DEDICATED" => "Y",
				"EDIT_URL" => "/bitrix/admin/cluster_redis_edit.php?lang=".LANGUAGE_ID."&group_id=".$data["GROUP_ID"]."&ID=".$data["ID"],
			];
		}
		return $result;
	}

	public static function getByID($id)
	{
		$ar = CClusterRedis::loadConfig();
		return $ar[$id];
	}

	public function add($fields)
	{
		if (!$this->checkFields($fields, false))
		{
			return false;
		}

		$servers = CClusterRedis::loadConfig();

		$ID = 1;
		foreach ($servers as $server)
		{
			if ($server["ID"] >= $ID)
			{
				$ID = $server["ID"] + 1;
			}
		}

		$servers[$ID] =[
			"ID" => $ID,
			"GROUP_ID" => intval($fields["GROUP_ID"]),
			"STATUS" => "READY",
			"WEIGHT" => $fields["WEIGHT"],
			"HOST" => $fields["HOST"],
			"PORT" => $fields["PORT"],
		];
		CClusterRedis::saveConfig($servers);
		return $ID;
	}

	public static function delete($id)
	{
		$servers = CClusterRedis::loadConfig();
		if(array_key_exists($id, $servers))
		{
			unset($servers[$id]);
			CClusterRedis::saveConfig($servers);
		}
		return true;
	}

	public function update($id, $fields)
	{
		$id = intval($id);
		$servers = CClusterRedis::loadConfig();

		if (!array_key_exists($id, $servers))
		{
			return false;
		}

		if (!$this->checkFields($fields, $id))
		{
			return false;
		}

		$servers[$id] = [
			"ID" => $id,
			"GROUP_ID" => $servers[$id]["GROUP_ID"],
			"STATUS" => isset($fields["STATUS"])? $fields["STATUS"]: $servers[$id]["STATUS"],
			"HOST" => isset($fields["HOST"])? $fields["HOST"]: $servers[$id]["HOST"],
			"PORT" => isset($fields["PORT"])? $fields["PORT"]: $servers[$id]["PORT"],
		];
		CClusterRedis::saveConfig($servers);
		return $id;
	}

	public function checkFields(&$fields, $id)
	{
		global $APPLICATION;
		$aMsg = [];

		if (isset($fields["PORT"]))
		{
			$fields["PORT"] = intval($fields["PORT"]);
		}

		if (isset($fields["WEIGHT"]) || $id === false)
		{
			$weight = intval($fields["WEIGHT"]);
			if ($weight < 0)
			{
				$weight = 0;
			}
			elseif ($weight > 100)
			{
				$weight = 100;
			}

			$fields["WEIGHT"] = $weight;
		}

		if (isset($fields["HOST"]) && isset($fields["PORT"]))
		{
			$ob = new \Redis();
			if (!@$ob->connect($fields["HOST"], $fields["PORT"]))
			{
				$aMsg[] = array("id" => "HOST", "text" => GetMessage("CLU_REDIS_CANNOT_CONNECT"));
			}
		}

		if (!empty($aMsg))
		{
			$e = new CAdminException($aMsg);
			$APPLICATION->ThrowException($e);
			return false;
		}
		return true;
	}

	public static function pause($id)
	{
		$servers = CClusterRedis::getByID($id);
		if (is_array($servers) && $servers["STATUS"] != "READY")
		{
			$ob = new CClusterRedis;
			$ob->update($id, ["STATUS" => "READY"]);
		}
	}

	public static function resume($id)
	{
		$servers = CClusterRedis::getByID($id);
		if (is_array($servers) && $servers["STATUS"] == "READY")
		{
			$ob = new CClusterRedis;
			$ob->update($id, ["STATUS" => "ONLINE"]);
		}
	}

	public static function getStatus($id)
	{
		$stats = [];
		$servers = CClusterRedis::getByID($id);
		if (is_array($servers))
		{
			$redis = new \Redis();
			if(@$redis->connect($servers["HOST"], $servers["PORT"]))
			{
				$stats = [
					'redis_version' => null,
					'os' => null,
					'uptime_in_seconds' => null,
					'connected_clients' => null,
					'used_memory_human' => null,
					'total_system_memory_human' => null,
					'maxmemory_human' => null,
					'maxmemory_policy' => null,
					'mem_fragmentation_ratio' => null,
					'loading' => null,
					'keyspace_hits' => null,
					'keyspace_misses' => null,
					'evicted_keys' => null,
					'expired_keys' => null,
					'expired_stale_perc' => null,
					'used_cpu_sys' => null,
					'used_cpu_user' => null,
					'used_cpu_sys_children' => null,
					'used_cpu_user_children' => null,
					'role' => null,
					'cluster_enabled' => null,
					'connected_slaves' => null,
					'master_replid' => null,
					'master_replid2' => null,
					'master_repl_offset' => null,
					'slave_expires_tracked_keys' => null,
				];

				$info = $redis->info();
				foreach ($stats as $key => $value)
				{
					$stats[$key] = $info[$key];
				}
			}
		}

		return $stats;
	}
}