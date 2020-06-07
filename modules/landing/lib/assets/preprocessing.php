<?php
namespace Bitrix\Landing\Assets;

use \Bitrix\Landing\Block;
use \Bitrix\Crm\CompanyTable;
use \Bitrix\Landing\Site;

class PreProcessing
{
	/**
	 * Processing the block on adding.
	 * @param Block $block Block instance.
	 * @return void
	 */
	public static function blockAddProcessing(Block $block): void
	{
		PreProcessing\Icon::processing($block);
		PreProcessing\Theme::processing($block);
		self::tempCrmProcessing($block);
	}

	/**
	 * Processing the block on nodes updating.
	 * @param Block $block Block instance.
	 * @return void
	 */
	public static function blockUpdateNodeProcessing(Block $block): void
	{
		PreProcessing\Icon::processing($block);
		self::tempCrmProcessing($block);
	}

	/**
	 * Processing the block on undeleting.
	 * @param Block $block Block instance.
	 * @return void
	 */
	public static function blockUndeleteProcessing(Block $block): void
	{
		PreProcessing\Icon::processing($block);
	}

	/**
	 * Processing the block on output.
	 * @param Block $block Block instance.
	 * @return void
	 */
	public static function blockViewProcessing(Block $block): void
	{
		PreProcessing\Icon::view($block);
	}

	/**
	 * Processing the block on publication
	 * @param Block $block
	 */
	public static function blockPublicationProcessing(Block $block): void
	{
		if(self::isLazyloadEnable($block->getSiteId()))
		{
			PreProcessing\Lazyload::processing($block);
		}
	}

	public static function blockSetDynamicProcessing(Block $block): void
	{
		if(self::isLazyloadEnable($block->getSiteId()))
		{
			PreProcessing\Lazyload::processingDynamic($block);
		}
	}

	protected static function isLazyloadEnable($siteId)
	{
		static $result;
		if ($result !== null)
		{
			return $result;
		}

		$hooks = Site::getHooks($siteId);
		$result =
			array_key_exists('SPEED', $hooks)
			&& $hooks['SPEED']->getPageFields()['SPEED_USE_LAZY']->getValue() === 'Y';

		return $result;
	}

	/**
	 * Temporary method for replacing phones and emails from CMR requisites.
	 * @param Block $block Block instance.
	 * @return void
	 */
	private static function tempCrmProcessing(Block $block): void
	{
		$emails = [];
		$phones = [];
		$company = null;

		// get requisites from my companies
		if (\Bitrix\Main\Loader::includeModule('crm'))
		{
			$res = CompanyTable::getList([
				'select' => [
					'ID', 'TITLE'
				],
				'filter' => [
					'=IS_MY_COMPANY' => 'Y'
				],
				'order' => [
					'DATE_MODIFY' => 'desc'
				]
			]);
			if ($row = $res->fetch())
			{
				$company = $row['TITLE'];
				$res = \CCrmFieldMulti::GetListEx(
					[],
					[
						'=ENTITY_ID' => 'company',
						'=ELEMENT_ID' => $row['ID']
					]
				);
				while ($row = $res->fetch())
				{
					if ($row['TYPE_ID'] == 'EMAIL')
					{
						$emails[count($emails)+1] = $row['VALUE'];
					}
					else if ($row['TYPE_ID'] == 'PHONE')
					{
						$phones[count($phones)+1] = $row['VALUE'];
					}
				}
			}
		}

		// if phones or email found, replace markers
		$replaced = 0;
		$content = $block->getContent();
		$content = preg_replace_callback(
			'/#(PHONE|EMAIL)([\d]+)#/',
			function($matches) use($phones, $emails)
			{
				$key = $matches[2];
				$sources = ($matches[1] == 'PHONE') ? $phones : $emails;
				if (isset($sources[$key]))
				{
					return $sources[$key];
				}
				else
				{
					return ($matches[1] == 'PHONE') ? '+123456789' : 'info@company24.com';
				}
			},
			$content, -1, $replaced
		);
		if(strpos($content, '#COMPANY#') !== false)
		{
			$company = $company ?? 'Company24';
			$content = str_replace('#COMPANY#', $company, $content);
			$replaced++;
		}

		if ($replaced)
		{
			$block->saveContent($content);
			$block->save();
		}
	}
}