<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage main
 * @copyright 2001-2012 Bitrix
 */

namespace Bitrix\Main\Entity;

/**
 * Entity field class for datetime data type
 * @package bitrix
 * @subpackage main
 */
class DatetimeField extends DateField
{
	public function __construct($name, $parameters = array())
	{
		ScalarField::__construct($name, $parameters);
	}

	public function convertValueToDb($value)
	{
		return $this->getConnection()->getSqlHelper()->convertToDbDateTime($value);
	}
}