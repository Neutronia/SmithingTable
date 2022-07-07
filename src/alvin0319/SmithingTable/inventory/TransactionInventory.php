<?php

declare(strict_types=1);

namespace alvin0319\SmithingTable\inventory;

use pocketmine\block\inventory\BlockInventory;
use pocketmine\inventory\SimpleInventory;
use pocketmine\item\Item;
use pocketmine\item\ItemFactory;
use pocketmine\item\VanillaItems;
use pocketmine\network\mcpe\protocol\ContainerClosePacket;
use pocketmine\network\mcpe\protocol\ContainerOpenPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\inventory\WindowTypes;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\world\Position;

final class TransactionInventory extends SimpleInventory {

	public function __construct() {
		parent::__construct(3);
	}

	public function getItem(int $index): Item {
		if (
				$index === 2 &&
				$this->getItem(0)->equals(VanillaItems::DIAMOND_HELMET()) &&
				$this->getItem(1)->equals(ItemFactory::getInstance()->get(742))
		) {
			return ItemFactory::getInstance()->get(748);
		}

		return parent::getItem($index);
	}
}