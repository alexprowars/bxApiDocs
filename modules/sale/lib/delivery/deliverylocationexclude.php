<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage sale
 * @copyright 2001-2014 Bitrix
 */
namespace Bitrix\Sale\Delivery;

final class DeliveryLocationExcludeTable extends DeliveryLocationTable
{
	const DB_LOCATION_FLAG = 'LE';
	const DB_GROUP_FLAG = 	'GE';

	public static function getFilePath()
	{
		return __FILE__;
	}
}
