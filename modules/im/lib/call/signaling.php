<?php

namespace Bitrix\Im\Call;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

class Signaling
{
	protected  $call;

	public function __construct(Call $call)
	{
		$this->call = $call;
	}

	public function sendInvite(int $senderId, array $toUserIds, $isMobile, $video = false)
	{
		$users = $this->call->getUsers();
		$userData = \CIMContactList::getUserData(['ID' => $users, 'DEPARTMENT' => 'N', 'HR_PHOTO' => 'Y']);
		$config = array(
			'call' => $this->call->toArray((count($toUserIds) == 1 ? $toUserIds[0] : 0)),
			'users' => $users,
			'invitedUsers' => $toUserIds,
			'userData' => $userData,
			'senderId' => $senderId,
			'publicIds' => $this->getPublicIds($users),
			'isMobile' => $isMobile,
			'video' => $video
		);

		if(count($users) == 2 && $this->call->getProvider() === Call::PROVIDER_PLAIN)
		{
			$name = '';
			if($this->call->getAssociatedEntity())
			{
				$name = $this->call->getAssociatedEntity()->getName($toUserIds[0]);
			}

			if($name != '')
			{
				$pushText = Loc::getMessage('IM_CALL_INVITE', ['#USER_NAME#' => $name]);
			}
			else
			{
				$pushText = Loc::getMessage('IM_CALL_INVITE', ['#USER_NAME#' => Loc::getMessage('IM_CALL_INVITE_NA')]);
			}

			$senderUserData = \CIMContactList::getUserData(['ID' => $users, 'DEPARTMENT' => 'N', 'HR_PHOTO' => 'Y']);
			$senderUserData = [
				'users' => [
					$senderId => $senderUserData['users'][$senderId]
				],
				'hrphoto' => [
					$senderId => $senderUserData['hrphoto'][$senderId]
				],
			];
			$userDataForPush = [
				'users' => array_map(
					function($element)
					{
						return[
							'id' => $element['id'],
							'name' => $element['name']
						];
					},
					$senderUserData['users']
				),
				'hrphoto' => $senderUserData['hrphoto']
			];

			$push = [
				'message' => $pushText,
				'expiry' => 0,
				'params' => [
					'ACTION' => 'IMINV_' . $this->call->getId() . "_" . time() . "_" . ($video ? 'Y' : 'N'),
					'PARAMS' => [
						'call' => [
							'ID' => $this->call->getId(),
							'PROVIDER' => $this->call->getProvider()
						],
						'video' => $video,
						'users' => $users,
						'userData' => $userDataForPush,
						'isMobile' => $isMobile,
						'senderId' => $senderId
					]
				],
				'advanced_params' => [
					'id' => 'IM_CALL_'.$this->call->getId(),
					'notificationsToCancel' => ['IM_CALL_'.$this->call->getId()],
					'androidHighPriority' => true,
					'useVibration' => true
				],
				'sound' => 'call.aif',
				'send_immediately' => 'Y'
			];
		}
		else
		{
			$push = null;
		}

		return $this->send('Call::incoming', $toUserIds, $config, $push);
	}

	public function sendUsersInvited(int $senderId, array $toUserIds, array $users)
	{
		$config = array(
			'call' => $this->call->toArray(),
			'users' => $users,
			'userData' => \CIMContactList::GetUserData(Array('ID' => $users, 'DEPARTMENT' => 'N', 'HR_PHOTO' => 'Y')),
			'senderId' => $senderId,
			'publicIds' => $this->getPublicIds($users),
		);

		return $this->send('Call::usersInvited', $toUserIds, $config);
	}

	public function sendAssociatedEntityReplaced(int $senderId)
	{
		$config = array(
			'call' => $this->call->toArray(),
			'senderId' => $senderId,
		);

		$toUserIds = $this->call->getUsers();

		return $this->send('Call::associatedEntityReplaced', $toUserIds, $config);
	}

	public function sendAnswer(int $senderId, $callInstanceId, $isMobile)
	{
		$config = array(
			'call' => $this->call->toArray(),
			'senderId' => $senderId,
			'callInstanceId' => $callInstanceId,
			'isMobile' => $isMobile,
		);

		$toUserIds = $this->call->getUsers();

		return $this->send('Call::answer', $toUserIds, $config, $this->getCancelingPush());
	}

	public function sendPing(int $senderId, $requestId)
	{
		$config = array(
			'requestId' => $requestId,
			'callId' => $this->call->getId(),
			'senderId' => $senderId
		);

		$toUserIds = $this->call->getUsers();
		$toUserIds = array_filter($toUserIds, function($value) use ($senderId) {
			return $value != $senderId;
		});
		return $this->send('Call::ping', $toUserIds, $config, null, 0);
	}

	public function sendNegotiationNeeded(int $senderId, int $toUserId, $restart)
	{
		return $this->send('Call::negotiationNeeded', $toUserId, array(
			'senderId' => $senderId,
			'restart' => $restart
		));
	}

	public function sendConnectionOffer(int $senderId, int $toUserId, string $connectionId, string $offerSdp, string $userAgent)
	{
		return $this->send('Call::connectionOffer', $toUserId, array(
			'senderId' => $senderId,
			'connectionId' => $connectionId,
			'sdp' => $offerSdp,
			'userAgent' => $userAgent
		));
	}

	public function sendConnectionAnswer(int $senderId, int $toUserId, string $connectionId, string $answerSdp, string $userAgent)
	{
		return $this->send('Call::connectionAnswer', $toUserId, array(
			'senderId' => $senderId,
			'connectionId' => $connectionId,
			'sdp' => $answerSdp,
			'userAgent' => $userAgent
		));
	}

	public function sendIceCandidates(int $senderId, int $toUserId, string $connectionId, array $iceCandidates)
	{
		return $this->send('Call::iceCandidate', $toUserId, array(
			'senderId' => $senderId,
			'connectionId' => $connectionId,
			'candidates' => $iceCandidates
		));
	}

	public function sendHangup(int $senderId, array $toUserIds, string $callInstanceId, $code = 200)
	{
		if(count($this->call->getUsers()) == 2 && $this->call->getProvider() === Call::PROVIDER_PLAIN)
		{
			$push = $this->getCancelingPush();
		}
		else
		{
			$push = null;
		}

		return $this->send('Call::hangup', $toUserIds, array(
			'senderId' => $senderId,
			'callInstanceId' => $callInstanceId,
			'code' => $code,
			'push' => $push
		));
	}

	public function sendFinish()
	{
		if(count($this->call->getUsers()) == 2 && $this->call->getProvider() === Call::PROVIDER_PLAIN)
		{
			$push = $this->getCancelingPush();
		}
		else
		{
			$push = null;
		}

		return $this->send('Call::finish', $this->call->getUsers(), [], $push);
	}

	protected function getPublicIds(array $userIds)
	{
		if(!Loader::includeModule('pull'))
		{
			return [];
		}

		return \Bitrix\Pull\Channel::getPublicIds([
			'USERS' => $userIds,
			'JSON' => true
		]);
	}

	protected function getCancelingPush()
	{
		return [
			'send_immediately' => 'Y',
			'advanced_params' => [
				"notificationsToCancel" => ['IM_CALL_'.$this->call->getId()],
			]
		];
	}

	protected function send(string $command, $users, array $params = [], $push = null, $ttl = 5)
	{
		if(!Loader::includeModule('pull'))
			return false;

		if(!isset($params['call']))
			$params['call'] = ['ID' => $this->call->getId()];

		if(!isset($params['callId']))
			$params['callId'] = $this->call->getId();

		\Bitrix\Pull\Event::add($users, array(
			'module_id' => 'im',
			'command' => $command,
			'params' => $params,
			'push' => $push,
			'expiry' => $ttl
		));

		return true;
	}
}