<?php

declare(strict_types=1);

namespace alvin0319\SmithingTable\inventory;

use alvin0319\SmithingTable\Loader;
use pocketmine\inventory\SimpleInventory;
use pocketmine\item\Item;

final class TransactionInventory extends SimpleInventory{

	public function __construct(){
		parent::__construct(3);
	}

	public function getItem(int $index) : Item{
		if(
			$index === 2 &&
			($output = Loader::getInstance()->getRecipe(parent::getItem(0), parent::getItem(1))) !== null
		){
			$output->setNamedTag(parent::getItem(0)->getNamedTag());
			return $output;
		}

		return parent::getItem($index);
	}
}