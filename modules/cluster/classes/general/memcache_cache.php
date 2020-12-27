<?php

class CPHPCacheMemcacheCluster extends \Bitrix\Main\Data\CacheEngineMemcache
{
	private $bQueue = null;
	/** @var array|false $arList */
	private static $arList = false;
	private static $arOtherGroups = array();

	public static function LoadConfig()
	{
		if (self::$arList === false)
		{
			$arList = false;
			if (file_exists($_SERVER["DOCUMENT_ROOT"].BX_ROOT."/modules/cluster/memcache.php"))
				include($_SERVER["DOCUMENT_ROOT"].BX_ROOT."/modules/cluster/memcache.php");

			if (defined("BX_MEMCACHE_CLUSTER") && is_array($arList))
			{
				foreach ($arList as $i => $arServer)
				{
					$bOtherGroup = defined("BX_CLUSTER_GROUP") && ($arServer["GROUP_ID"] !== BX_CLUSTER_GROUP);

					if (($arServer["STATUS"] !== "ONLINE") || $bOtherGroup)
						unset($arList[$i]);

					if ($bOtherGroup)
						self::$arOtherGroups[$arServer["GROUP_ID"]] = true;
				}

				self::$arList = $arList;
			}
			else
				self::$arList = array();

		}
		return self::$arList;
	}

	function __construct($options = [])
	{
		if (!is_object(self::$memcache))
		{
			self::$memcache = new Memcache;
			$arServerList = CPHPCacheMemcacheCluster::LoadConfig();

			if (count($arServerList) == 1)
			{
				$arServer = array_pop($arServerList);
				self::$memcache->pconnect($arServer["HOST"], $arServer["PORT"]);
			}
			else
			{
				foreach ($arServerList as $arServer)
				{
					self::$memcache->addServer(
						$arServer["HOST"]
						, $arServer["PORT"]
						, true //persistent
						, ($arServer["WEIGHT"] > 0? $arServer["WEIGHT"]: 1)
						, 1 //timeout
					);
				}
			}
		}

		if (defined("BX_CACHE_SID"))
		{
			$this->sid = BX_MEMCACHE_CLUSTER . BX_CACHE_SID;
		}
		else
		{
			$this->sid = BX_MEMCACHE_CLUSTER;
		}

		if (defined("BX_CLUSTER_GROUP"))
		{
			$this->bQueue = true;
		}

		$cacheConfig = \Bitrix\Main\Config\Configuration::getValue("cache");
		if ($cacheConfig && is_array($cacheConfig))
		{
			if (isset($cacheConfig["use_lock"]))
			{
				$this->useLock = (bool)$cacheConfig["use_lock"];
			}

			if (isset($cacheConfig["sid"]) && ($cacheConfig["sid"] != ""))
			{
				if (!defined("BX_CACHE_SID"))
				{
					$this->sid = BX_MEMCACHE_CLUSTER.$cacheConfig["sid"];
				}
			}

			if (isset($cacheConfig["ttl_multiplier"]) && $this->useLock)
			{
				$this->ttlMultiplier = (integer)$cacheConfig["ttl_multiplier"];
			}
		}

		if (!empty($options) && isset($options['actual_data']))
		{
			$this->useLock = !((bool) $options['actual_data']);
		}

		$this->sid .= ($this->useLock? 2: 3);

		if (!$this->useLock)
		{
			$this->ttlMultiplier = 1;
		}
	}

	function IsAvailable()
	{
		return count(self::$arList) > 0;
	}

	function QueueRun($param1, $param2, $param3)
	{
		$this->bQueue = false;
		$this->clean($param1, $param2, $param3);
	}

	function clean($baseDir, $initDir = false, $filename = false)
	{
		if (is_object(self::$memcache))
		{
			if (
				$this->bQueue
				&& class_exists('CModule')
				&& CModule::IncludeModule('cluster')
			)
			{
				foreach (self::$arOtherGroups as $group_id => $tmp)
				{
					CClusterQueue::Add($group_id, 'CPHPCacheMemcacheCluster', $baseDir, $initDir, $filename);
				}
			}

			parent::clean($baseDir, $initDir, $filename);
		}
	}
}