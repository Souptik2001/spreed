<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2018 Joas Schilling <coding@schilljs.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Talk\Chat\Parser;

use OCA\Circles\CirclesManager;
use OCA\DAV\CardDAV\PhotoCache;
use OCA\Talk\Chat\ChatManager;
use OCA\Talk\Exceptions\ParticipantNotFoundException;
use OCA\Talk\GuestManager;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\Message;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Share\RoomShareProvider;
use OCP\Comments\IComment;
use OCP\Federation\ICloudIdManager;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IPreview as IPreviewManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Server;
use OCP\Share\Exceptions\ShareNotFound;
use Sabre\VObject\Reader;

class SystemMessage {
	protected IUserManager $userManager;
	protected IGroupManager $groupManager;
	protected GuestManager $guestManager;
	protected IPreviewManager $previewManager;
	protected RoomShareProvider $shareProvider;
	protected PhotoCache $photoCache;
	protected IRootFolder $rootFolder;
	protected ICloudIdManager $cloudIdManager;
	protected IURLGenerator $url;
	protected ?IL10N $l = null;

	/**
	 * @psalm-var array<array-key, null|string>
	 */
	protected array $displayNames = [];
	/** @var string[] */
	protected array $groupNames = [];
	/** @var string[] */
	protected array $circleNames = [];
	/** @var string[] */
	protected array $circleLinks = [];
	/** @var string[] */
	protected array $guestNames = [];

	public function __construct(IUserManager $userManager,
								IGroupManager $groupManager,
								GuestManager $guestManager,
								IPreviewManager $previewManager,
								RoomShareProvider $shareProvider,
								PhotoCache $photoCache,
								IRootFolder $rootFolder,
								ICloudIdManager $cloudIdManager,
								IURLGenerator $url) {
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		$this->guestManager = $guestManager;
		$this->previewManager = $previewManager;
		$this->shareProvider = $shareProvider;
		$this->photoCache = $photoCache;
		$this->rootFolder = $rootFolder;
		$this->cloudIdManager = $cloudIdManager;
		$this->url = $url;
	}

	/**
	 * @param Message $chatMessage
	 * @throws \OutOfBoundsException
	 */
	public function parseMessage(Message $chatMessage): void {
		$this->l = $chatMessage->getL10n();
		$comment = $chatMessage->getComment();
		$room = $chatMessage->getRoom();
		$data = json_decode($chatMessage->getMessage(), true);
		if (!\is_array($data)) {
			throw new \OutOfBoundsException('Invalid message');
		}

		$message = $data['message'];
		$parameters = $data['parameters'];
		$parsedParameters = ['actor' => $this->getActorFromComment($room, $comment)];

		$participant = $chatMessage->getParticipant();
		if (!$participant->isGuest()) {
			$currentActorId = $participant->getAttendee()->getActorId();
			$currentUserIsActor = $parsedParameters['actor']['type'] === 'user' &&
				$participant->getAttendee()->getActorType() === Attendee::ACTOR_USERS &&
				$currentActorId === $parsedParameters['actor']['id'];
		} else {
			$currentActorId = $participant->getAttendee()->getActorId();
			$currentUserIsActor = $parsedParameters['actor']['type'] === 'guest' &&
				$participant->getAttendee()->getActorType() === 'guest' &&
				$participant->getAttendee()->getActorId() === $parsedParameters['actor']['id'];
		}
		$cliIsActor = $parsedParameters['actor']['type'] === 'guest' &&
			'guest/cli' === $parsedParameters['actor']['id'];

		if ($message === 'conversation_created') {
			$parsedMessage = $this->l->t('{actor} created the conversation');
			if ($currentUserIsActor) {
				$parsedMessage = $this->l->t('You created the conversation');
			} elseif ($cliIsActor) {
				$parsedMessage = $this->l->t('An administrator created the conversation');
			}
		} elseif ($message === 'conversation_renamed') {
			$parsedMessage = $this->l->t('{actor} renamed the conversation from "%1$s" to "%2$s"', [$parameters['oldName'], $parameters['newName']]);
			if ($currentUserIsActor) {
				$parsedMessage = $this->l->t('You renamed the conversation from "%1$s" to "%2$s"', [$parameters['oldName'], $parameters['newName']]);
			} elseif ($cliIsActor) {
				$parsedMessage = $this->l->t('An administrator renamed the conversation from "%1$s" to "%2$s"', [$parameters['oldName'], $parameters['newName']]);
			}
		} elseif ($message === 'description_set') {
			$parsedMessage = $this->l->t('{actor} set the description');
			if ($currentUserIsActor) {
				$parsedMessage = $this->l->t('You set the description');
			} elseif ($cliIsActor) {
				$parsedMessage = $this->l->t('An administrator set the description');
			}
		} elseif ($message === 'description_removed') {
			$parsedMessage = $this->l->t('{actor} removed the description');
			if ($currentUserIsActor) {
				$parsedMessage = $this->l->t('You removed the description');
			} elseif ($cliIsActor) {
				$parsedMessage = $this->l->t('An administrator removed the description');
			}
		} elseif ($message === 'call_started') {
			$parsedMessage = $this->l->t('{actor} started a call');
			if ($currentUserIsActor) {
				$parsedMessage = $this->l->t('You started a call');
			}
		} elseif ($message === 'call_joined') {
			$parsedMessage = $this->l->t('{actor} joined the call');
			if ($currentUserIsActor) {
				$parsedMessage = $this->l->t('You joined the call');
			}
		} elseif ($message === 'call_left') {
			$parsedMessage = $this->l->t('{actor} left the call');
			if ($currentUserIsActor) {
				$parsedMessage = $this->l->t('You left the call');
			}
		} elseif ($message === 'call_missed') {
			[$parsedMessage, $parsedParameters, $message] = $this->parseMissedCall($room, $parameters, $currentActorId);
		} elseif ($message === 'call_ended' || $message === 'call_ended_everyone') {
			[$parsedMessage, $parsedParameters] = $this->parseCall($message, $parameters, $parsedParameters);
		} elseif ($message === 'read_only_off') {
			$parsedMessage = $this->l->t('{actor} unlocked the conversation');
			if ($currentUserIsActor) {
				$parsedMessage = $this->l->t('You unlocked the conversation');
			} elseif ($cliIsActor) {
				$parsedMessage = $this->l->t('An administrator unlocked the conversation');
			}
		} elseif ($message === 'read_only') {
			$parsedMessage = $this->l->t('{actor} locked the conversation');
			if ($currentUserIsActor) {
				$parsedMessage = $this->l->t('You locked the conversation');
			} elseif ($cliIsActor) {
				$parsedMessage = $this->l->t('An administrator locked the conversation');
			}
		} elseif ($message === 'listable_none') {
			$parsedMessage = $this->l->t('{actor} limited the conversation to the current participants');
			if ($currentUserIsActor) {
				$parsedMessage = $this->l->t('You limited the conversation to the current participants');
			} elseif ($cliIsActor) {
				$parsedMessage = $this->l->t('An administrator limited the conversation to the current participants');
			}
		} elseif ($message === 'listable_users') {
			$parsedMessage = $this->l->t('{actor} opened the conversation to registered users');
			if ($currentUserIsActor) {
				$parsedMessage = $this->l->t('You opened the conversation to registered users');
			} elseif ($cliIsActor) {
				$parsedMessage = $this->l->t('An administrator opened the conversation to registered users');
			}
		} elseif ($message === 'listable_all') {
			$parsedMessage = $this->l->t('{actor} opened the conversation to registered and guest app users');
			if ($currentUserIsActor) {
				$parsedMessage = $this->l->t('You opened the conversation to registered and guest app users');
			} elseif ($cliIsActor) {
				$parsedMessage = $this->l->t('An administrator opened the conversation to registered and guest app users');
			}
		} elseif ($message === 'lobby_timer_reached') {
			$parsedMessage = $this->l->t('The conversation is now open to everyone');
		} elseif ($message === 'lobby_none') {
			$parsedMessage = $this->l->t('{actor} opened the conversation to everyone');
			if ($currentUserIsActor) {
				$parsedMessage = $this->l->t('You opened the conversation to everyone');
			}
		} elseif ($message === 'lobby_non_moderators') {
			$parsedMessage = $this->l->t('{actor} restricted the conversation to moderators');
			if ($currentUserIsActor) {
				$parsedMessage = $this->l->t('You restricted the conversation to moderators');
			}
		} elseif ($message === 'guests_allowed') {
			$parsedMessage = $this->l->t('{actor} allowed guests');
			if ($currentUserIsActor) {
				$parsedMessage = $this->l->t('You allowed guests');
			} elseif ($cliIsActor) {
				$parsedMessage = $this->l->t('An administrator allowed guests');
			}
		} elseif ($message === 'guests_disallowed') {
			$parsedMessage = $this->l->t('{actor} disallowed guests');
			if ($currentUserIsActor) {
				$parsedMessage = $this->l->t('You disallowed guests');
			} elseif ($cliIsActor) {
				$parsedMessage = $this->l->t('An administrator disallowed guests');
			}
		} elseif ($message === 'password_set') {
			$parsedMessage = $this->l->t('{actor} set a password');
			if ($currentUserIsActor) {
				$parsedMessage = $this->l->t('You set a password');
			} elseif ($cliIsActor) {
				$parsedMessage = $this->l->t('An administrator set a password');
			}
		} elseif ($message === 'password_removed') {
			$parsedMessage = $this->l->t('{actor} removed the password');
			if ($currentUserIsActor) {
				$parsedMessage = $this->l->t('You removed the password');
			} elseif ($cliIsActor) {
				$parsedMessage = $this->l->t('An administrator removed the password');
			}
		} elseif ($message === 'user_added') {
			$parsedParameters['user'] = $this->getUser($parameters['user']);
			$parsedMessage = $this->l->t('{actor} added {user}');
			if ($parsedParameters['user']['id'] === $parsedParameters['actor']['id']) {
				if ($currentUserIsActor) {
					$parsedMessage = $this->l->t('You joined the conversation');
				} else {
					$parsedMessage = $this->l->t('{actor} joined the conversation');
				}
			} elseif ($currentUserIsActor) {
				$parsedMessage = $this->l->t('You added {user}');
			} elseif (!$participant->isGuest() && $currentActorId === $parsedParameters['user']['id']) {
				$parsedMessage = $this->l->t('{actor} added you');
				if ($cliIsActor) {
					$parsedMessage = $this->l->t('An administrator added you');
				}
			} elseif ($cliIsActor) {
				$parsedMessage = $this->l->t('An administrator added {user}');
			}
		} elseif ($message === 'user_removed') {
			$parsedParameters['user'] = $this->getUser($parameters['user']);
			if ($parsedParameters['user']['id'] === $parsedParameters['actor']['id']) {
				if ($currentUserIsActor) {
					$parsedMessage = $this->l->t('You left the conversation');
				} else {
					$parsedMessage = $this->l->t('{actor} left the conversation');
				}
			} else {
				$parsedMessage = $this->l->t('{actor} removed {user}');
				if ($currentUserIsActor) {
					$parsedMessage = $this->l->t('You removed {user}');
				} elseif (!$participant->isGuest() && $currentActorId === $parsedParameters['user']['id']) {
					$parsedMessage = $this->l->t('{actor} removed you');
					if ($cliIsActor) {
						$parsedMessage = $this->l->t('An administrator removed you');
					}
				} elseif ($cliIsActor) {
					$parsedMessage = $this->l->t('An administrator removed {user}');
				}
			}
		} elseif ($message === 'federated_user_added') {
			$parsedParameters['federated_user'] = $this->getRemoteUser($parameters['federated_user']);
			$parsedMessage = $this->l->t('{actor} invited {user}');
			if ($currentUserIsActor) {
				$parsedMessage = $this->l->t('You invited {user}');
			} elseif ($cliIsActor) {
				$parsedMessage = $this->l->t('An administrator invited {user}');
			} elseif ($parsedParameters['federated_user']['id'] === $parsedParameters['actor']['id']) {
				$parsedMessage = $this->l->t('{federated_user} accepted the invitation');
			}
		} elseif ($message === 'federated_user_removed') {
			$parsedParameters['federated_user'] = $this->getRemoteUser($parameters['federated_user']);
			$parsedMessage = $this->l->t('{actor} removed {federated_user}');
			if ($currentUserIsActor) {
				$parsedMessage = $this->l->t('You removed {federated_user}');
			} elseif ($cliIsActor) {
				$parsedMessage = $this->l->t('An administrator removed {federated_user}');
			} elseif ($parsedParameters['federated_user']['id'] === $parsedParameters['actor']['id']) {
				$parsedMessage = $this->l->t('{federated_user} declined the invitation');
			}
		} elseif ($message === 'group_added') {
			$parsedParameters['group'] = $this->getGroup($parameters['group']);
			$parsedMessage = $this->l->t('{actor} added group {group}');
			if ($currentUserIsActor) {
				$parsedMessage = $this->l->t('You added group {group}');
			} elseif ($cliIsActor) {
				$parsedMessage = $this->l->t('An administrator added group {group}');
			}
		} elseif ($message === 'group_removed') {
			$parsedParameters['group'] = $this->getGroup($parameters['group']);
			$parsedMessage = $this->l->t('{actor} removed group {group}');
			if ($currentUserIsActor) {
				$parsedMessage = $this->l->t('You removed group {group}');
			} elseif ($cliIsActor) {
				$parsedMessage = $this->l->t('An administrator removed group {group}');
			}
		} elseif ($message === 'circle_added') {
			$parsedParameters['circle'] = $this->getCircle($parameters['circle']);
			$parsedMessage = $this->l->t('{actor} added circle {circle}');
			if ($currentUserIsActor) {
				$parsedMessage = $this->l->t('You added circle {circle}');
			} elseif ($cliIsActor) {
				$parsedMessage = $this->l->t('An administrator added circle {circle}');
			}
		} elseif ($message === 'circle_removed') {
			$parsedParameters['circle'] = $this->getCircle($parameters['circle']);
			$parsedMessage = $this->l->t('{actor} removed circle {circle}');
			if ($currentUserIsActor) {
				$parsedMessage = $this->l->t('You removed circle {circle}');
			} elseif ($cliIsActor) {
				$parsedMessage = $this->l->t('An administrator removed circle {circle}');
			}
		} elseif ($message === 'moderator_promoted') {
			$parsedParameters['user'] = $this->getUser($parameters['user']);
			$parsedMessage = $this->l->t('{actor} promoted {user} to moderator');
			if ($currentUserIsActor) {
				$parsedMessage = $this->l->t('You promoted {user} to moderator');
			} elseif (!$participant->isGuest() && $currentActorId === $parsedParameters['user']['id']) {
				$parsedMessage = $this->l->t('{actor} promoted you to moderator');
				if ($cliIsActor) {
					$parsedMessage = $this->l->t('An administrator promoted you to moderator');
				}
			} elseif ($cliIsActor) {
				$parsedMessage = $this->l->t('An administrator promoted {user} to moderator');
			}
		} elseif ($message === 'moderator_demoted') {
			$parsedParameters['user'] = $this->getUser($parameters['user']);
			$parsedMessage = $this->l->t('{actor} demoted {user} from moderator');
			if ($currentUserIsActor) {
				$parsedMessage = $this->l->t('You demoted {user} from moderator');
			} elseif (!$participant->isGuest() && $currentActorId === $parsedParameters['user']['id']) {
				$parsedMessage = $this->l->t('{actor} demoted you from moderator');
				if ($cliIsActor) {
					$parsedMessage = $this->l->t('An administrator demoted you from moderator');
				}
			} elseif ($cliIsActor) {
				$parsedMessage = $this->l->t('An administrator demoted {user} from moderator');
			}
		} elseif ($message === 'guest_moderator_promoted') {
			$parsedParameters['user'] = $this->getGuest($room, $parameters['session']);
			$parsedMessage = $this->l->t('{actor} promoted {user} to moderator');
			if ($currentUserIsActor) {
				$parsedMessage = $this->l->t('You promoted {user} to moderator');
			} elseif ($participant->isGuest() && $currentActorId === $parsedParameters['user']['id']) {
				$parsedMessage = $this->l->t('{actor} promoted you to moderator');
				if ($cliIsActor) {
					$parsedMessage = $this->l->t('An administrator promoted you to moderator');
				}
			} elseif ($cliIsActor) {
				$parsedMessage = $this->l->t('An administrator promoted {user} to moderator');
			}
		} elseif ($message === 'guest_moderator_demoted') {
			$parsedParameters['user'] = $this->getGuest($room, $parameters['session']);
			$parsedMessage = $this->l->t('{actor} demoted {user} from moderator');
			if ($currentUserIsActor) {
				$parsedMessage = $this->l->t('You demoted {user} from moderator');
			} elseif ($participant->isGuest() && $currentActorId === $parsedParameters['user']['id']) {
				$parsedMessage = $this->l->t('{actor} demoted you from moderator');
				if ($cliIsActor) {
					$parsedMessage = $this->l->t('An administrator demoted you from moderator');
				}
			} elseif ($cliIsActor) {
				$parsedMessage = $this->l->t('An administrator demoted {user} from moderator');
			}
		} elseif ($message === 'file_shared') {
			try {
				$parsedParameters['file'] = $this->getFileFromShare($participant, $parameters['share']);
				$parsedMessage = '{file}';
				$metaData = $parameters['metaData'] ?? [];
				if (isset($metaData['messageType']) && $metaData['messageType'] === 'voice-message') {
					$chatMessage->setMessageType('voice-message');
				} else {
					$chatMessage->setMessageType(ChatManager::VERB_MESSAGE);
				}
			} catch (\Exception $e) {
				$parsedMessage = $this->l->t('{actor} shared a file which is no longer available');
				if ($currentUserIsActor) {
					$parsedMessage = $this->l->t('You shared a file which is no longer available');
				}
			}
		} elseif ($message === 'object_shared') {
			$parsedParameters['object'] = $parameters['metaData'];
			$parsedMessage = '{object}';

			if (isset($parsedParameters['object']['type'])
				&& $parsedParameters['object']['type'] === 'geo-location'
				&& !preg_match(ChatManager::GEO_LOCATION_VALIDATOR, $parsedParameters['object']['id'])) {
				$parsedParameters = [];
				$parsedMessage = $this->l->t('The shared location is malformed');
			}

			$chatMessage->setMessageType(ChatManager::VERB_MESSAGE);
		} elseif ($message === 'matterbridge_config_added') {
			$parsedMessage = $this->l->t('{actor} set up Matterbridge to synchronize this conversation with other chats');
			if ($currentUserIsActor) {
				$parsedMessage = $this->l->t('You set up Matterbridge to synchronize this conversation with other chats');
			}
		} elseif ($message === 'matterbridge_config_edited') {
			$parsedMessage = $this->l->t('{actor} updated the Matterbridge configuration');
			if ($currentUserIsActor) {
				$parsedMessage = $this->l->t('You updated the Matterbridge configuration');
			}
		} elseif ($message === 'matterbridge_config_removed') {
			$parsedMessage = $this->l->t('{actor} removed the Matterbridge configuration');
			if ($currentUserIsActor) {
				$parsedMessage = $this->l->t('You removed the Matterbridge configuration');
			}
		} elseif ($message === 'matterbridge_config_enabled') {
			$parsedMessage = $this->l->t('{actor} started Matterbridge');
			if ($currentUserIsActor) {
				$parsedMessage = $this->l->t('You started Matterbridge');
			}
		} elseif ($message === 'matterbridge_config_disabled') {
			$parsedMessage = $this->l->t('{actor} stopped Matterbridge');
			if ($currentUserIsActor) {
				$parsedMessage = $this->l->t('You stopped Matterbridge');
			}
		} elseif ($message === 'message_deleted') {
			$parsedMessage = $this->l->t('{actor} deleted a message');
			if ($currentUserIsActor) {
				$parsedMessage = $this->l->t('You deleted a message');
			}
		} elseif ($message === 'reaction_revoked') {
			$parsedMessage = $this->l->t('{actor} deleted a reaction');
			if ($currentUserIsActor) {
				$parsedMessage = $this->l->t('You deleted a reaction');
			}
		} elseif ($message === 'message_expiration_enabled') {
			$weeks = $parameters['seconds'] >= (86400 * 7) ? (int) round($parameters['seconds'] / (86400 * 7)) : 0;
			$days = $parameters['seconds'] >= 86400 ? (int) round($parameters['seconds'] / 86400) : 0;
			$hours = $parameters['seconds'] >= 3600 ? (int) round($parameters['seconds'] / 3600) : 0;
			$minutes = (int) round($parameters['seconds'] / 60);

			$parsedParameters['seconds'] = $parameters['seconds'];
			if ($currentUserIsActor) {
				if ($weeks > 0) {
					$parsedMessage = $this->l->n('You set the message expiration to %n week', 'You set the message expiration to %n weeks', $weeks);
				} elseif ($days > 0) {
					$parsedMessage = $this->l->n('You set the message expiration to %n day', 'You set the message expiration to %n days', $days);
				} elseif ($hours > 0) {
					$parsedMessage = $this->l->n('You set the message expiration to %n hour', 'You set the message expiration to %n hours', $hours);
				} else {
					$parsedMessage = $this->l->n('You set the message expiration to %n minute', 'You set the message expiration to %n minutes', $minutes);
				}
			} else {
				if ($weeks > 0) {
					$parsedMessage = $this->l->n('{actor} set the message expiration to %n week', '{actor} set the message expiration to %n weeks', $weeks);
				} elseif ($days > 0) {
					$parsedMessage = $this->l->n('{actor} set the message expiration to %n day', '{actor} set the message expiration to %n days', $days);
				} elseif ($hours > 0) {
					$parsedMessage = $this->l->n('{actor} set the message expiration to %n hour', '{actor} set the message expiration to %n hours', $hours);
				} else {
					$parsedMessage = $this->l->n('{actor} set the message expiration to %n minute', '{actor} set the message expiration to %n minutes', $minutes);
				}
			}
		} elseif ($message === 'message_expiration_disabled') {
			$parsedMessage = $this->l->t('{actor} disabled message expiration');
			if ($currentUserIsActor) {
				$parsedMessage = $this->l->t('You disabled message expiration');
			}
		} elseif ($message === 'history_cleared') {
			$parsedMessage = $this->l->t('{actor} cleared the history of the conversation');
			if ($currentUserIsActor) {
				$parsedMessage = $this->l->t('You cleared the history of the conversation');
			}
		} elseif ($message === 'poll_closed') {
			$parsedParameters['poll'] = $parameters['poll'];
			$parsedMessage = $this->l->t('{actor} closed the poll {poll}');
			if ($currentUserIsActor) {
				$parsedMessage = $this->l->t('You closed the poll {poll}');
			}
		} elseif ($message === 'poll_voted') {
			$parsedParameters['poll'] = $parameters['poll'];
			$parsedMessage = $this->l->t('Someone voted on the poll {poll}');
			unset($parsedParameters['actor']);
		} else {
			throw new \OutOfBoundsException('Unknown subject');
		}

		$chatMessage->setMessage($parsedMessage, $parsedParameters, $message);
	}

	/**
	 * @param Message $chatMessage
	 * @throws \OutOfBoundsException
	 */
	public function parseDeletedMessage(Message $chatMessage): void {
		$this->l = $chatMessage->getL10n();
		$data = json_decode($chatMessage->getMessage(), true);
		if (!\is_array($data)) {
			throw new \OutOfBoundsException('Invalid message');
		}
		$room = $chatMessage->getRoom();

		$parsedParameters = ['actor' => $this->getActor($room, $data['deleted_by_type'], $data['deleted_by_id'])];

		$participant = $chatMessage->getParticipant();
		$currentActorId = $participant->getAttendee()->getActorId();

		$authorIsActor = $data['deleted_by_type'] === $chatMessage->getComment()->getActorType()
			&& $data['deleted_by_id'] === $chatMessage->getComment()->getActorId();

		if (!$participant->isGuest()) {
			$currentUserIsActor = $parsedParameters['actor']['type'] === 'user' &&
				$participant->getAttendee()->getActorType() === Attendee::ACTOR_USERS &&
				$currentActorId === $parsedParameters['actor']['id'];
		} else {
			$currentUserIsActor = $parsedParameters['actor']['type'] === 'guest' &&
				$participant->getAttendee()->getActorType() === 'guest' &&
				$currentActorId === $parsedParameters['actor']['id'];
		}

		if ($chatMessage->getMessageType() === ChatManager::VERB_MESSAGE_DELETED) {
			$message = 'message_deleted';
			$parsedMessage = $this->l->t('Message deleted by author');

			if (!$authorIsActor) {
				$parsedMessage = $this->l->t('Message deleted by {actor}');
			}
			if ($currentUserIsActor) {
				$parsedMessage = $this->l->t('Message deleted by you');
			}
		} else {
			throw new \OutOfBoundsException('Unknown subject');
		}

		// Overwrite reactions of deleted messages as you can not react to them anymore either
		$chatMessage->getComment()->setReactions([]);

		$chatMessage->setMessage($parsedMessage, $parsedParameters, $message);
	}

	/**
	 * @param Participant $participant
	 * @param string $shareId
	 * @return array
	 * @throws InvalidPathException
	 * @throws NotFoundException
	 * @throws ShareNotFound
	 */
	protected function getFileFromShare(Participant $participant, string $shareId): array {
		$share = $this->shareProvider->getShareById($shareId);
		$node = $share->getNode();
		$name = $node->getName();
		$size = $node->getSize();
		$path = $name;

		if (!$participant->isGuest()) {
			if ($share->getShareOwner() !== $participant->getAttendee()->getActorId()) {
				$userFolder = $this->rootFolder->getUserFolder($participant->getAttendee()->getActorId());
				if ($userFolder instanceof Node) {
					$userNodes = $userFolder->getById($node->getId());

					if (empty($userNodes)) {
						// FIXME This should be much more sensible, e.g.
						// 1. Only be executed on "Waiting for new messages"
						// 2. Once per request
						\OC_Util::tearDownFS();
						\OC_Util::setupFS($participant->getAttendee()->getActorId());
						$userNodes = $userFolder->getById($node->getId());
					}

					if (empty($userNodes)) {
						throw new NotFoundException('File was not found');
					}

					/** @var Node $userNode */
					$userNode = reset($userNodes);
					$fullPath = $userNode->getPath();
					$pathSegments = explode('/', $fullPath, 4);
					$name = $userNode->getName();
					$size = $userNode->getSize();
					$path = $pathSegments[3] ?? $path;
				}
			} else {
				$fullPath = $node->getPath();
				$pathSegments = explode('/', $fullPath, 4);
				$path = $pathSegments[3] ?? $path;
			}

			$url = $this->url->linkToRouteAbsolute('files.viewcontroller.showFile', [
				'fileid' => $node->getId(),
			]);
		} else {
			$url = $this->url->linkToRouteAbsolute('files_sharing.sharecontroller.showShare', [
				'token' => $share->getToken(),
			]);
		}

		$data = [
			'type' => 'file',
			'id' => (string) $node->getId(),
			'name' => $name,
			'size' => $size,
			'path' => $path,
			'link' => $url,
			'mimetype' => $node->getMimeType(),
			'preview-available' => $this->previewManager->isAvailable($node) ? 'yes' : 'no',
		];

		if ($node->getMimeType() === 'text/vcard') {
			$vCard = $node->getContent();

			$vObject = Reader::read($vCard);
			if (!empty($vObject->FN)) {
				$data['contact-name'] = (string) $vObject->FN;
			}

			$photo = $this->photoCache->getPhotoFromVObject($vObject);
			if ($photo) {
				$data['contact-photo-mimetype'] = $photo['Content-Type'];
				$data['contact-photo'] = base64_encode($photo['body']);
			}
		}

		return $data;
	}

	protected function getActorFromComment(Room $room, IComment $comment): array {
		return $this->getActor($room, $comment->getActorType(), $comment->getActorId());
	}

	protected function getActor(Room $room, string $actorType, string $actorId): array {
		if ($actorType === Attendee::ACTOR_GUESTS) {
			return $this->getGuest($room, $actorId);
		}
		if ($actorType === Attendee::ACTOR_FEDERATED_USERS) {
			return $this->getRemoteUser($actorId);
		}

		return $this->getUser($actorId);
	}

	protected function getUser(string $uid): array {
		if (!isset($this->displayNames[$uid])) {
			try {
				$this->displayNames[$uid] = $this->getDisplayName($uid);
			} catch (ParticipantNotFoundException $e) {
				$this->displayNames[$uid] = null;
			}
		}

		if ($this->displayNames[$uid] === null) {
			return [
				'type' => 'highlight',
				'id' => 'deleted_user',
				'name' => $this->l->t('Deleted user'),
			];
		}

		return [
			'type' => 'user',
			'id' => $uid,
			'name' => $this->displayNames[$uid],
		];
	}

	protected function getRemoteUser(string $federationId): array {
		$cloudId = $this->cloudIdManager->resolveCloudId($federationId);

		return [
			'type' => 'user',
			'id' => $cloudId->getUser(),
			'name' => $cloudId->getDisplayId(),
			'server' => $cloudId->getRemote(),
		];
	}

	protected function getDisplayName(string $uid): string {
		$user = $this->userManager->get($uid);
		if ($user instanceof IUser) {
			return $user->getDisplayName();
		}

		throw new ParticipantNotFoundException();
	}

	protected function getGroup(string $gid): array {
		if (!isset($this->groupNames[$gid])) {
			$this->groupNames[$gid] = $this->getDisplayNameGroup($gid);
		}

		return [
			'type' => 'group',
			'id' => $gid,
			'name' => $this->groupNames[$gid],
		];
	}

	protected function getCircle(string $circleId): array {
		if (!isset($this->circleNames[$circleId])) {
			$this->loadCircleDetails($circleId);
		}

		if (!isset($this->circleNames[$circleId])) {
			return [
				'type' => 'highlight',
				'id' => $circleId,
				'name' => $circleId,
			];
		}

		return [
			'type' => 'circle',
			'id' => $circleId,
			'name' => $this->circleNames[$circleId],
			'url' => $this->circleLinks[$circleId],
		];
	}

	protected function getDisplayNameGroup(string $gid): string {
		$group = $this->groupManager->get($gid);
		if ($group instanceof IGroup) {
			return $group->getDisplayName();
		}
		return $gid;
	}

	protected function loadCircleDetails(string $circleId): void {
		try {
			$circlesManager = Server::get(CirclesManager::class);
			$circlesManager->startSuperSession();
			$circle = $circlesManager->getCircle($circleId);
			$circlesManager->stopSession();

			$this->circleNames[$circleId] = $circle->getDisplayName();
			$this->circleLinks[$circleId] = $circle->getUrl();
		} catch (\Exception $e) {
			$circlesManager->stopSession();
		}
	}

	protected function getGuest(Room $room, string $actorId): array {
		if (!isset($this->guestNames[$actorId])) {
			$this->guestNames[$actorId] = $this->getGuestName($room, $actorId);
		}

		return [
			'type' => 'guest',
			'id' => 'guest/' . $actorId,
			'name' => $this->guestNames[$actorId],
		];
	}

	protected function getGuestName(Room $room, string $actorId): string {
		try {
			$participant = $room->getParticipantByActor(Attendee::ACTOR_GUESTS, $actorId);
			$name = $participant->getAttendee()->getDisplayName();
			if ($name === '') {
				return $this->l->t('Guest');
			}
			return $this->l->t('%s (guest)', [$name]);
		} catch (ParticipantNotFoundException $e) {
			return $this->l->t('Guest');
		}
	}

	protected function parseMissedCall(Room $room, array $parameters, string $currentActorId): array {
		if ($parameters['users'][0] !== $currentActorId) {
			return [
				$this->l->t('You missed a call from {user}'),
				[
					'user' => $this->getUser($parameters['users'][0]),
				],
				'call_missed',
			];
		}

		if ($room->getType() !== Room::TYPE_ONE_TO_ONE) {
			// Can happen if a user was remove from a one-to-one room.
			return [
				$this->l->t('You tried to call {user}'),
				[
					'user' => [
						'type' => 'highlight',
						'id' => 'deleted_user',
						'name' => $room->getName(),
					],
				],
				'call_tried',
			];
		}

		$participants = json_decode($room->getName(), true);
		$other = '';
		foreach ($participants as $participant) {
			if ($participant !== $currentActorId) {
				$other = $participant;
			}
		}

		return [
			$this->l->t('You tried to call {user}'),
			[
				'user' => $this->getUser($other),
			],
			'call_tried',
		];
	}


	protected function parseCall(string $message, array $parameters, array $params): array {
		if ($message === 'call_ended_everyone') {
			if ($params['actor']['type'] === 'user') {
				$flipped = array_flip($parameters['users']);
				unset($flipped[$params['actor']['id']]);
				$parameters['users'] = array_flip($flipped);
			} else {
				$parameters['guests']--;
			}
		}
		sort($parameters['users']);
		$numUsers = \count($parameters['users']);
		$displayedUsers = $numUsers;

		switch ($numUsers) {
			case 0:
				if ($message === 'call_ended') {
					$subject = $this->l->n(
						'Call with %n guest (Duration {duration})',
						'Call with %n guests (Duration {duration})',
						$parameters['guests']
					);
				} else {
					$subject = $this->l->n(
						'{actor} ended the call with %n guest (Duration {duration})',
						'{actor} ended the call with %n guests (Duration {duration})',
						$parameters['guests']
					);
				}
				break;
			case 1:
				if ($message === 'call_ended') {
					$subject = $this->l->t('Call with {user1} and {user2} (Duration {duration})');
				} else {
					if ($parameters['guests'] === 0) {
						$subject = $this->l->t('{actor} ended the call with {user1} (Duration {duration})');
					} else {
						$subject = $this->l->t('{actor} ended the call with {user1} and {user2} (Duration {duration})');
					}
				}
				$subject = str_replace('{user2}', $this->l->n('%n guest', '%n guests', $parameters['guests']), $subject);
				break;
			case 2:
				if ($parameters['guests'] === 0) {
					if ($message === 'call_ended') {
						$subject = $this->l->t('Call with {user1} and {user2} (Duration {duration})');
					} else {
						$subject = $this->l->t('{actor} ended the call with {user1} and {user2} (Duration {duration})');
					}
				} else {
					if ($message === 'call_ended') {
						$subject = $this->l->t('Call with {user1}, {user2} and {user3} (Duration {duration})');
					} else {
						$subject = $this->l->t('{actor} ended the call with {user1}, {user2} and {user3} (Duration {duration})');
					}
					$subject = str_replace('{user3}', $this->l->n('%n guest', '%n guests', $parameters['guests']), $subject);
				}
				break;
			case 3:
				if ($parameters['guests'] === 0) {
					if ($message === 'call_ended') {
						$subject = $this->l->t('Call with {user1}, {user2} and {user3} (Duration {duration})');
					} else {
						$subject = $this->l->t('{actor} ended the call with {user1}, {user2} and {user3} (Duration {duration})');
					}
				} else {
					if ($message === 'call_ended') {
						$subject = $this->l->t('Call with {user1}, {user2}, {user3} and {user4} (Duration {duration})');
					} else {
						$subject = $this->l->t('{actor} ended the call with {user1}, {user2}, {user3} and {user4} (Duration {duration})');
					}
					$subject = str_replace('{user4}', $this->l->n('%n guest', '%n guests', $parameters['guests']), $subject);
				}
				break;
			case 4:
				if ($parameters['guests'] === 0) {
					if ($message === 'call_ended') {
						$subject = $this->l->t('Call with {user1}, {user2}, {user3} and {user4} (Duration {duration})');
					} else {
						$subject = $this->l->t('{actor} ended the call with {user1}, {user2}, {user3} and {user4} (Duration {duration})');
					}
				} else {
					if ($message === 'call_ended') {
						$subject = $this->l->t('Call with {user1}, {user2}, {user3}, {user4} and {user5} (Duration {duration})');
					} else {
						$subject = $this->l->t('{actor} ended the call with {user1}, {user2}, {user3}, {user4} and {user5} (Duration {duration})');
					}
					$subject = str_replace('{user5}', $this->l->n('%n guest', '%n guests', $parameters['guests']), $subject);
				}
				break;
			case 5:
			default:
				if ($message === 'call_ended') {
					$subject = $this->l->t('Call with {user1}, {user2}, {user3}, {user4} and {user5} (Duration {duration})');
				} else {
					$subject = $this->l->t('{actor} ended the call with {user1}, {user2}, {user3}, {user4} and {user5} (Duration {duration})');
				}
				if ($numUsers === 5 && $parameters['guests'] === 0) {
					$displayedUsers = 5;
				} else {
					$displayedUsers = 4;
					$numOthers = $parameters['guests'] + $numUsers - $displayedUsers;
					$subject = str_replace('{user5}', $this->l->n('%n other', '%n others', $numOthers), $subject);
				}
		}

		if ($displayedUsers > 0) {
			for ($i = 1; $i <= $displayedUsers; $i++) {
				$params['user' . $i] = $this->getUser($parameters['users'][$i - 1]);
			}
		}

		$subject = str_replace('{duration}', $this->getDuration($parameters['duration']), $subject);
		return [
			$subject,
			$params,
		];
	}

	protected function getDuration(int $seconds): string {
		$hours = floor($seconds / 3600);
		$seconds %= 3600;
		$minutes = floor($seconds / 60);
		$seconds %= 60;

		if ($hours > 0) {
			$duration = sprintf('%1$d:%2$02d:%3$02d', $hours, $minutes, $seconds);
		} else {
			$duration = sprintf('%1$d:%2$02d', $minutes, $seconds);
		}

		return $duration;
	}
}
