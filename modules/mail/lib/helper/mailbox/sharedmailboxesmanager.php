<?php
namespace Bitrix\Mail\Helper\Mailbox;

use Bitrix\Mail\Internals\MailboxAccessTable;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Entity\Query\Filter\Expression\Column;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;

class SharedMailboxesManager
{
	public static function getSharedMailboxesCount()
	{
		$count = static::getBaseQueryForSharedMailboxes()
			->addSelect(Query::expr()->countDistinct('MAILBOX_ID'), 'CNT')
			->exec()
			->fetch();
		return !empty($count['CNT']) ? $count['CNT'] : 0;
	}

	public static function getSharedMailboxesIds()
	{
		$count = static::getBaseQueryForSharedMailboxes()
			->addSelect('MAILBOX_ID')
			->addGroup('MAILBOX_ID')
			->exec()
			->fetchAll();
		return array_map('intval', array_column($count, 'MAILBOX_ID'));
	}

	private static function getBaseQueryForSharedMailboxes()
	{
		return MailboxAccessTable::query()
			->registerRuntimeField('', new ReferenceField('ref', MailboxTable::class, ['=this.MAILBOX_ID' => 'ref.ID'], ['join_type' => 'INNER']))
			->where(new ExpressionField('ac', 'CONCAT("U", %s)', 'ref.USER_ID'), '!=', new Column('ACCESS_CODE'))
			->where('ref.ACTIVE', 'Y')
			->where('ref.LID', SITE_ID);
	}
}