<?php

declare(strict_types=1);

namespace alvin0319\SmithingTable;

use alvin0319\SmithingTable\block\SmithingTable;
use alvin0319\SmithingTable\inventory\SmithingTableInventory;
use muqsit\simplepackethandler\SimplePacketHandler;
use pocketmine\block\BlockFactory;
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

final class Loader extends PluginBase{
	use SingletonTrait;

	protected function onLoad() : void{
		self::setInstance($this);
	}

	protected function onEnable() : void{
		SimplePacketHandler::createInterceptor($this)->interceptIncoming(function(InventoryTransactionPacket $packet, NetworkSession $session) : bool{
			$invManager = $session->getInvManager();
			$typeConverter = TypeConverter::getInstance();
			$actions = [];
			$isSmithingTableTransaction = false;
			$isFinalTransaction = false;
			if($packet->trData instanceof NormalTransactionData){
				$_actions = $packet->trData->getActions();
				foreach($_actions as $index => $action){
					$slot = $action->inventorySlot;
					if ($action->sourceType === NetworkInventoryAction::SOURCE_TODO) {
						$isFinalTransaction = true;
					}
					if ($action->sourceType !== NetworkInventoryAction::SOURCE_CONTAINER) continue;
					if ($action->windowId === ContainerIds::UI) {
						if(($slot === 51 || $slot === 52)){
							$inv = $invManager->getWindow($invManager->getCurrentWindowId());
							if ($inv instanceof SmithingTableInventory){
								$slot -= 51;
								$isSmithingTableTransaction = true;
							}
						} else {
							$inv = $invManager->getWindow($action->windowId);
						}
					} else {
						$inv = $invManager->getWindow($action->windowId);
					}
					if(!$inv instanceof Inventory){
						continue;
					}
					$new = new NetworkInventoryAction();
					$new->inventorySlot = $slot;
					$new->newItem = $action->newItem;
					$new->oldItem = $action->oldItem;
					$new->sourceFlags = $action->sourceFlags;
					$new->sourceType = $action->sourceType;

					$actions[] = new SlotChangeAction($inv, $new->inventorySlot, $typeConverter->netItemStackToCore($new->oldItem->getItemStack()), $typeConverter->netItemStackToCore($new->newItem->getItemStack()));
				}
			}
//			var_dump($packet->trData->getActions());
			if($isSmithingTableTransaction && count($actions) > 0){
				if ($isFinalTransaction) { // 결과물 빼는 트랜잭션

				} else { // 재료 넣고 빼는 트랜잭션
					$transaction = new InventoryTransaction($session->getPlayer(), $actions);
					$invManager->onTransactionStart($transaction);
					try{
						$transaction->execute();
					}catch(TransactionException $e){
						$logger = $session->getLogger();
						$logger->debug("Failed to execute inventory transaction: " . $e->getMessage());
						$logger->debug("Actions: " . json_encode($actions));

						foreach($transaction->getInventories() as $inventory){
							$invManager->syncContents($inventory);
						}
					}
				}
				return false;
			}
			return true;
		});
		BlockFactory::getInstance()->register(new SmithingTable(), true);
	}
}