<?php

declare(strict_types=1);

namespace alvin0319\SmithingTable\block;

use alvin0319\SmithingTable\inventory\SmithingTableInventory;
use pocketmine\block\BlockBreakInfo;
use pocketmine\block\BlockIdentifier;
use pocketmine\block\BlockLegacyIds;
use pocketmine\block\BlockToolType;
use pocketmine\block\Opaque;
use pocketmine\item\Item;
use pocketmine\item\ToolTier;
use pocketmine\math\Vector3;
use pocketmine\player\Player;

final class SmithingTable extends Opaque{

	public function __construct(){
		parent::__construct(new BlockIdentifier(BlockLegacyIds::SMITHING_TABLE, 0), "Smithing Table", new BlockBreakInfo(2.5, BlockToolType::AXE, ToolTier::WOOD()->getHarvestLevel()));
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$player?->setCurrentWindow(new SmithingTableInventory($this->getPosition()));
		return true;
	}
}