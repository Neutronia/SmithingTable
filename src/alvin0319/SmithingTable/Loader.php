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
use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\inventory\transaction\InventoryTransaction;
use pocketmine\inventory\transaction\TransactionException;
use pocketmine\item\Item;
use pocketmine\item\ItemFactory;
use pocketmine\item\VanillaItems;
use pocketmine\network\mcpe\cache\CraftingDataCache;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\CraftingDataPacket;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerIds;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStack;
use pocketmine\network\mcpe\protocol\types\inventory\NetworkInventoryAction;
use pocketmine\network\mcpe\protocol\types\inventory\NormalTransactionData;
use pocketmine\network\mcpe\protocol\types\recipe\RecipeIngredient;
use pocketmine\network\mcpe\protocol\types\recipe\ShapelessRecipe as ProtocolShapelessRecipe;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Binary;
use pocketmine\utils\SingletonTrait;
use Ramsey\Uuid\Uuid;
use function array_map;
use function count;
use function json_encode;

final class Loader extends PluginBase{
	use SingletonTrait;

	/** @var TransactionInventory[] */
	private array $transactionInventory = [];

	/** @var Item[] */
	private array $smithingRecipes = [];

	protected function onLoad() : void{
		self::setInstance($this);
	}

	protected function onEnable() : void{
		$this->getServer()->getPluginManager()->registerEvent(InventoryOpenEvent::class, function(InventoryOpenEvent $event){
			$this->transactionInventory[$event->getPlayer()->getXuid()] = new TransactionInventory();
		}, EventPriority::MONITOR, $this);
		$this->getServer()->getPluginManager()->registerEvent(InventoryCloseEvent::class, function(InventoryCloseEvent $event){
			unset($this->transactionInventory[$event->getPlayer()->getXuid()]);
		}, EventPriority::MONITOR, $this);

		$netheriteIngot = ItemFactory::getInstance()->get(742);

		$this->registerRecipe(ItemFactory::getInstance()->get(748), VanillaItems::DIAMOND_HELMET(), $netheriteIngot);
		$this->registerRecipe(ItemFactory::getInstance()->get(749), VanillaItems::DIAMOND_CHESTPLATE(), $netheriteIngot);
		$this->registerRecipe(ItemFactory::getInstance()->get(750), VanillaItems::DIAMOND_LEGGINGS(), $netheriteIngot);
		$this->registerRecipe(ItemFactory::getInstance()->get(751), VanillaItems::DIAMOND_BOOTS(), $netheriteIngot);
		$this->registerRecipe(ItemFactory::getInstance()->get(743), VanillaItems::DIAMOND_SWORD(), $netheriteIngot);
		$this->registerRecipe(ItemFactory::getInstance()->get(744), VanillaItems::DIAMOND_SHOVEL(), $netheriteIngot);
		$this->registerRecipe(ItemFactory::getInstance()->get(745), VanillaItems::DIAMOND_PICKAXE(), $netheriteIngot);
		$this->registerRecipe(ItemFactory::getInstance()->get(746), VanillaItems::DIAMOND_AXE(), $netheriteIngot);
		$this->registerRecipe(ItemFactory::getInstance()->get(747), VanillaItems::DIAMOND_HOE(), $netheriteIngot);

		SimplePacketHandler::createInterceptor($this)->interceptIncoming(function(InventoryTransactionPacket $packet, NetworkSession $session) : bool{
			if(!isset($this->transactionInventory[$session->getPlayer()->getXuid()])) return true;

			$invManager = $session->getInvManager();
			$typeConverter = TypeConverter::getInstance();
			$actions = [];
			$isSmithingTableTransaction = false;
			$isFinalTransaction = false;
			if($packet->trData instanceof NormalTransactionData){
				$_actions = $packet->trData->getActions();
				foreach($_actions as $index => $action){
					$slot = $action->inventorySlot;
					if($action->sourceType === NetworkInventoryAction::SOURCE_TODO && ($action->windowId === -12 || $action->windowId === -10)){
						$isFinalTransaction = true;
						$inv = $this->transactionInventory[$session->getPlayer()->getXuid()];
					}elseif($action->sourceType === NetworkInventoryAction::SOURCE_CONTAINER){
						if($action->windowId === ContainerIds::UI && ($slot === 51 || $slot === 52)){
							$inv = $invManager->getWindow($invManager->getCurrentWindowId());
							if($inv instanceof SmithingTableInventory){
								$slot -= 51;
								$isSmithingTableTransaction = true;
							}else{
								return true; // throw error?
							}
						}else{
							$inv = $invManager->getWindow($action->windowId);
						}
					}else return true;
					$new = new NetworkInventoryAction();
					$new->inventorySlot = $slot;
					$new->newItem = $action->newItem;
					$new->oldItem = $action->oldItem;
					$new->sourceFlags = $action->sourceFlags;
					$new->sourceType = $action->sourceType;

					$actions[] = new SlotChangeAction($inv, $new->inventorySlot, $typeConverter->netItemStackToCore($new->oldItem->getItemStack()), $typeConverter->netItemStackToCore($new->newItem->getItemStack()));
				}
			}

			$executeTransaction = function($actions) use ($session, $invManager) : bool{
				$transaction = new InventoryTransaction($session->getPlayer(), $actions);
				$invManager->onTransactionStart($transaction);
				try{
					$transaction->execute();
					return true;
				}catch(TransactionException $e){
					$logger = $session->getLogger();
					$logger->debug("Failed to execute inventory transaction: " . $e->getMessage());
					$logger->debug("Actions: " . json_encode($actions));
					foreach($transaction->getInventories() as $inventory){
						$invManager->syncContents($inventory);
					}
					return false;
				}
			};

			if($isSmithingTableTransaction && count($actions) > 0){
				if($isFinalTransaction){
					$isFailed = false;
					$firstActions = []; // 기존의 제련대 템(다이아 헬멧, 네더 주괴)을 옮기는 action을 먼저 처리
					$secondActions = []; // 제련대 결과 템(네더 헬멧)을 옮기는 action을 나중에 처리
					$tempInventory = $this->transactionInventory[$session->getPlayer()->getXuid()];
					foreach($actions as $_ => $action){
						$inv = $action->getInventory();
						if($inv instanceof SmithingTableInventory || ($inv instanceof TransactionInventory && $action->getSlot() < 2)){
							$firstActions[] = $action;
						}else{
							$secondActions[] = $action;
						}
					}
					if(!$executeTransaction($firstActions)){
						$isFailed = true;
					}
					if(count($secondActions) > 0){ // final transaction의 첫번째 패킷은 "제련대 결과 템"을 옮기는 action이 없음
						if($executeTransaction($secondActions)){
							$tempInventory->clearAll(); // 네더 템 빼기 성공하면, 재료템 삭제하기
						}else{
							$isFailed = true;
						}
					}
					if($isFailed){
						/** @var SmithingTableInventory $inv */
						$inv = $invManager->getWindow($invManager->getCurrentWindowId());
						for($i = 0; $i < 2; $i++){
							$inv->addItem($tempInventory->getItem($i));
						}
						$tempInventory->clearAll();
						$invManager->syncContents($inv); // is it work for SmithingTableInventory?
					}
				}else{
					$executeTransaction($actions);
				}

				return false;
			}

			return true;
		});
		BlockFactory::getInstance()->register(new SmithingTable(), true);
	}

	private function registerRecipe(Item $result, Item $input1, Item $input2) : void{
		$result->setCount(1);
		$input1->setCount(1);
		$input2->setCount(1);
		$packet = CraftingDataCache::getInstance()->getCache($this->getServer()->getCraftingManager());
		static $counter = 0;
		if($counter === 0){
			$counter = count($packet->recipesWithTypeIds);
		}
		$nullUUID = Uuid::fromString(Uuid::NIL);
		$converter = TypeConverter::getInstance();
		$packet->recipesWithTypeIds[] = new ProtocolShapelessRecipe(
			CraftingDataPacket::ENTRY_SHAPELESS,
			Binary::writeInt(++$counter),
			array_map(function(Item $item) use ($converter) : RecipeIngredient{
				return $converter->coreItemStackToRecipeIngredient($item);
			}, [$input1, $input2]),
			array_map(function(Item $item) use ($converter) : ItemStack{
				return $converter->coreItemStackToNet($item);
			}, [$result]),
			$nullUUID,
			"smithing_table",
			0,
			$counter
		);
		$this->smithingRecipes[self::itemHash($input1) . self::itemHash($input2)] = clone $result;
	}

	public function getRecipe(Item $input1, Item $input2) : ?Item{
		if(isset($this->smithingRecipes[$hash = self::itemHash($input1) . self::itemHash($input2)])){
			return clone $this->smithingRecipes[$hash];
		}
		return null;
	}

	private static function itemHash(Item $item) : string{
		return $item->getId() . ":" . $item->getMeta();
	}
}