<?php

namespace Bitrix\Main\UserField\Internal;

/**
 * @deprecated
 */
abstract class TypeFactory
{
	/**
	 * @return TypeDataManager
	 */
	abstract public function getTypeDataClass(): string;

	/**
	 * @return PrototypeItemDataManager
	 */
	abstract public function getItemPrototypeDataClass(): string;

	abstract public function getCode(): string;

	/**
	 * @param mixed $type
	 * @return PrototypeItemDataManager
	 */
	public function getItemDataClass($type): string
	{
		return $this->getTypeDataClass()::compileEntity($type)->getDataClass();
	}

	/**
	 * @return Item
	 */
	public function getItemParentClass(): string
	{
		return Item::class;
	}

	public function getUserFieldEntityPrefix(): string
	{
		$code = $this->getCode();
		return static::getPrefixByCode($code).'_';
	}

	public function getUserFieldEntityId(int $typeId): string
	{
		return $this->getUserFieldEntityPrefix().$typeId;
	}

	public static function getCodeByPrefix(string $prefix): string
	{
		return strtolower($prefix);
	}

	public static function getPrefixByCode(string $code): string
	{
		return strtoupper($code);
	}
}