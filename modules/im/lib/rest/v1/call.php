<?php

namespace Bitrix\Im\Rest\v1;

use Bitrix\Im\Call\CallUser;
use Bitrix\Im\Call\Registry;
use Bitrix\Main\Context;
use Bitrix\Main\Engine;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;

class Call extends Engine\Controller
{
	public function createAction($type, $provider, $entityType, $entityId, $joinExisting = false)
	{
		$currentUserId = $this->getCurrentUser()->getId();

		if($joinExisting)
		{
			$call = \Bitrix\Im\Call\Call::searchActive($type, $provider, $entityType, $entityId);
			if($call && !$call->getAssociatedEntity()->checkAccess($currentUserId))
			{
				$this->errorCollection[] = new Error("You can not access this call", 'access_denied');
				return null;
			}
		}

		if($call)
		{
			$isNew = false;
			if(!$call->hasUser($currentUserId))
			{
				$call->addUser($currentUserId);
			}
		}
		else
		{
			$isNew = true;
			$call = \Bitrix\Im\Call\Call::createWithEntity($type, $provider, $entityType, $entityId, $currentUserId);
			if(!$call->getAssociatedEntity()->checkAccess($currentUserId))
			{
				$this->errorCollection[] = new Error("You can not create this call", 'access_denied');
				return null;
			}
			$initiator = $call->getUser($currentUserId);
			$initiator->updateState(CallUser::STATE_READY);
			$initiator->updateLastSeen(new DateTime());
		}

		$users = $call->getUsers();
		$publicChannels = Loader::includeModule('pull') ?
			\Bitrix\Pull\Channel::getPublicIds([
				'TYPE' => \CPullChannel::TYPE_PRIVATE,
				'USERS' => $users,
				'JSON' => true
			])
			:
			[];

		return [
			'call' => $call->toArray(),
			'isNew' => $isNew,
			'users' => $users,
			'userData' => \CIMContactList::GetUserData(['ID' => $users, 'DEPARTMENT' => 'N', 'HR_PHOTO' => 'Y']),
			'publicChannels' =>$publicChannels
		];
	}

	public function createChildCallAction($parentId, $newProvider, $newUsers)
	{
		$currentUserId = $this->getCurrentUser()->getId();

		$parentCall = Registry::getCallWithId($parentId);
		if(!$this->checkCallAccess($parentCall, $currentUserId))
		{
			$this->errorCollection[] = new Error("You do not have access to the parent call", "access_denied");
			return null;
		}

		$childCall = $parentCall->makeClone($newProvider);

		$initiator = $childCall->getUser($currentUserId);
		$initiator->updateState(CallUser::STATE_READY);
		$initiator->updateLastSeen(new DateTime());

		foreach ($newUsers as $userId)
		{
			if(!$childCall->hasUser($userId))
			{
				$childCall->addUser($userId)->updateState(CallUser::STATE_CALLING);;
			}
		}

		$users = $childCall->getUsers();
		return array(
			'call' => $childCall->toArray(),
			'users' => $users,
			'userData' => \CIMContactList::GetUserData(Array('ID' => $users, 'DEPARTMENT' => 'N', 'HR_PHOTO' => 'Y')),
		);
	}

	public function getAction($callId)
	{
		$currentUserId = $this->getCurrentUser()->getId();

		$call = Registry::getCallWithId($callId);
		if(!$this->checkCallAccess($call, $currentUserId))
		{
			$this->errorCollection[] = new Error("You do not have access to the parent call", "access_denied");
			return null;
		}

		$users = $call->getUsers();
		return array(
			'call' => $call->toArray($currentUserId),
			'users' => $users,
			'userData' => \CIMContactList::GetUserData(Array('ID' => $users, 'DEPARTMENT' => 'N', 'HR_PHOTO' => 'Y')),
		);
	}

	public function inviteAction($callId, array $userIds, $video = "N")
	{
		$isVideo = ($video === "Y");
		$currentUserId = $this->getCurrentUser()->getId();
		$call = Registry::getCallWithId($callId);

		if(!$this->checkCallAccess($call, $currentUserId))
			return null;

		$userAgent = Context::getCurrent()->getRequest()->getUserAgent();
		$isMobile = strpos($userAgent, "Bitrix24") !== false;
		$call->getUser($currentUserId)->update([
			'LAST_SEEN' => new DateTime(),
			'IS_MOBILE' => $isMobile ? 'Y' : 'N'
		]);

		$usersToInvite = [];
		foreach ($userIds as $userId)
		{
			if(!$call->hasUser($userId))
			{
				if(!$call->addUser($userId))
				{
					continue;
				}
			}
			$usersToInvite[] = $userId;
			$callUser = $call->getUser($userId);
			if($callUser->getState() != CallUser::STATE_READY)
			{
				$callUser->updateState(CallUser::STATE_CALLING);
			}
		}

		// send invite to the ones being invited.
		$call->getSignaling()->sendInvite($currentUserId, $usersToInvite, $isMobile, $isVideo);

		// send userInvited to everyone else.
		$allUsers = $call->getUsers();
		$otherUsers = array_diff($allUsers, $userIds);
		$call->getSignaling()->sendUsersInvited($currentUserId, $otherUsers, $usersToInvite);

		if($call->getState() == \Bitrix\Im\Call\Call::STATE_NEW)
		{
			$call->updateState(\Bitrix\Im\Call\Call::STATE_INVITING);
		}

		return true;
	}

	public function cancelAction($callId)
	{
		$currentUserId = $this->getCurrentUser()->getId();
		$call = Registry::getCallWithId($callId);

		if(!$this->checkCallAccess($call, $currentUserId))
			return null;
	}

	public function answerAction($callId, $callInstanceId)
	{
		$currentUserId = $this->getCurrentUser()->getId();
		$call = Registry::getCallWithId($callId);
		$userAgent = Context::getCurrent()->getRequest()->getUserAgent();
		$isMobile = strpos($userAgent, "Bitrix24") !== false;

		if(!$this->checkCallAccess($call, $currentUserId))
			return null;

		$callUser = $call->getUser($currentUserId);
		if($callUser)
		{
			$callUser->update([
				'STATE' => CallUser::STATE_READY,
				'LAST_SEEN' => new DateTime(),
				'IS_MOBILE' => $isMobile ? 'Y' : 'N'
			]);
		}

		$call->getSignaling()->sendAnswer($currentUserId, $callInstanceId, $isMobile);
	}

	public function declineAction($callId, $callInstanceId, $code = 603)
	{
		$currentUserId = $this->getCurrentUser()->getId();
		$call = Registry::getCallWithId($callId);

		if(!$this->checkCallAccess($call, $currentUserId))
			return null;

		$callUser = $call->getUser($currentUserId);
		if($callUser)
		{
			if($code == 486)
			{
				$callUser->updateState(CallUser::STATE_BUSY);
			}
			else
			{
				$callUser->updateState(CallUser::STATE_DECLINED);
			}
			$callUser->updateLastSeen(new DateTime());
		}

		$userIds = $call->getUsers();
		$call->getSignaling()->sendHangup($currentUserId, $userIds, $callInstanceId, $code);

		if(!$call->hasActiveUsers())
		{
			$call->finish();
		}
	}

	/**
	 * @param $callId
	 * @return bool
	 */
	public function pingAction($callId, $requestId, $retransmit = true)
	{
		$currentUserId = $this->getCurrentUser()->getId();
		$call = Registry::getCallWithId($callId);

		if(!$this->checkCallAccess($call, $currentUserId))
			return null;

		$callUser = $call->getUser($currentUserId);
		if($callUser)
		{
			$callUser->updateLastSeen(new DateTime());
			if($callUser->getState() == CallUser::STATE_UNAVAILABLE)
			{
				$callUser->updateState(CallUser::STATE_IDLE);
			}
		}

		if($retransmit)
		{
			$call->getSignaling()->sendPing($currentUserId, $requestId);
		}

		return true;
	}

	public function negotiationNeededAction($callId, $userId, $restart = false)
	{
		$restart = (bool)$restart;
		$currentUserId = $this->getCurrentUser()->getId();
		$call = Registry::getCallWithId($callId);

		if(!$this->checkCallAccess($call, $currentUserId))
			return null;

		$callUser = $call->getUser($currentUserId);
		if($callUser)
		{
			$callUser->updateLastSeen(new DateTime());
		}

		$call->getSignaling()->sendNegotiationNeeded($currentUserId, $userId, $restart);
		return true;
	}

	public function connectionOfferAction($callId, $userId, $connectionId, $sdp, $userAgent)
	{
		$currentUserId = $this->getCurrentUser()->getId();
		$call = Registry::getCallWithId($callId);

		if(!$this->checkCallAccess($call, $currentUserId))
			return null;

		$callUser = $call->getUser($currentUserId);
		if($callUser)
		{
			$callUser->updateLastSeen(new DateTime());
		}

		$call->getSignaling()->sendConnectionOffer($currentUserId, $userId, $connectionId, $sdp, $userAgent);
		return true;
	}

	public function connectionAnswerAction($callId, $userId, $connectionId, $sdp, $userAgent)
	{
		$currentUserId = $this->getCurrentUser()->getId();
		$call = Registry::getCallWithId($callId);

		if(!$this->checkCallAccess($call, $currentUserId))
			return null;

		$callUser = $call->getUser($currentUserId);
		if($callUser)
		{
			$callUser->updateLastSeen(new DateTime());
		}

		$call->getSignaling()->sendConnectionAnswer($currentUserId, $userId, $connectionId, $sdp, $userAgent);
		return true;
	}

	public function iceCandidateAction($callId, $userId, $connectionId, array $candidates)
	{
		// mobile can alter key order, so we recover it
		ksort($candidates);

		$currentUserId = $this->getCurrentUser()->getId();
		$call = Registry::getCallWithId($callId);

		if(!$this->checkCallAccess($call, $currentUserId))
			return null;

		$callUser = $call->getUser($currentUserId);
		if($callUser)
		{
			$callUser->updateLastSeen(new DateTime());
		}

		$call->getSignaling()->sendIceCandidates($currentUserId, $userId, $connectionId, $candidates);
		return true;
	}

	public function hangupAction($callId, $callInstanceId, $retransmit = true)
	{
		$currentUserId = $this->getCurrentUser()->getId();
		$call = Registry::getCallWithId($callId);

		if(!$this->checkCallAccess($call, $currentUserId))
			return null;

		$callUser = $call->getUser($currentUserId);
		if($callUser)
		{
			$callUser->updateState(CallUser::STATE_IDLE);
			$callUser->updateLastSeen(new DateTime());
		}

		$userIds = $call->getUsers();
		if($retransmit)
		{
			$call->getSignaling()->sendHangup($currentUserId, $userIds, $callInstanceId);
		}

		if(!$call->hasActiveUsers())
		{
			$call->finish();
		}
	}

	protected function checkCallAccess(\Bitrix\Im\Call\Call $call, $userId)
	{
		if(!$call->checkAccess($userId))
		{
			$this->errorCollection[] = new Error("You don't have access to the call " . $call->getId() . "; (current user id: " . $userId . ")", 'access_denied');
			return false;
		}
		else
		{
			return true;
		}
	}
}