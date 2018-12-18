<?

namespace Bitrix\Main\UI;

use Bitrix\Main\Application;
use Bitrix\Main\IO\File;
use Bitrix\Main\IO\Directory;

class Extension
{
	public static function load($extNames)
	{
		if (!is_array($extNames))
		{
			$extNames = [$extNames];
		}

		foreach ($extNames as $extName)
		{
			if (static::register($extName))
			{
				\CJSCore::init($extName);
			}
		}
	}

	public static function register($extName)
	{
		$extension = static::getConfig($extName);
		if ($extension !== null)
		{
			static::registerAssets($extName, $extension);

			return true;
		}

		return \CJSCore::isExtRegistered($extName);
	}

	public static function registerAssets($id, array $options)
	{
		\CJSCore::registerExt($id, $options);
	}

	private static function getConfig($extName)
	{
		$extensionPath = static::getPath($extName);
		if ($extensionPath === null)
		{
			return null;
		}

		$configFile = Application::getDocumentRoot().$extensionPath."/config.php";
		if (!File::isFileExists($configFile))
		{
			return null;
		}

		$config = include($configFile);

		if (
			is_array($config)
			&& Directory::isDirectoryExists(Application::getDocumentRoot().$extensionPath.'/lang/')
		)
		{
			if (isset($config["lang"]))
			{
				if (is_array($config["lang"]))
				{
					$config["lang"][] = $extensionPath."/config.php";
				}
				else
				{
					$config["lang"] = array($config["lang"], $extensionPath."/config.php");
				}
			}
			else
			{
				$config["lang"] = $extensionPath."/config.php";
			}
		}

		return is_array($config) ? $config : null;
	}

	private static function getPath($extName)
	{
		if (!is_string($extName))
		{
			return null;
		}

		$namespaces = explode(".", $extName);
		if (count($namespaces) < 2)
		{
			return null;
		}

		$path = "js";
		foreach ($namespaces as $namespace)
		{
			if (!preg_match("/^[a-z0-9_\\.\\-]+$/i", $namespace))
			{
				return null;
			}

			$path .= "/".$namespace;
		}

		return \getLocalPath($path, BX_PERSONAL_ROOT);
	}

	public static function getHtml($extName)
	{
		$isRegistered = static::register($extName);

		if ($isRegistered)
		{
			return \CJSCore::getHTML($extName);
		}

		return null;
	}

	public static function getDependencyList($extName)
	{
		return self::getDependencyListRecursive($extName);
	}

	public static function getResourceList($extName, $option = [])
	{
		$skipCoreJS = isset($option['skip_core_js']) && $option['skip_core_js'] === true;
		$withDependency = !(isset($option['with_dependency']) && $option['with_dependency'] === false);

		\CJSCore::init();

		$extensions = [];
		if ($withDependency)
		{
			$resultList = [];
			$alreadyResolved = $skipCoreJS? ['core']: [];

			$extensions = self::getDependencyListRecursive($extName, true, true, $resultList, $alreadyResolved);
		}
		else
		{
			$extensions[] = [$extName, self::getConfig($extName)];
		}

		foreach ($extensions as $index => $extension)
		{
			if(isset($extension[1]['oninit']) && is_callable($extension[1]['oninit']))
			{
				$callbackResult = call_user_func_array(
					$extension[1]['oninit'],
					array($extension[1])
				);

				if(is_array($callbackResult))
				{
					foreach($callbackResult as $key => $value)
					{
						if(!is_array($value))
						{
							$value = array($value);
						}

						if(!isset($extension[1][$key]))
						{
							$extension[1][$key] = array();
						}
						elseif(!is_array($extension[1][$key]))
						{
							$extension[1][$key] = array($extension[1][$key]);
						}

						$extensions[$index][1][$key] = array_merge($extension[1][$key], $value);
					}
				}

				unset($extensions[$index][1]['oninit']);
			}
		}

		$result = [
			'js' => [],
			'css' => [],
			'lang' => [],
			'lang_additional' => [],
		];

		foreach ($extensions as $extension)
		{
			foreach (['js', 'css', 'lang', 'lang_additional'] as $key)
			{
				if (array_key_exists($key, $extension[1]))
				{
					if (is_array($extension[1][$key]))
					{
						$result[$key] = array_merge($result[$key], $extension[1][$key]);
					}
					else
					{
						$result[$key][] = $extension[1][$key];
					}
				}
			}
		}

		return $result;
	}

	private static function getDependencyListRecursive($name, $storeConfig = false, $storeSelf = false, &$resultList = [], &$alreadyResolved = [])
	{
		$config = self::getConfig($name);
		if ($config === null)
		{
			$namespaces = explode(".", $name);
			if (count($namespaces) == 1)
			{
				$config = self::getCoreConfigForDependencyList($name, $storeConfig, $resultList, $alreadyResolved);
			}
		}

		$alreadyResolved[] = $name;

		if ($config && !empty($config['rel']))
		{
			foreach ($config['rel'] as $dependencyName)
			{
				$dependencyConfig = self::getConfig($dependencyName);
				if ($dependencyConfig === null)
				{
					$namespaces = explode(".", $dependencyName);
					if (count($namespaces) == 1)
					{
						$dependencyConfig = self::getCoreConfigForDependencyList($dependencyName, $storeConfig, $resultList, $alreadyResolved);
					}
				}

				if(empty($dependencyConfig['rel']))
				{
					if ($storeConfig)
					{
						$resultList[] = [$dependencyName, $dependencyConfig];
					}
					else
					{
						$resultList[] = $dependencyName;
					}
				}
				else if(!in_array($dependencyName, $alreadyResolved))
				{
					self::getDependencyListRecursive($dependencyName, $storeConfig, true, $resultList, $alreadyResolved);
				}
			}
		}

		if ($storeSelf)
		{
			if ($storeConfig)
			{
				$resultList[] = [$name, $config];
			}
			else
			{
				$resultList[] = $name;
			}
		}

		if (
			$storeConfig
			&& ($config && !empty($config['rel']) || $storeSelf)
		)
		{
			$uniqueArray = [];
			foreach ($resultList as $element)
			{
				$uniqueArray[$element[0]] = $element;
			}
			$resultList = array_values($uniqueArray);
		}
		else
		{
			$resultList = array_unique($resultList);
		}

		return $resultList;
	}

	private static function getCoreConfigForDependencyList($name, $storeConfig = false, &$resultList = [], &$alreadyResolved = [])
	{
		$config = \CJSCore::getExtInfo($name);
		if ($config)
		{
			if (!$config['skip_core'] && !in_array('core', $alreadyResolved))
			{
				$coreAutoload = \CJSCore::getAutoloadExtInfo();
				foreach ($coreAutoload as $coreAutoExt => $coreAutoConfig)
				{
					if ($storeConfig)
					{
						array_unshift($resultList, [$coreAutoExt, $coreAutoConfig]);
					}
					else
					{
						array_unshift($resultList, $coreAutoExt);
					}
				}

				$coreConfig = \CJSCore::GetCoreConfig();
				if ($storeConfig)
				{
					array_unshift($resultList, ['core', $coreConfig]);
				}
				else
				{
					array_unshift($resultList, 'core');
				}

				$alreadyResolved[] = 'core';
			}
		}
		else
		{
			$config = null;
		}

		return $config;
	}
}