<?php
namespace Bitrix\Landing\Subtype;

use \Bitrix\Main\Localization\Loc;
use \Bitrix\Landing\Manager;
use \Bitrix\Main\Loader;

Loc::loadMessages(__FILE__);

class Openlines
{
	/**
	 * Prepare manifest.
	 * @param array $manifest Block's manifest.
	 * @param \Bitrix\Landing\Block $block Block instance.
	 * @param array $params Additional params.
	 * @return array
	 * @noinspection PhpUnused
	 */
	public static function prepareManifest(array $manifest, \Bitrix\Landing\Block $block = null, array $params = array())
	{
		if (Manager::isB24())
		{
			$link = '/crm/button/';
		}
		else if (Manager::isB24Connector())
		{
			$link = '/bitrix/admin/b24connector_buttons.php?lang=' . LANGUAGE_ID;
		}
		if (isset($link))
		{
			$manifest['block']['attrsFormDescription'] = '<a href="' . $link . '" target="_blank">' .
				Loc::getMessage('LANDING_BLOCK_BUTTONS_CONFIG') .
				'</a>';
		}

		return $manifest;
	}
}
