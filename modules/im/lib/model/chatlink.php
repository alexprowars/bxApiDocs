<?php
namespace Bitrix\Im\Model;

use Bitrix\Main,
	Bitrix\Main\Localization\Loc;
Loc::loadMessages(__FILE__);

/**
 * Class ChatLink
 *
 * Fields:
 * <ul>
 * <li> ID int mandatory
 * <li> CHAT_ID int mandatory
 * <li> SERVICE_ID string mandatory
 * <li> CONFERENCE_URL string mandatory
 * <li> CONFERENCE_EXTERNAL_ID string mandatory
 * </ul>
 *
 * @package Bitrix\Im
 **/

class ChatLinkTable extends Main\Entity\DataManager
{
	/**
	 * Returns DB table name for entity.
	 *
	 * @return string
	 */
	public static function getTableName(): string
	{
		return 'b_im_chat_link';
	}

	/**
	 * Returns entity map definition.
	 *
	 * @return array
	 */
	public static function getMap(): array
	{
		return array(
			'ID' => array(
				'data_type' => 'integer',
				'primary' => true,
				'autocomplete' => true,
				'title' => Loc::getMessage('CHAT_LINK_ENTITY_ID_FIELD'),
			),
			'CHAT_ID' => array(
				'data_type' => 'integer',
				'required' => true,
				'title' => Loc::getMessage('CHAT_LINK_ENTITY_CHAT_ID_FIELD'),
			),
			'SERVICE_ID' => array(
				'data_type' => 'text',
				'required' => true,
				'title' => Loc::getMessage('CHAT_LINK_ENTITY_SERVICE_ID_FIELD'),
			),
			'CONFERENCE_URL' => array(
				'data_type' => 'text',
				'required' => true,
				'title' => Loc::getMessage('CHAT_LINK_ENTITY_CONF_URL_FIELD'),
			),
			'CONFERENCE_EXTERNAL_ID' => array(
				'data_type' => 'text',
				'required' => true,
				'title' => Loc::getMessage('CHAT_LINK_ENTITY_CONF_EXTERNAL_ID_FIELD'),
			),
		);
	}
}