<?php

declare(strict_types=1);

namespace alvin0319\SmithingTable\inventory;

use pocketmine\block\inventory\BlockInventory;
use pocketmine\inventory\SimpleInventory;
use pocketmine\network\mcpe\protocol\ContainerClosePacket;
use pocketmine\network\mcpe\protocol\ContainerOpenPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\inventory\WindowTypes;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\world\Position;

final class SmithingTableInventory extends SimpleInventory implements BlockInventory{

	public function __construct(private Position $holder){
		parent::__construct(2);
	}

	public function getHolder() : Position{
		return $this->holder;
	}

	public function onOpen(Player $who) : void{
		parent::onOpen($who);

		$who->getNetworkSession()->sendDataPacket(ContainerOpenPacket::blockInv(
			$who->getNetworkSession()->getInvManager()->getCurrentWindowId(),
			WindowTypes::SMITHING_TABLE,
			BlockPosition::fromVector3($this->getHolder())
		));
	}

	public function onClose(Player $who) : void{
		parent::onClose($who);

		for($i = 0; $i < 2; $i++) {
			if(!$this->isSlotEmpty($i)) {
				if($who->getInventory()->canAddItem($this->getItem($i))) {
					$who->getInventory()->addItem($this->getItem($i));
				}else{
					$this->getHolder()->getWorld()->dropItem($who->getPosition(), $this->getItem($i));
				}
			}
		}
		$this->clearAll();

		$who->getNetworkSession()->sendDataPacket(ContainerClosePacket::create(
			$who->getNetworkSession()->getInvManager()->getCurrentWindowId(),
			true
		));
	}
}