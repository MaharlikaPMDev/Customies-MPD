<?php
declare(strict_types=1);

namespace customiesdevs\customies\item;

use InvalidArgumentException;
use pocketmine\block\Block;
use pocketmine\data\bedrock\item\SavedItemData;
use pocketmine\inventory\CreativeInventory;
use pocketmine\item\Item;
use pocketmine\item\ItemIdentifier;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\StringToItemParser;
use pocketmine\network\mcpe\convert\ItemTranslator;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\serializer\ItemTypeDictionary;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\network\mcpe\protocol\types\ItemComponentPacketEntry;
use pocketmine\network\mcpe\protocol\types\ItemTypeEntry;
use pocketmine\utils\SingletonTrait;
use pocketmine\utils\Utils;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use ReflectionClass;
use function array_values;

final class CustomiesItemFactory {
	use SingletonTrait;

	/** @var ItemTypeEntry[] */
	private array $itemTableEntries = [];
	/** @var ItemComponentPacketEntry[] */
	private array $itemComponentEntries = [];

	/**
	 * Get a custom item from its identifier. An exception will be thrown if the item is not registered.
	 */
	public function get(string $identifier, int $amount = 1): Item {
		$item = StringToItemParser::getInstance()->parse($identifier);

		if($item === null) {
			throw new InvalidArgumentException("Custom item " . $identifier . " is not registered");
		}
		return $item->setCount($amount);
	}

	/**
	 * Returns the item properties CompoundTag which maps out all custom item properties.
	 * @return ItemComponentPacketEntry[]
	 */
	public function getItemComponentEntries(): array {
		return $this->itemComponentEntries;
	}

	/**
	 * Returns custom item entries for the StartGamePacket itemTable property.
	 * @return ItemTypeEntry[]
	 */
	public function getItemTableEntries(): array {
		return array_values($this->itemTableEntries);
	}
	
	private function registerGlobally(Item $item, string $identifier, int $itemId) : void {
		$this->registerCustomItemMapping($identifier, $itemId);

		GlobalItemDataHandlers::getDeserializer()->map($identifier, fn() => clone $item);
		GlobalItemDataHandlers::getSerializer()->map($item, fn() => new SavedItemData($identifier));

		StringToItemParser::getInstance()->register($identifier, fn() => clone $item);

		if(($componentBased = $item instanceof ItemComponents)) {
			$this->itemComponentEntries[$identifier] = new ItemComponentPacketEntry($identifier,
				new CacheableNbt($item->getComponents()
					->setInt("id", $itemId)
					->setString("name", $identifier)
				)
			);
		}

		$this->itemTableEntries[$identifier] = new ItemTypeEntry($identifier, $itemId, $componentBased);
		CreativeInventory::getInstance()->add($item);
	}

	public function registerItemFromYAML(string $className, array $component): void {
		Utils::testValidInstance($className, Item::class);

		$identifier = $component["id"];

		$itemId = ItemTypeIds::newId();
		$item = new $className(new ItemIdentifier($itemId), $component);
		$this->registerGlobally($item, $identifier, $itemId);
	}

	public function registerItem(string $className, string $identifier, string $name): void {
		if($className !== Item::class) {
			Utils::testValidInstance($className, Item::class);
		}

		$itemId = ItemTypeIds::newId();
		$item = new $className(new ItemIdentifier($itemId), $name);
		$this->registerGlobally($item, $identifier, $itemId);
	}

	/**
	 * Registers a custom item ID to the required mappings in the global ItemTypeDictionary instance.
	 */
	private function registerCustomItemMapping(string $stringId, int $id): void {
		if(method_exists(ItemTranslator::class, "getAll")){
			foreach(ItemTranslator::getAll(true) as $itemTranslator){
				$this->registerCustomItemMappingToDictionary($itemTranslator->getDictionary(), $stringId, $id);
			}
		}else{
			$this->registerCustomItemMappingToDictionary(TypeConverter::getInstance()->getItemTypeDictionary(), $stringId, $id);
		}
	}

	private function registerCustomItemMappingToDictionary(ItemTypeDictionary $dictionary, string $stringId, int $id): void {
		$reflection = new ReflectionClass($dictionary);

		$reflectionProperty = $reflection->getProperty("intToStringIdMap");
		/** @var string[] $value */
		$value = $reflectionProperty->getValue($dictionary);
		$reflectionProperty->setValue($dictionary, $value + [$id => $stringId]);

		$reflectionProperty = $reflection->getProperty("stringToIntMap");
		/** @var int[] $value */
		$value = $reflectionProperty->getValue($dictionary);
		$reflectionProperty->setValue($dictionary, $value + [$stringId => $id]);
	}

	/**
	 * Registers the required mappings for the block to become an item that can be placed etc. It is assigned an ID that
	 * correlates to its block ID.
	 */
	public function registerBlockItem(string $identifier, Block $block): void {
		$itemId = $block->getIdInfo()->getBlockTypeId();
		$this->registerCustomItemMapping($identifier, $itemId);

		StringToItemParser::getInstance()->registerBlock($identifier, fn() => clone $block);

		$this->itemTableEntries[] = new ItemTypeEntry($identifier, $itemId, false);
	}
}
