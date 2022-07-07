<?php

declare(strict_types=1);

namespace alvin0319\SmithingTable;

use alvin0319\SmithingTable\block\SmithingTable;
use alvin0319\SmithingTable\inventory\SmithingTableInventory;
use alvin0319\SmithingTable\inventory\TransactionInventory;
use muqsit\simplepackethandler\SimplePacketHandler;
use pocketmine\block\BlockFactory;
use pocketmine\event\EventPriority;
use pocketmine\event\inventory\InventoryCloseEvent;
use pocketmine\event\inventory\InventoryOpenEvent;
use pocketmine\inventory\Inventory;
use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\inventory\transaction\InventoryTransaction;
use pocketmine\inventory\transaction\TransactionException;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerIds;
use pocketmine\network\mcpe\protocol\types\inventory\NetworkInventoryAction;
use pocketmine\network\mcpe\protocol\types\inventory\NormalTransactionData;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\SingletonTrait;

use function count;
use function json_encode;

final class Loader extends PluginBase {
	use SingletonTrait;

	/** @var TransactionInventory[] */
	private array $transactionInventory = [];

	protected function onLoad(): void {
		self::setInstance($this);
	}

	protected function onEnable(): void {
		$this->getServer()->getPluginManager()->registerEvent(InventoryOpenEvent::class, function (InventoryOpenEvent $event) {
			$this->transactionInventory[$event->getPlayer()->getXuid()] = new TransactionInventory();
		}, EventPriority::MONITOR, $this);
		$this->getServer()->getPluginManager()->registerEvent(InventoryCloseEvent::class, function (InventoryCloseEvent $event) {
			unset($this->transactionInventory[$event->getPlayer()->getXuid()]);
		}, EventPriority::MONITOR, $this);

		SimplePacketHandler::createInterceptor($this)->interceptIncoming(function (InventoryTransactionPacket $packet, NetworkSession $session): bool {
			if (!isset($this->transactionInventory[$session->getPlayer()->getXuid()])) return true;

			$invManager = $session->getInvManager();
			$typeConverter = TypeConverter::getInstance();
			$actions = [];
			$isSmithingTableTransaction = false;
			$isFinalTransaction = false;
			if ($packet->trData instanceof NormalTransactionData) {
				$_actions = $packet->trData->getActions();
				foreach ($_actions as $index => $action) {
					$slot = $action->inventorySlot;
					if ($action->sourceType === NetworkInventoryAction::SOURCE_TODO && ($action->windowId === -12 || $action->windowId === -10)) {
						$isFinalTransaction = true;
						$inv = $this->transactionInventory[$session->getPlayer()->getXuid()];
					} elseif ($action->sourceType === NetworkInventoryAction::SOURCE_CONTAINER) {
						if ($action->windowId === ContainerIds::UI && ($slot === 51 || $slot === 52)) {
							$inv = $invManager->getWindow($invManager->getCurrentWindowId());
							if ($inv instanceof SmithingTableInventory) {
								$slot -= 51;
								$isSmithingTableTransaction = true;
							} else {
								return true; // throw error?
							}
						} else {
							$inv = $invManager->getWindow($action->windowId);
						}
					} else return true;
					$new = new NetworkInventoryAction();
					$new->inventorySlot = $slot;
					$new->newItem = $action->newItem;
					$new->oldItem = $action->oldItem;
					$new->sourceFlags = $action->sourceFlags;
					$new->sourceType = $action->sourceType;

					$actions[] = new SlotChangeAction($inv, $new->inventorySlot, $typeConverter->netItemStackToCore($new->oldItem->getItemStack()), $typeConverter->netItemStackToCore($new->newItem->getItemStack()));
				}
			}

			$executeTransaction = function ($actions) use ($session, $invManager): bool {
				$transaction = new InventoryTransaction($session->getPlayer(), $actions);
				$invManager->onTransactionStart($transaction);
				try {
					$transaction->execute();
					return true;
				} catch (TransactionException $e) {
					$logger = $session->getLogger();
					$logger->debug("Failed to execute inventory transaction: ".$e->getMessage());
					$logger->debug("Actions: ".json_encode($actions));

					foreach ($transaction->getInventories() as $inventory) {
						$invManager->syncContents($inventory);
					}
//					var_dump($e->getMessage());
//					var_dump($e->getTraceAsString());
					return false;
				}
			};

			if ($isSmithingTableTransaction && count($actions) > 0) {
				if ($isFinalTransaction) {
					$firstActions = []; // 기존의 제련대 템(다이아 헬멧, 네더 주괴)을 옮기는 action을 먼저 처리
					$secondActions = []; // 제련대 결과 템(네더 헬멧)을 옮기는 action을 나중에 처리
					foreach ($actions as $_ => $action) {
						$inv = $action->getInventory();
						if ($inv instanceof SmithingTableInventory || ($inv instanceof TransactionInventory && $action->getSlot() < 2)) {
							$firstActions[] = $action;
						} else {
							$secondActions[] = $action;
						}
					}
					$executeTransaction($firstActions);
//					var_dump($this->transactionInventory[$session->getPlayer()->getXuid()]->getItem(2));
					if (count($secondActions) > 0) {
						if ($executeTransaction($secondActions)) {
							$this->transactionInventory[$session->getPlayer()->getXuid()]->clearAll(); // 네더 템 빼기 성공하면, 재료템 삭제하기
						}
					} // final transaction의 첫번째 패킷은 "제련대 결과 템"을 옮기는 action이 없음
				} else {
					$executeTransaction($actions);
				}

				return false;
			}

			return true;
		});
		BlockFactory::getInstance()->register(new SmithingTable(), true);
	}
}