<?
namespace Bitrix\Main\Controller;

use Bitrix\Main\Engine;
use Bitrix\Main\UI\Extension;

/**
 * Class LoadExt
 * @package Bitrix\Main\Controller
 */
class LoadExt extends Engine\Controller
{
	/**
	 * @param array $extension
	 * @return array
	 */
	public function getExtensionsAction($extension = [])
	{
		$result = [];

		if (!empty($extension) && is_array($extension))
		{
			foreach ($extension as $key => $item)
			{
				$result[] = [
					"extension" => $item,
					"html" => Extension::getHtml($item)
				];
			}
		}

		return $result;
	}
}