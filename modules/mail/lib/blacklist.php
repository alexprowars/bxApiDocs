<?php

namespace Bitrix\Mail;

use Bitrix\Main\Application;
use Bitrix\Main\Entity;
use Bitrix\Main\Localization;

Localization\Loc::loadMessages(__FILE__);

class BlacklistTable extends Entity\DataManager
{

	public static function getFilePath()
	{
		return __FILE__;
	}

	public static function getTableName()
	{
		return 'b_mail_blacklist';
	}

	public static function replace($siteId, $mailboxId, array $list)
	{
		global $DB;

		if ($mailboxId > 0)
			$DB->query(sprintf("DELETE FROM b_mail_blacklist WHERE MAILBOX_ID = %u", $mailboxId));
		else
			$DB->query(sprintf("DELETE FROM b_mail_blacklist WHERE SITE_ID = '%s' AND MAILBOX_ID = 0", $DB->forSql($siteId)));

		if (!empty($list))
		{
			foreach ($list as $item)
			{
				static::add(array(
					'SITE_ID'    => $siteId,
					'MAILBOX_ID' => $mailboxId,
					'ITEM_TYPE'  => Blacklist\ItemType::resolveByValue($item),
					'ITEM_VALUE' => $item,
				));
			}
		}
	}

	/**
	 * @param array $list
	 * @param array $mailbox
	 * @return int
	 * @throws \Bitrix\Main\Db\SqlQueryException
	 */
	public static function addMailsBatch(array $list, $userId = null)
	{
		if (empty($list))
		{
			return 0;
		}
		if (is_null($userId))
		{
			$userId = 0;
		}
		$sqlHelper = Application::getConnection()->getSqlHelper();
		$addList = [];
		foreach ($list as $index => $item)
		{
			$itemToAdd = [
				'SITE_ID' => SITE_ID,
				'MAILBOX_ID' => 0,
				'USER_ID' => $userId,
				'ITEM_TYPE' => Blacklist\ItemType::resolveByValue($item),
				'ITEM_VALUE' => $item,
			];
			$addList[] = $itemToAdd;
		}

		if (count($addList) === 0)
		{
			return 0;
		}
		$keys = implode(', ', array_keys(current($addList)));
		$values = [];
		foreach ($addList as $item)
		{
			$values[] = implode(
				", ",
				[
					"'" . $sqlHelper->forSql($item['SITE_ID']) . "'",
					(int)$item['MAILBOX_ID'],
					(int)$item['USER_ID'],
					$item['ITEM_TYPE'],
					"'" . $sqlHelper->forSql($item['ITEM_VALUE']) . "'",
				]
			);
		}
		$values = implode('), (', $values);

		$tableName = static::getTableName();
		$sql = "INSERT IGNORE $tableName($keys) VALUES($values)";
		Application::getConnection()->query($sql);
		return Application::getConnection()->getAffectedRowsCount();
	}

	public static function getMap()
	{
		return array(
			'ID' => array(
				'data_type'    => 'integer',
				'primary'      => true,
				'autocomplete' => true,
			),
			'SITE_ID' => array(
				'data_type' => 'string',
				'required'  => true,
			),
			'MAILBOX_ID' => array(
				'data_type' => 'integer',
			),
			'USER_ID' => array(
				'data_type' => 'integer',
			),
			'ITEM_TYPE' => array(
				'data_type' => 'integer',
				'required'  => true,
			),
			'ITEM_VALUE' => array(
				'data_type' => 'string',
				'required'  => true,
				'fetch_data_modification' => function()
				{
					return array(
						function ($value, $query, $data)
						{
							if (Blacklist\ItemType::DOMAIN == $data['ITEM_TYPE'])
								$value = sprintf('@%s', $value);

							return $value;
						}
					);
				}
			),
		);
	}

}
