<?php

class CPHPCacheRedisCluster extends \Bitrix\Main\Data\CacheEngineRedis
{
	private $bQueue = null;

	/** @var array|false $servers */
	private static $servers = false;
	private static $otherGroups = [];

	protected $failover = \RedisCluster::FAILOVER_NONE;
	protected $timeout = null;
	protected $readTimeout = null;

	public static function LoadConfig()
	{
		if (self::$servers === false)
		{
			$arList = false;
			if (file_exists($_SERVER["DOCUMENT_ROOT"].BX_ROOT."/modules/cluster/redis.php"))
			{
				include($_SERVER["DOCUMENT_ROOT"].BX_ROOT."/modules/cluster/redis.php");
			}

			if (defined("BX_REDIS_CLUSTER") && is_array($arList))
			{
				foreach ($arList as $i => $server)
				{
					$otherGroup = defined("BX_CLUSTER_GROUP") && ($server["GROUP_ID"] !== BX_CLUSTER_GROUP);

					if (($server["STATUS"] !== "ONLINE") || $otherGroup)
					{
						unset($arList[$i]);
					}

					if ($otherGroup)
					{
						self::$otherGroups[$server["GROUP_ID"]] = true;
					}
				}
				self::$servers = $arList;
			}
			else
			{
				self::$servers = [];
			}
		}

		return self::$servers;
	}

	public function __construct($options = [])
	{
		if (self::$redis == null)
		{
			$servers = self::LoadConfig();
			$cnt = count($servers);

			if ($cnt == 1)
			{
				$server = array_pop($servers);
				parent::__construct($server);
			}
			elseif ($cnt > 1)
			{
				$config = $this->configure();
				if ($config && is_array($config))
				{
					if (isset($config["failover"]))
					{
						if ($config["failover"] == 0)
						{
							$this->serializer = \RedisCluster::FAILOVER_NONE;
						}
						elseif ($config["failover"] == 1)
						{
							$this->serializer = \RedisCluster::FAILOVER_ERROR;
						}
						elseif ($config["failover"] == 2)
						{
							$this->serializer = \RedisCluster::FAILOVER_DISTRIBUTE;
						}
						elseif ($config["failover"] == 3)
						{
							$this->serializer = \RedisCluster::FAILOVER_DISTRIBUTE_SLAVES;
						}
					}

					if (isset($config["timeout"]))
					{
						$config["timeout"] = (float) $config["timeout"];
						if ($config["timeout"] > 0)
						{
							$this->timeout = $config["timeout"];
						}
					}

					if (isset($config["read_timeout"]))
					{
						$config["read_timeout"] = (float) $config["read_timeout"];
						if ($config["read_timeout"] > 0)
						{
							$this->readTimeout = $config["read_timeout"];
						}
					}
				}

				$connections = [];
				foreach ($servers as $server)
				{
					$connections[] = $server['HOST'].':'.$server['PORT'];
				}

				if (!empty($servers))
				{
					self::$redis = new \RedisCluster(null, $connections, $this->timeout, $this->readTimeout, $this->persistent);

					self::$redis->setOption(\RedisCluster::OPT_SERIALIZER, $this->serializer);

					if ($cnt > 1)
					{
						self::$redis->setOption(\RedisCluster::OPT_SLAVE_FAILOVER, $this->failover);
					}

					self::$isConnected = true;
				}
			}
		}

		if (defined("BX_CACHE_SID"))
		{
			$this->sid = BX_REDIS_CLUSTER.BX_CACHE_SID;
		}
		else
		{
			$this->sid = BX_REDIS_CLUSTER;
		}

		if (defined("BX_CLUSTER_GROUP"))
		{
			$this->bQueue = true;
		}

		if ($this->useLock)
		{
			$this->sid .= 2;
		}
		else
		{
			$this->sid .= 3;
			$this->ttlMultiplier = 1;
		}
	}

	public function QueueRun($param1, $param2, $param3)
	{
		$this->bQueue = false;
		$this->clean($param1, $param2, $param3);
	}

	public function clean($baseDir, $initDir = false, $filename = false)
	{
		if (self::isAvailable())
		{
			if (
				$this->bQueue
				&& class_exists('CModule')
				&& CModule::IncludeModule('cluster')
			)
			{
				foreach (self::$otherGroups as $group_id => $tmp)
				{
					CClusterQueue::Add($group_id, 'CPHPCacheRedisCluster', $baseDir, $initDir, $filename);
				}
			}

			parent::clean($baseDir, $initDir, $filename);
		}
	}
}